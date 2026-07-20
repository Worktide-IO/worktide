<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\ConversationNote;
use App\Entity\Enum\ConversationStatus;
use App\Entity\Enum\TagScope;
use App\Entity\Tag;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The "action back into Worktide" side of the automation integration: an
 * external flow engine (self-hosted n8n) calls this to act on a Conversation it
 * was notified about — set status, apply tags, drop an internal note.
 *
 * This is the INBOUND counterpart to {@see \App\MessageHandler\DispatchAutomationEventHandler}
 * (Worktide → n8n). Auth is a single shared token in `AUTOMATION_API_TOKEN`,
 * sent as `X-Worktide-Automation-Token` (constant-time compared). No JWT / user
 * session — the path is PUBLIC_ACCESS in security.yaml and the token IS the
 * credential, mirroring the inbound-webhook endpoints. Empty token in config →
 * the endpoint is disabled (404), so a fresh install can't be poked.
 *
 *   POST /v1/automation/conversations/{id}/apply
 *   { "status": "closed", "tags": ["billing"], "note": "…", "pinNote": false }
 *
 * All body fields are optional; only those present are applied. Tags are
 * resolved-or-created per the conversation's workspace (scope=conversation).
 */
final class AutomationConversationActionController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TagRepository $tags,
        private readonly string $automationToken,
    ) {}

    #[Route(
        path: '/v1/automation/conversations/{id}/apply',
        name: 'api_automation_conversation_apply',
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $this->assertToken($request);

        if (!Uuid::isValid($id)) {
            throw new BadRequestHttpException('Ungültige Conversation-ID.');
        }
        $conversation = $this->em->find(Conversation::class, Uuid::fromString($id));
        if (!$conversation instanceof Conversation) {
            throw new NotFoundHttpException('Conversation nicht gefunden.');
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('Ungültiger Request-Body.');
        }

        $applied = [];

        if (\array_key_exists('status', $data)) {
            $status = ConversationStatus::tryFrom((string) $data['status']);
            if ($status === null) {
                throw new BadRequestHttpException(sprintf('Unbekannter Status "%s".', (string) $data['status']));
            }
            $conversation->setStatus($status);
            $applied[] = 'status';
        }

        if (isset($data['tags']) && \is_array($data['tags'])) {
            foreach ($data['tags'] as $rawName) {
                $name = trim((string) $rawName);
                if ($name === '') {
                    continue;
                }
                $conversation->addTag($this->resolveOrCreateTag($name, $conversation));
            }
            $applied[] = 'tags';
        }

        $note = isset($data['note']) ? trim((string) $data['note']) : '';
        if ($note !== '') {
            $entry = (new ConversationNote())
                ->setConversation($conversation)
                ->setBody(mb_substr($note, 0, 20000))
                ->setIsPinned((bool) ($data['pinNote'] ?? false));
            $entry->setWorkspace($conversation->getWorkspace());
            $this->em->persist($entry);
            $applied[] = 'note';
        }

        $this->em->flush();

        return new JsonResponse([
            'id' => $conversation->getId()?->toRfc4122(),
            'status' => $conversation->getStatus()->value,
            'tags' => array_values(array_map(
                static fn (Tag $t): string => $t->getName(),
                $conversation->getTags()->toArray(),
            )),
            'applied' => $applied,
        ]);
    }

    private function assertToken(Request $request): void
    {
        $configured = trim($this->automationToken);
        if ($configured === '') {
            // Feature disabled — behave as if the route doesn't exist.
            throw new NotFoundHttpException();
        }
        $presented = (string) $request->headers->get('X-Worktide-Automation-Token', '');
        if ($presented === '' || !hash_equals($configured, $presented)) {
            throw new AccessDeniedHttpException('Ungültiges Automation-Token.');
        }
    }

    private function resolveOrCreateTag(string $name, Conversation $conversation): Tag
    {
        $workspace = $conversation->getWorkspace();
        foreach ($this->tags->findBy(['workspace' => $workspace, 'name' => $name]) as $tag) {
            if (\in_array($tag->getScope(), [TagScope::Conversation, TagScope::Any], true)) {
                return $tag;
            }
        }
        $tag = (new Tag())
            ->setName(mb_substr($name, 0, 100))
            ->setScope(TagScope::Conversation);
        $tag->setWorkspace($workspace);
        $this->em->persist($tag);

        return $tag;
    }
}
