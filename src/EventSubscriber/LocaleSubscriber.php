<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\I18n\LocaleResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Surfaces the resolved display locale to the framework, so `->trans()` and the
 * Twig `|trans` filter (email templates) render in the caller's language.
 *
 * Runs AFTER the firewall (priority < 8) so {@see LocaleResolver} can read the
 * authenticated user. Locale comes from stored state (user pref → workspace →
 * default), NOT Accept-Language — see LocaleResolver's docblock (keeps responses
 * cacheable). Only the main request is touched.
 *
 * We run at priority 6 — necessarily AFTER Symfony's LocaleAwareListener (15),
 * which is what normally pushes the request locale into the translator. Because
 * we need the authenticated user (available only after the firewall at 8), we
 * come too late for that sync, so we push the locale into the translator here
 * ourselves in addition to setting it on the request.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LocaleResolver $localeResolver,
        private readonly TranslatorInterface $translator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priority 6: after the firewall (8) so the user token is available.
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $locale = $this->localeResolver->resolve();
        $event->getRequest()->setLocale($locale);
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }
    }
}
