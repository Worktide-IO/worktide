<?php

declare(strict_types=1);

namespace App\Service\Newsletter;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Contact;
use App\Entity\Newsletter;
use App\Entity\NewsletterIssue;
use App\Entity\NewsletterSubscription;
use App\Entity\Workspace;
use App\Service\I18n\RecipientLocaleResolver;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Renders + sends one newsletter issue to one contact. Called from the async
 * {@see \App\MessageHandler\SendNewsletterHandler} (one message per recipient),
 * so a slow/broken mail server never blocks the send request or the other
 * recipients.
 *
 * The markdown body is rendered to HTML server-side (GFM, raw-HTML stripped so
 * an admin-authored body can't inject markup), simple `{{ firstName }}` /
 * `{{ lastName }}` / `{{ company }}` placeholders are substituted per recipient,
 * and every mail carries a working one-click unsubscribe link. Behind the
 * default-deny {@see EgressModule::NewsletterSend} gate — distinct from
 * transactional `email_outbound`, so bulk sends are opted into separately.
 */
final class NewsletterMailer
{
    private readonly GithubFlavoredMarkdownConverter $markdown;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EgressGuard $egress,
        private readonly NewsletterUnsubscribeSigner $signer,
        private readonly NewsletterConfirmSigner $confirmSigner,
        private readonly RecipientLocaleResolver $localeResolver,
        private readonly string $mailFrom,
        private readonly string $mailFromName = '',
    ) {
        // Strip raw HTML in the markdown → the rendered body is safe to mark
        // |raw in the email template; drop unsafe (javascript:) links too.
        $this->markdown = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Send `$issue` to `$contact`. Returns false when egress is denied, the
     * contact has no address, or the ids are missing — never throws for those.
     */
    public function send(NewsletterIssue $issue, Contact $contact): bool
    {
        $to = $contact->getEmail();
        if ($to === null || $to === '' || !$this->egress->isAllowed(EgressModule::NewsletterSend)) {
            return false;
        }
        $newsletter = $issue->getNewsletter();
        $contactId = $contact->getId();
        $newsletterId = $newsletter?->getId();
        if ($newsletter === null || $contactId === null || $newsletterId === null) {
            return false;
        }

        $bodyMarkdown = $this->fillPlaceholders($issue->getBody() ?? '', $contact);
        $bodyHtml = $this->markdown->convert($bodyMarkdown)->getContent();

        // Rendered async (Messenger), so the recipient's locale travels in the
        // context and the templates/base footer apply it via the trans filter.
        // The subject/body stay author-written content and are not translated.
        $locale = $this->localeResolver->forContact($contact);

        [$from, $replyTo] = $this->resolveSender($newsletter);
        $mail = (new TemplatedEmail())
            ->from($from)
            ->to(new Address($to, trim($contact->getFirstName() . ' ' . $contact->getLastName())))
            ->subject($this->fillPlaceholders($issue->getSubject(), $contact))
            ->htmlTemplate('email/newsletter.html.twig')
            ->textTemplate('email/newsletter.txt.twig')
            ->context([
                'newsletterTitle' => $newsletter->getTitle(),
                'subject' => $issue->getSubject(),
                'bodyHtml' => $bodyHtml,
                'bodyText' => $bodyMarkdown,
                'firstName' => $contact->getFirstName(),
                'unsubscribeUrl' => $this->signer->unsubscribeUrl($contactId, $newsletterId),
                'locale' => $locale,
            ]);
        if ($replyTo !== null) {
            $mail->replyTo($replyTo);
        }

        $this->mailer->send($mail);

        return true;
    }

    /**
     * Send the double-opt-in confirmation mail for a pending subscription. This is
     * a single transactional message triggered by the contact's own opt-in, so it
     * sits behind {@see EgressModule::EmailOutbound}, not the bulk NewsletterSend
     * gate. Returns false when egress is denied, the contact has no address, or the
     * ids are missing.
     */
    public function sendConfirmation(NewsletterSubscription $subscription): bool
    {
        $contact = $subscription->getContact();
        $to = $contact->getEmail();
        if ($to === null || $to === '' || !$this->egress->isAllowed(EgressModule::EmailOutbound)) {
            return false;
        }
        $newsletter = $subscription->getNewsletter();
        $contactId = $contact->getId();
        $newsletterId = $newsletter->getId();
        if ($contactId === null || $newsletterId === null) {
            return false;
        }

        $locale = $this->localeResolver->forContact($contact);
        [$from, $replyTo] = $this->resolveSender($newsletter);
        $mail = (new TemplatedEmail())
            ->from($from)
            ->to(new Address($to, trim($contact->getFirstName() . ' ' . $contact->getLastName())))
            ->subject($newsletter->getTitle())
            ->htmlTemplate('email/newsletter_confirm.html.twig')
            ->textTemplate('email/newsletter_confirm.txt.twig')
            ->context([
                'newsletterTitle' => $newsletter->getTitle(),
                'firstName' => $contact->getFirstName(),
                'confirmUrl' => $this->confirmSigner->confirmUrl($contactId, $newsletterId),
                'locale' => $locale,
            ]);
        if ($replyTo !== null) {
            $mail->replyTo($replyTo);
        }

        $this->mailer->send($mail);

        return true;
    }

    /**
     * Resolve the From address and optional Reply-To for a newsletter's workspace.
     * The From ADDRESS stays the global MAILER_FROM (deliverability/SPF); only the
     * display name is overridden per workspace (settings.newsletter.senderName), and
     * a workspace Reply-To (settings.newsletter.replyTo) is added when valid.
     *
     * @return array{0: Address, 1: Address|null}
     */
    private function resolveSender(Newsletter $newsletter): array
    {
        $settings = $newsletter->getWorkspace()->getSettings();
        $ns = \is_array($settings['newsletter'] ?? null) ? $settings['newsletter'] : [];

        $senderName = \is_string($ns['senderName'] ?? null) ? trim($ns['senderName']) : '';
        if ($senderName === '') {
            $senderName = $this->mailFromName !== '' ? $this->mailFromName : 'Worktide';
        }
        $from = new Address($this->mailFrom, $senderName);

        $replyTo = null;
        $replyToRaw = \is_string($ns['replyTo'] ?? null) ? trim($ns['replyTo']) : '';
        if ($replyToRaw !== '' && filter_var($replyToRaw, \FILTER_VALIDATE_EMAIL) !== false) {
            $replyTo = new Address($replyToRaw);
        }

        return [$from, $replyTo];
    }

    private function fillPlaceholders(string $text, Contact $contact): string
    {
        $map = [
            'firstName' => $contact->getFirstName(),
            'lastName' => $contact->getLastName(),
            'company' => $contact->getCustomer()->getName(),
        ];
        foreach ($map as $key => $value) {
            $text = preg_replace('/\{\{\s*' . $key . '\s*\}\}/', $value, $text) ?? $text;
        }

        return $text;
    }
}
