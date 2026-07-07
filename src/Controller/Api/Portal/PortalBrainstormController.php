<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\BrainstormNote;
use App\Entity\Enum\IdeaOrigin;
use App\Repository\BrainstormNoteRepository;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer-portal "Brainstorming" board (wireframe screen 5) — a shared, free-
 * form note stream between the customer and the agency, alongside Ziele & Ideen.
 *
 * Notes are scoped per {@see \App\Entity\Customer} and read-appended: the portal
 * user can list the board and add a contribution (always origin=customer);
 * agency/AI notes are authored staff-side. Gated by the `ideas` feature.
 *
 * AI moderation/summary (wireframe "🤖 KI moderiert") is a later addition — this
 * ships the collaborative board itself; it renders AI notes if any exist.
 */
final class PortalBrainstormController
{
    private const ORIGIN_LABELS = [
        'customer' => 'Sie',
        'agency' => 'Agentur',
        'ai' => 'KI',
    ];

    private const MAX_LENGTH = 2000;

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly BrainstormNoteRepository $notes,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/portal/brainstorm',
        name: 'api_portal_brainstorm_list',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('ideas');

        $notes = $this->notes->findForPortalCustomer($this->portal->customer());

        return new JsonResponse([
            'notes' => array_map($this->noteDto(...), $notes),
        ]);
    }

    #[Route(
        path: '/v1/portal/brainstorm',
        name: 'api_portal_brainstorm_create',
        methods: ['POST'],
    )]
    public function create(Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('ideas');

        $body = $this->body($request);
        $text = \is_string($body['body'] ?? null) ? trim($body['body']) : '';
        if ($text === '') {
            throw new BadRequestHttpException('body required.');
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH);
        }

        $contact = $this->portal->contact();

        $note = (new BrainstormNote())
            ->setCustomer($this->portal->customer())
            ->setBody($text)
            ->setOrigin(IdeaOrigin::Customer)
            ->setAuthorContact($contact)
            ->setAuthorName($contact->getFullName());

        $this->em->persist($note);
        $this->em->flush();

        return new JsonResponse($this->noteDto($note), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function noteDto(BrainstormNote $note): array
    {
        $origin = $note->getOrigin()->value;
        $selfContactId = $this->portal->contact()->getId()?->toRfc4122();

        return [
            'id' => $note->getId()?->toRfc4122(),
            'body' => $note->getBody(),
            'authorName' => $note->getAuthorName(),
            'origin' => $origin,
            'originLabel' => self::ORIGIN_LABELS[$origin] ?? $origin,
            'isMine' => $note->getAuthorContact()?->getId()?->toRfc4122() === $selfContactId
                && $selfContactId !== null,
            'createdAt' => $note->getCreatedAt()?->format(\DateTimeInterface::ATOM),
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
