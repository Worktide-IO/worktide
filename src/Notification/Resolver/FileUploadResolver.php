<?php

declare(strict_types=1);

namespace App\Notification\Resolver;

use App\Entity\DomainEventLog;
use App\Entity\Enum\FileTarget;
use App\Entity\Enum\NotificationType;
use App\Notification\NotificationResolverInterface;
use App\Notification\RecipientResolver;
use App\Notification\ResolvedNotification;
use App\Repository\CustomerRepository;
use App\Repository\FileRepository;

/**
 * Notifies about newly uploaded files in a customer's shared file area, in both
 * directions (delivery is batched — see {@see NotificationType::isBatchable()}):
 *
 *  - a CUSTOMER (portal contact) uploads → notify the responsible staff user
 *    (Customer.accountManager) — type CustomerFileUpload.
 *  - STAFF shares a file into the customer area → notify the customer's active
 *    portal contacts — type FileShared.
 *
 * Staff-hidden files (isHiddenForConnectUsers) never notify the customer, and a
 * file is only relevant here when target=Customer. The uploader is never
 * notified about their own upload.
 */
final class FileUploadResolver implements NotificationResolverInterface
{
    public function __construct(
        private readonly FileRepository $files,
        private readonly CustomerRepository $customers,
        private readonly RecipientResolver $recipients,
    ) {}

    public function supports(DomainEventLog $event): bool
    {
        return $event->getName() === 'file.created';
    }

    public function resolve(DomainEventLog $event): iterable
    {
        $fileId = $event->getAggregateId();
        $file = $fileId !== null ? $this->files->find($fileId) : null;
        if ($file === null || $file->getTarget() !== FileTarget::Customer) {
            return;
        }
        $customer = $this->customers->find($file->getTargetId());
        if ($customer === null) {
            return;
        }

        $uploader = $file->getUploadedBy();
        $uploaderId = $uploader?->getId()?->toRfc4122();
        $portalUsers = $this->recipients->portalUsersOfCustomer($customer);
        $portalIds = array_map(static fn ($u): ?string => $u->getId()?->toRfc4122(), $portalUsers);
        $uploadedByPortal = $uploaderId !== null && \in_array($uploaderId, $portalIds, true);
        $fileName = $file->getName();

        if ($uploadedByPortal) {
            // Customer → responsible staff user.
            $manager = $customer->getAccountManager();
            if ($manager === null || $manager->getId()?->toRfc4122() === $uploaderId) {
                return;
            }
            yield new ResolvedNotification(
                recipient: $manager,
                type: NotificationType::CustomerFileUpload,
                titleKey: 'notification.customer_file_upload',
                link: '/customers/' . ($customer->getId()?->toRfc4122() ?? '') . '?tab=files',
                body: $fileName,
                titleParams: ['%customer%' => $customer->getName()],
            );

            return;
        }

        // Staff shared a (visible) file → the customer's portal contacts.
        if ($file->isHiddenForConnectUsers()) {
            return;
        }
        foreach ($portalUsers as $recipient) {
            if ($recipient->getId()?->toRfc4122() === $uploaderId) {
                continue;
            }
            yield new ResolvedNotification(
                recipient: $recipient,
                type: NotificationType::FileShared,
                titleKey: 'notification.file_shared',
                link: '/dateien',
                body: $fileName,
            );
        }
    }
}
