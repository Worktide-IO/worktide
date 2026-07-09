<?php

declare(strict_types=1);

namespace App\Notification\Resolver;

use App\Entity\DomainEventLog;
use App\Entity\Enum\NotificationType;
use App\Entity\SystemIncident;
use App\Notification\NotificationResolverInterface;
use App\Notification\RecipientResolver;
use App\Notification\ResolvedNotification;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A new system incident (Störung) → notify the affected customer's portal
 * users. Requires SystemIncident to be tracked by DomainEventEmitterSubscriber
 * (added to its TRACKED map) so `systemincident.created` fires.
 *
 * v1 targets the customer (portal) audience only — the same signal the old
 * derived portal feed surfaced. Staff-side incident alerts are a later add.
 */
final class SystemIncidentResolver implements NotificationResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecipientResolver $recipients,
    ) {}

    public function supports(DomainEventLog $event): bool
    {
        return $event->getName() === 'systemincident.created';
    }

    public function resolve(DomainEventLog $event): iterable
    {
        $id = $event->getAggregateId();
        $incident = $id !== null ? $this->em->find(SystemIncident::class, $id) : null;
        if (!$incident instanceof SystemIncident || !$incident->isOpen()) {
            return;
        }
        $system = $incident->getSystem();
        $customer = $system->getCustomer();

        foreach ($this->recipients->portalUsersOfCustomer($customer) as $user) {
            yield new ResolvedNotification(
                recipient: $user,
                type: NotificationType::System,
                title: 'Störung: ' . $system->getName(),
                link: '/monitoring',
                body: $incident->getTitle(),
            );
        }
    }
}
