<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ProjectShareInvitation;
use App\Service\ProjectShareInvitationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Mints the opaque accept token on POST of a ProjectShareInvitation, stamps it
 * onto the entity so the response still carries it (plainToken) to the
 * operator, then mails the branded share invitation with the magic accept link
 * to the invitee. GET/DELETE pass through unchanged.
 *
 * Mirrors {@see WorkspaceInvitationIssueProcessor}; distinct decorator priority
 * so both chain around the persist processor, each acting only on its own type.
 */
#[AsDecorator('api_platform.doctrine.orm.state.persist_processor', priority: 29)]
final class ProjectShareInvitationIssueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $inner,
        private readonly ProjectShareInvitationMailer $mailer,
        private readonly EntityManagerInterface $em,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $isNew = $data instanceof ProjectShareInvitation
            && $operation instanceof Post
            && $data->getToken() === '';

        if ($isNew) {
            $plain = bin2hex(random_bytes(32));
            $data->setToken($plain);
            $data->setPlainToken($plain);
        }

        $result = $this->inner->process($data, $operation, $uriVariables, $context);

        if ($isNew && $result instanceof ProjectShareInvitation) {
            if ($this->mailer->send($result)) {
                $this->em->flush();
            }
        }

        return $result;
    }
}
