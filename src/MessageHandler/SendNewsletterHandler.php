<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Contact;
use App\Entity\NewsletterIssue;
use App\Message\SendNewsletterMessage;
use App\Service\Newsletter\NewsletterMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Delivers one newsletter issue to one contact. Missing issue/contact (deleted
 * between fan-out and consume) is unrecoverable → straight to the failed
 * transport; a transient mail error propagates so the transport's retry
 * strategy applies.
 */
#[AsMessageHandler]
final class SendNewsletterHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsletterMailer $mailer,
    ) {}

    public function __invoke(SendNewsletterMessage $message): void
    {
        $issue = $this->em->find(NewsletterIssue::class, $message->getIssueId());
        $contact = $this->em->find(Contact::class, $message->getContactId());
        if (!$issue instanceof NewsletterIssue || !$contact instanceof Contact) {
            throw new UnrecoverableMessageHandlingException('Newsletter issue or contact no longer exists.');
        }

        $this->mailer->send($issue, $contact);
    }
}
