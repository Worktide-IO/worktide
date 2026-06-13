<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\TaskDependency;
use App\Repository\TaskDependencyRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class NoDependencyCycleValidator extends ConstraintValidator
{
    public function __construct(
        private readonly TaskDependencyRepository $dependencies,
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoDependencyCycle) {
            return;
        }
        if (!$value instanceof TaskDependency) {
            throw new UnexpectedValueException($value, TaskDependency::class);
        }

        $pred = $value->getPredecessor();
        $succ = $value->getSuccessor();

        if ($pred === $succ || $pred->getId()?->equals($succ->getId())) {
            $this->context->buildViolation($constraint->selfMessage)
                ->atPath('successor')
                ->addViolation();
            return;
        }

        if ($this->dependencies->wouldCreateCycle($pred, $succ)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ chain }}', sprintf('%s → %s', $pred->getIdentifier(), $succ->getIdentifier()))
                ->atPath('successor')
                ->addViolation();
        }
    }
}
