<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Enum\IdeaOrigin;
use App\Entity\Enum\IdeaStatus;
use App\Entity\Idea;
use App\Entity\IdeaVote;
use App\Entity\User;
use App\Repository\IdeaRepository;
use App\Repository\IdeaVoteRepository;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Customer-portal "Ideen fürs Business" — the idea board with upvoting
 * (wireframe screen 5, bottom half). Customers view ideas (from themselves,
 * the agency, or AI), submit new ones, and upvote. Gated by `ideas`.
 *
 * Voting is idempotent (one {@see IdeaVote} per user per idea, enforced by a
 * unique constraint) and keeps {@see Idea::$voteCount} in sync.
 */
final class PortalIdeasController
{


    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly IdeaRepository $ideas,
        private readonly IdeaVoteRepository $votes,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route(
        path: '/v1/portal/ideas',
        name: 'api_portal_ideas_list',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('ideas');

        $ideas = $this->ideas->findForPortalCustomer($this->portal->customer());
        $votedIds = $this->votedIdeaIds($ideas);

        return new JsonResponse([
            'ideas' => array_map(
                fn (Idea $i): array => $this->ideaDto($i, \in_array($i->getId()?->toRfc4122(), $votedIds, true)),
                $ideas,
            ),
        ]);
    }

    #[Route(
        path: '/v1/portal/ideas',
        name: 'api_portal_ideas_create',
        methods: ['POST'],
    )]
    public function create(Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('ideas');

        $body = $this->body($request);
        $title = \is_string($body['title'] ?? null) ? trim($body['title']) : '';
        if ($title === '') {
            throw new BadRequestHttpException('title required.');
        }

        $idea = (new Idea())
            ->setCustomer($this->portal->customer())
            ->setTitle($title)
            ->setDescription(\is_string($body['description'] ?? null) ? $body['description'] : null)
            ->setOrigin(IdeaOrigin::Customer)
            ->setStatus(IdeaStatus::Proposed)
            ->setSubmittedByContact($this->portal->contact());

        $this->em->persist($idea);
        $this->em->flush();

        return new JsonResponse($this->ideaDto($idea, false), 201);
    }

    #[Route(
        path: '/v1/portal/ideas/{id}/vote',
        name: 'api_portal_ideas_vote',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function vote(string $id): JsonResponse
    {
        $this->portal->assertFeatureEnabled('ideas');

        $idea = $this->findIdeaOr404($id);
        $voter = $this->portalUser();

        if ($this->votes->findOneBy(['idea' => $idea, 'voter' => $voter]) === null) {
            $vote = (new IdeaVote())->setIdea($idea)->setVoter($voter);
            $this->em->persist($vote);
            $idea->setVoteCount($idea->getVoteCount() + 1);
            $this->em->flush();
        }

        return new JsonResponse(['id' => $id, 'voteCount' => $idea->getVoteCount(), 'hasVoted' => true]);
    }

    #[Route(
        path: '/v1/portal/ideas/{id}/vote',
        name: 'api_portal_ideas_unvote',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['DELETE'],
    )]
    public function unvote(string $id): JsonResponse
    {
        $this->portal->assertFeatureEnabled('ideas');

        $idea = $this->findIdeaOr404($id);
        $voter = $this->portalUser();

        $existing = $this->votes->findOneBy(['idea' => $idea, 'voter' => $voter]);
        if ($existing !== null) {
            $this->em->remove($existing);
            $idea->setVoteCount($idea->getVoteCount() - 1);
            $this->em->flush();
        }

        return new JsonResponse(['id' => $id, 'voteCount' => $idea->getVoteCount(), 'hasVoted' => false]);
    }

    /**
     * Ideas the current user has voted on, from the given set (one query).
     *
     * @param list<Idea> $ideas
     * @return list<string> RFC-4122 idea ids
     */
    private function votedIdeaIds(array $ideas): array
    {
        if ($ideas === []) {
            return [];
        }
        $votes = $this->votes->findBy(['voter' => $this->portalUser(), 'idea' => $ideas]);

        return array_map(static fn (IdeaVote $v): string => $v->getIdea()->getId()?->toRfc4122() ?? '', $votes);
    }

    private function findIdeaOr404(string $id): Idea
    {
        $idea = $this->ideas->find(Uuid::fromString($id));
        if (
            !$idea instanceof Idea
            || $idea->getDeletedAt() !== null
            || $idea->getCustomer()->getId()?->toRfc4122() !== $this->portal->customer()->getId()?->toRfc4122()
        ) {
            throw new NotFoundHttpException('Idea not found.');
        }
        return $idea;
    }

    private function portalUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function ideaDto(Idea $idea, bool $hasVoted): array
    {
        $status = $idea->getStatus()->value;
        $origin = $idea->getOrigin()->value;
        $contact = $idea->getSubmittedByContact();

        return [
            'id' => $idea->getId()?->toRfc4122(),
            'title' => $idea->getTitle(),
            'description' => $idea->getDescription(),
            'status' => $status,
            'statusLabel' => $this->translator->trans('label.idea_status.' . $status),
            'origin' => $origin,
            'originLabel' => $this->translator->trans('label.idea_origin.' . $origin),
            'submittedBy' => $contact !== null ? trim($contact->getFirstName() . ' ' . $contact->getLastName()) : null,
            'voteCount' => $idea->getVoteCount(),
            'hasVoted' => $hasVoted,
            'createdAt' => $idea->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Body must be valid JSON.');
        }
        return \is_array($decoded) ? $decoded : [];
    }
}
