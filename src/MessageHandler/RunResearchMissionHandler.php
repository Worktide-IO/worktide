<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Enum\LeadActivityChannel;
use App\Entity\Enum\LeadActivityType;
use App\Entity\Enum\LeadSource;
use App\Entity\Enum\LeadStage;
use App\Entity\Enum\ResearchMissionStatus;
use App\Entity\Lead;
use App\Entity\LeadActivity;
use App\Entity\ResearchMission;
use App\Entity\Workspace;
use App\Message\RunResearchMissionMessage;
use App\Repository\CustomerRepository;
use App\Repository\LeadRepository;
use App\Service\Ai\ResearchAssistant;
use App\Service\ExternalSearch\ExternalSearchQuery;
use App\Service\ExternalSearch\ExternalSearchRegistry;
use App\Service\ExternalSearch\ExternalSearchResult;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs one discovery pass for a {@see ResearchMission} on the `ai_agents`
 * transport: query the external-search registry, score hits into leads via
 * {@see ResearchAssistant}, dedupe against existing leads + customers, and
 * persist the new {@see Lead} rows (each with a "discovered" {@see LeadActivity}).
 * Idempotent-ish: dedupe means a re-run only adds genuinely new leads, and the
 * mission `state` records the last pass so progress is resumable.
 */
#[AsMessageHandler]
final class RunResearchMissionHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExternalSearchRegistry $search,
        private readonly ResearchAssistant $assistant,
        private readonly LeadRepository $leads,
        private readonly CustomerRepository $customers,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RunResearchMissionMessage $message): void
    {
        $missionId = $message->getMissionId();
        $mission = $this->em->find(ResearchMission::class, $missionId);
        if ($mission === null) {
            throw new UnrecoverableMessageHandlingException(sprintf('ResearchMission %s no longer exists; dropping run.', $missionId->toRfc4122()));
        }
        $workspace = $mission->getWorkspace();

        $mission->setStatus(ResearchMissionStatus::Running);
        $this->em->flush();

        $brief = $mission->getBrief() ?? [];
        $query = new ExternalSearchQuery(
            query: trim((string) ($brief['query'] ?? '')) ?: $mission->getPrompt(),
            limit: (int) ($brief['limit'] ?? 25),
            filters: array_filter([
                'tech' => $this->hint($brief, 'tech'),
                'region' => $this->hint($brief, 'region'),
                'industry' => $this->hint($brief, 'industry'),
            ], static fn (?string $v): bool => $v !== null && $v !== ''),
        );

        $results = $this->search->searchAll($query);
        $extracted = $this->assistant->extractLeads($mission, $results);

        /** @var array<string, ExternalSearchResult> $byUrl */
        $byUrl = [];
        foreach ($results as $r) {
            if ($r->url !== null) {
                $byUrl[$r->url] = $r;
            }
        }

        $created = 0;
        foreach ($extracted['leads'] as $l) {
            $dedupeKey = $this->dedupeKey($l);
            if ($dedupeKey !== null && $this->exists($workspace, $dedupeKey)) {
                continue;
            }
            $srcUrl = \is_string($l['sourceUrl'] ?? null) ? $l['sourceUrl'] : null;
            $srcResult = $srcUrl !== null ? ($byUrl[$srcUrl] ?? null) : null;

            $lead = (new Lead())
                ->setWorkspace($workspace)
                ->setMission($mission)
                ->setName((string) $l['name'])
                ->setIsCompany((bool) $l['isCompany'])
                ->setEmail($l['email'] ?? null)
                ->setWebsite($l['website'] ?? null)
                ->setRole($l['role'] ?? null)
                ->setIndustry($l['industry'] ?? null)
                ->setRegion($l['region'] ?? null)
                ->setFitScore($l['fitScore'] ?? null)
                ->setScoreReason($l['scoreReason'] ?? null)
                ->setSource($srcResult?->source ?? LeadSource::WebSearch)
                ->setSourceUrl($srcUrl)
                ->setSourceDetail(['provider' => $srcResult?->provider ?? 'llm'])
                ->setStage(LeadStage::Discovered)
                ->setDedupeKey($dedupeKey);
            $this->em->persist($lead);
            $this->em->persist(
                (new LeadActivity())
                    ->setLead($lead)
                    ->setWorkspace($workspace)
                    ->setType(LeadActivityType::Discovered)
                    ->setChannel(LeadActivityChannel::Web)
                    ->setPayload(['provider' => $srcResult?->provider, 'url' => $srcUrl]),
            );
            ++$created;
        }

        $mission->setFoundCount($mission->getFoundCount() + $created);
        $mission->setState([
            'lastQuery' => $query->query,
            'resultCount' => \count($results),
            'lastCreated' => $created,
            'providers' => array_map(static fn ($p) => $p->getName(), $this->search->configured()),
        ]);
        $target = $mission->getTargetCount();
        $mission->setStatus(
            $target !== null && $mission->getFoundCount() < $target && $results !== []
                ? ResearchMissionStatus::Ready   // more to find — can run again
                : ResearchMissionStatus::Completed,
        );
        $mission->setSummary(sprintf('%d neue Leads aus %d Treffern.', $created, \count($results)));
        $this->em->flush();

        $this->publish($mission);
        $this->logger->info('Research mission run complete.', [
            'missionId' => $missionId->toRfc4122(),
            'created' => $created,
            'results' => \count($results),
        ]);
    }

    /**
     * @param array<string, mixed> $brief
     */
    private function hint(array $brief, string $key): ?string
    {
        return \is_string($brief[$key] ?? null) ? trim($brief[$key]) : null;
    }

    /**
     * @param array<string, mixed> $lead
     */
    private function dedupeKey(array $lead): ?string
    {
        $email = \is_string($lead['email'] ?? null) ? strtolower(trim($lead['email'])) : '';
        if ($email !== '') {
            return $email;
        }
        $url = $lead['website'] ?? $lead['sourceUrl'] ?? null;
        if (\is_string($url) && $url !== '') {
            $host = parse_url($url, \PHP_URL_HOST) ?: $url;

            return strtolower(preg_replace('/^www\./', '', $host) ?? $host);
        }

        return null;
    }

    private function exists(Workspace $workspace, string $dedupeKey): bool
    {
        if ($this->leads->findOneBy(['workspace' => $workspace, 'dedupeKey' => $dedupeKey]) !== null) {
            return true;
        }
        // Only email keys can match a Customer directly.
        if (str_contains($dedupeKey, '@') && $this->customers->findOneBy(['workspace' => $workspace, 'email' => $dedupeKey]) !== null) {
            return true;
        }

        return false;
    }

    private function publish(ResearchMission $mission): void
    {
        $wsId = $mission->getWorkspace()->getId()?->toRfc4122();
        if ($wsId === null) {
            return;
        }
        try {
            $this->hub->publish(new Update(
                topics: ['worktide:workspace:' . $wsId . ':research'],
                data: json_encode([
                    'missionId' => $mission->getId()?->toRfc4122(),
                    'status' => $mission->getStatus()->value,
                    'foundCount' => $mission->getFoundCount(),
                ]) ?: '{}',
                private: true,
            ));
        } catch (\Throwable $e) {
            $this->logger->debug('Mercure publish failed for research mission', ['error' => $e->getMessage()]);
        }
    }
}
