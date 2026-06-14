<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\WorkspaceInvitation;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Mints the opaque accept token on POST and stamps it onto the entity so
 * the response carries it to the operator (who emails it on). DELETE / GET
 * pass through unchanged — the token is never returned afterwards.
 */
#[AsDecorator('api_platform.doctrine.orm.state.persist_processor', priority: 30)]
final class WorkspaceInvitationIssueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $inner,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof WorkspaceInvitation && $operation instanceof Post && $data->getToken() === '') {
            $plain = bin2hex(random_bytes(32));
            $data->setToken($plain);
            $data->setPlaintextToken($plain);
        }
        return $this->inner->process($data, $operation, $uriVariables, $context);
    }
}
