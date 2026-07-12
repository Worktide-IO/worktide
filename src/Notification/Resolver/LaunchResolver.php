<?php

declare(strict_types=1);

namespace App\Notification\Resolver;

use App\Entity\CustomerProduct;
use App\Entity\DomainEventLog;
use App\Entity\Enum\NotificationType;
use App\Entity\ServiceSubscription;
use App\Notification\NotificationResolverInterface;
use App\Notification\RecipientResolver;
use App\Notification\ResolvedNotification;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A product/service launched FOR a specific customer → notify that customer's
 * portal users.
 *
 * Handles the two per-customer launch signals:
 *  - `servicesubscription.created` (ServiceSubscription is already tracked)
 *  - `customerproduct.created`     (CustomerProduct added to the TRACKED map)
 *
 * Catalog-wide `product.created` / `productversion.created` are intentionally
 * out of v1: they have no single customer audience, so notifying would mean a
 * broadcast — deferred until the target audience is decided.
 */
final class LaunchResolver implements NotificationResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecipientResolver $recipients,
    ) {}

    public function supports(DomainEventLog $event): bool
    {
        return \in_array($event->getName(), ['servicesubscription.created', 'customerproduct.created'], true);
    }

    public function resolve(DomainEventLog $event): iterable
    {
        $id = $event->getAggregateId();
        if ($id === null) {
            return;
        }

        if ($event->getName() === 'servicesubscription.created') {
            $sub = $this->em->find(ServiceSubscription::class, $id);
            if (!$sub instanceof ServiceSubscription) {
                return;
            }
            yield from $this->fanOut($sub->getCustomer(), 'notification.launch_service', $sub->getName());

            return;
        }

        $cp = $this->em->find(CustomerProduct::class, $id);
        if (!$cp instanceof CustomerProduct) {
            return;
        }
        $label = trim($cp->getProduct()->getName() . ' ' . ($cp->getProductVersion()?->getVersion() ?? ''));
        yield from $this->fanOut($cp->getCustomer(), 'notification.launch_product', $label);
    }

    /**
     * @return iterable<ResolvedNotification>
     */
    private function fanOut(\App\Entity\Customer $customer, string $titleKey, string $body): iterable
    {
        foreach ($this->recipients->portalUsersOfCustomer($customer) as $user) {
            yield new ResolvedNotification(
                recipient: $user,
                type: NotificationType::Launch,
                titleKey: $titleKey,
                link: '/dashboard',
                body: $body !== '' ? $body : null,
            );
        }
    }
}
