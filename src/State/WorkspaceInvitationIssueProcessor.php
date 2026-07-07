<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\WorkspaceInvitation;
use App\Service\WorkspaceInvitationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Mints the opaque accept token on POST and stamps it onto the entity so
 * the response still carries it (plaintextToken) to the operator, then mails
 * the branded invitation with the magic accept link to the invitee. DELETE /
 * GET pass through unchanged — the token is never returned afterwards.
 */
#[AsDecorator('api_platform.doctrine.orm.state.persist_processor', priority: 30)]
final class WorkspaceInvitationIssueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $inner,
        private readonly WorkspaceInvitationMailer $mailer,
        private readonly EntityManagerInterface $em,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $isNewInvitation = $data instanceof WorkspaceInvitation
            && $operation instanceof Post
            && $data->getToken() === '';

        if ($isNewInvitation) {
            $plain = bin2hex(random_bytes(32));
            $data->setToken($plain);
            $data->setPlaintextToken($plain);
        }

        $result = $this->inner->process($data, $operation, $uriVariables, $context);

        // Auto-send the invitation email once the row (and its id) exist.
        if ($isNewInvitation && $result instanceof WorkspaceInvitation) {
            if ($this->mailer->send($result)) {
                $this->em->flush(); // persist sentAt / sendCount
            }
        }

        return $result;
    }
}
