<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CustomerSystem;
use App\Entity\Enum\IncidentKind;
use App\Entity\SystemIncident;
use App\Entity\SystemUptimeDay;
use App\Repository\CustomerSystemRepository;
use App\Repository\SystemIncidentRepository;
use App\Repository\SystemUptimeDayRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Probes every active {@see CustomerSystem} with a URL and records the result
 * into today's {@see SystemUptimeDay} rollup, auto-opening/closing Outage and
 * Degraded {@see SystemIncident}s. This is the "monitoring pipeline" that feeds
 * the portal Monitoring screen — run it on a schedule (cron / Symfony Scheduler),
 * e.g. every few minutes. Reachable + fast → up; reachable but slow → degraded;
 * unreachable / error status → outage.
 */
#[AsCommand(name: 'app:monitoring:probe', description: 'Probe customer systems and record uptime/latency + incidents.')]
final class MonitoringProbeCommand extends Command
{
    private const SLOW_MS = 2000;    // above this = degraded
    private const TIMEOUT_S = 8.0;

    public function __construct(
        private readonly CustomerSystemRepository $systems,
        private readonly SystemUptimeDayRepository $uptimeDays,
        private readonly SystemIncidentRepository $incidents,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $day = $now->setTime(0, 0);

        $systems = $this->systems->findBy(['isActive' => true]);
        $probed = 0;

        foreach ($systems as $system) {
            $url = $system->getUrl();
            if ($url === null || $url === '') {
                continue;
            }

            [$outcome, $ms] = $this->check($url);
            $this->recordSample($system, $day, $outcome, $ms);
            $this->reconcileIncidents($system, $outcome, $now);
            $io->writeln(sprintf('%-28s %s %sms', $system->getName(), $outcome, $ms ?? '—'));
            $probed++;
        }

        $this->em->flush();
        $io->success(sprintf('Probed %d system(s).', $probed));

        return Command::SUCCESS;
    }

    /** @return array{0: 'up'|'degraded'|'down', 1: int|null} */
    private function check(string $url): array
    {
        $start = microtime(true);
        try {
            $status = $this->http->request('GET', $url, [
                'timeout' => self::TIMEOUT_S,
                'max_redirects' => 3,
            ])->getStatusCode();
            $ms = (int) round((microtime(true) - $start) * 1000);
            if ($status >= 400) {
                return ['down', $ms];
            }
            return [$ms > self::SLOW_MS ? 'degraded' : 'up', $ms];
        } catch (\Throwable) {
            return ['down', null];
        }
    }

    private function recordSample(CustomerSystem $system, \DateTimeImmutable $day, string $outcome, ?int $ms): void
    {
        $row = $this->uptimeDays->findOneForDay($system, $day)
            ?? (new SystemUptimeDay())->setSystem($system)->setDay($day)->setUptimePct(0.0);

        $count = $row->getSampleCount();
        $successes = (int) round($row->getUptimePct() / 100 * $count) + ($outcome === 'down' ? 0 : 1);
        $newCount = $count + 1;
        $row->setSampleCount($newCount)->setUptimePct($successes / $newCount * 100);

        if ($ms !== null) {
            $prev = $row->getAvgResponseMs();
            $row->setAvgResponseMs($prev === null ? $ms : (int) round(($prev * $count + $ms) / $newCount));
        }

        $this->em->persist($row);
    }

    private function reconcileIncidents(CustomerSystem $system, string $outcome, \DateTimeImmutable $now): void
    {
        $outage = $this->incidents->findOpenOfKind($system, IncidentKind::Outage);
        $degraded = $this->incidents->findOpenOfKind($system, IncidentKind::Degraded);

        if ($outcome === 'down') {
            $degraded?->setResolvedAt($now);
            if ($outage === null) {
                $this->open($system, IncidentKind::Outage, 'System nicht erreichbar', $now);
            }
        } elseif ($outcome === 'degraded') {
            $outage?->setResolvedAt($now);
            if ($degraded === null) {
                $this->open($system, IncidentKind::Degraded, 'Erhöhte Latenz', $now);
            }
        } else { // up → recovered
            $outage?->setResolvedAt($now);
            $degraded?->setResolvedAt($now);
        }
    }

    private function open(CustomerSystem $system, IncidentKind $kind, string $title, \DateTimeImmutable $now): void
    {
        $this->em->persist(
            (new SystemIncident())->setSystem($system)->setKind($kind)->setTitle($title)->setStartedAt($now),
        );
    }
}
