<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class NoDependencyCycle extends Constraint
{
    public string $message = 'This dependency would create a cycle through {{ chain }}.';
    public string $selfMessage = 'A task cannot depend on itself.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
