<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\HardDeleteOnly;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Makes every API DELETE reversible: entities carrying
 * {@see \App\Entity\Trait\SoftDeletableTrait} are soft-deleted (deletedAt set,
 * row kept, hidden from reads by {@see \App\ApiPlatform\Doctrine\SoftDeleteExtension})
 * instead of being permanently removed. One decorator covers every current and
 * future Delete operation — no per-resource `processor:` wiring, so a new
 * SoftDeletable resource is safe by default.
 *
 * Opt-out: entities implementing {@see HardDeleteOnly} (link/pivot rows that get
 * re-created with the same unique key) fall through to the real remove.
 * Non-soft-deletable entities also fall through unchanged.
 *
 * The retention purge ({@see \App\Command\SoftDeletePurgeCommand}) eventually
 * hard-deletes aged soft-deleted rows, bounding growth and freeing unique keys.
 *
 * @implements ProcessorInterface<object, object|null>
 */
#[AsDecorator('api_platform.doctrine.orm.state.remove_processor')]
final class SoftDeleteRemoveProcessorDecorator implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $inner,
        private readonly EntityManagerInterface $em,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (\is_object($data) && !$data instanceof HardDeleteOnly && method_exists($data, 'softDelete')) {
            $data->softDelete();
            $this->em->flush();

            return $data;
        }

        return $this->inner->process($data, $operation, $uriVariables, $context);
    }
}
