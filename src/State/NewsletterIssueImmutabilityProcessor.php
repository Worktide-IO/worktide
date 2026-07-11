<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\NewsletterIssue;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A sent newsletter issue is a historical record — its content can no longer be
 * edited. A POST always creates a draft (status is not client-writable, defaults
 * Draft), and the send controller flips it to Sent via the EM directly (not this
 * processor), so any NewsletterIssue reaching the persist processor already
 * marked sent must be a PATCH on a sent issue → reject.
 */
#[AsDecorator('api_platform.doctrine.orm.state.persist_processor', priority: 20)]
final class NewsletterIssueImmutabilityProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $inner,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof NewsletterIssue && $data->isSent()) {
            throw new ConflictHttpException('A sent newsletter issue can no longer be edited.');
        }

        return $this->inner->process($data, $operation, $uriVariables, $context);
    }
}
