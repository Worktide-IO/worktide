<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * DELETE processor that soft-deletes instead of hard-removing.
 *
 * Most entities carry {@see \App\Entity\Trait\SoftDeletableTrait} but nothing
 * wired their DELETE to it, so an API delete was a permanent row removal. Wire
 * this processor onto a resource's Delete operation (`processor:` argument) and
 * add the class to {@see \App\ApiPlatform\Doctrine\SoftDeleteExtension} so the
 * row survives (restorable) while vanishing from every API read.
 *
 * @implements ProcessorInterface<object, object|null>
 */
final class SoftDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (\is_object($data) && method_exists($data, 'softDelete')) {
            $data->softDelete();
            $this->em->flush();

            return $data;
        }

        // Not soft-deletable → fall back to a real removal so the operation
        // still deletes rather than silently no-op'ing.
        if (\is_object($data)) {
            $this->em->remove($data);
            $this->em->flush();
        }

        return null;
    }
}
