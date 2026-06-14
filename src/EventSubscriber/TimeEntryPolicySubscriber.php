<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\TimeEntry;
use App\Entity\TimeTrackingSettings;
use App\Repository\TimeTrackingSettingsRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Applies the workspace's TimeTrackingSettings to every newly-persisted
 * TimeEntry: rounds up to the nearest `roundingMinutes`, floors at
 * `minimumMinutes`, and rejects future starts when those are disabled.
 *
 * Auto-lock by age happens in a separate scheduled command — too expensive
 * to evaluate on every entry write.
 *
 * No-op when no settings row exists for the workspace, i.e. workspaces stay
 * permissive by default.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class TimeEntryPolicySubscriber
{
    public function __construct(
        private readonly TimeTrackingSettingsRepository $settings,
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TimeEntry) {
            return;
        }
        $settings = $this->settings->findForWorkspace($entity->getWorkspace());
        if ($settings === null) {
            return;
        }
        if (!$settings->isAllowFutureEntries() && $entity->getStartsAt() > new \DateTimeImmutable()) {
            throw new BadRequestHttpException('Workspace forbids future-dated time entries.');
        }

        $duration = $entity->getDurationMinutes();
        if ($settings->getRoundingMinutes() > 0) {
            $duration = $this->roundUp($duration, $settings->getRoundingMinutes());
        }
        if ($settings->getMinimumMinutes() > 0 && $duration < $settings->getMinimumMinutes()) {
            $duration = $settings->getMinimumMinutes();
        }
        if ($duration !== $entity->getDurationMinutes()) {
            $entity->setDurationMinutes($duration);
        }
    }

    private function roundUp(int $value, int $step): int
    {
        if ($value <= 0) {
            return 0;
        }
        return (int) (ceil($value / $step) * $step);
    }
}
