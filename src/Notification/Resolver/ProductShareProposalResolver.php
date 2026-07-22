<?php

declare(strict_types=1);

namespace App\Notification\Resolver;

use App\Entity\DomainEventLog;
use App\Entity\Enum\NotificationType;
use App\Entity\Enum\ProductShareStatus;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\ProductShare;
use App\Notification\NotificationResolverInterface;
use App\Notification\ResolvedNotification;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Notifies owners and admins of the target workspace when a product is shared
 * with them. Non-batchable — delivered immediately so the receiving team can
 * review and accept the proposal.
 */
final class ProductShareProposalResolver implements NotificationResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function supports(DomainEventLog $event): bool
    {
        return $event->getName() === 'productshare.created';
    }

    public function resolve(DomainEventLog $event): iterable
    {
        $id = $event->getAggregateId();
        $share = $id !== null ? $this->em->find(ProductShare::class, $id) : null;
        if (!$share instanceof ProductShare || $share->getStatus() !== ProductShareStatus::Proposed) {
            return;
        }

        $targetWs = $share->getTargetWorkspace();
        $product = $share->getProduct();
        $productName = $product->getName();
        $sourceName = $share->getSourceWorkspace()->getName();

        foreach ($targetWs->getMembers() as $member) {
            if (!$member->getIsActive()) {
                continue;
            }
            $role = $member->getRole();
            if ($role !== WorkspaceMemberRole::Owner && $role !== WorkspaceMemberRole::Admin) {
                continue;
            }
            $user = $member->getUser();

            yield new ResolvedNotification(
                recipient: $user,
                type: NotificationType::ProductShareProposed,
                titleKey: 'notification.product_share_proposed',
                titleParams: [
                    '%product%' => $productName,
                    '%workspace%' => $sourceName,
                ],
                link: '/produkte',
                body: $share->getMessage(),
            );
        }
    }
}
