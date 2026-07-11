<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\I18n\LocaleResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Surfaces the resolved display locale to the framework, so `->trans()` and the
 * Twig `|trans` filter (email templates) render in the caller's language.
 *
 * Runs AFTER the firewall (priority < 8) so {@see LocaleResolver} can read the
 * authenticated user. Locale comes from stored state (user pref → workspace →
 * default), NOT Accept-Language — see LocaleResolver's docblock (keeps responses
 * cacheable). Only the main request is touched.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LocaleResolver $localeResolver,
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
        $event->getRequest()->setLocale($this->localeResolver->resolve());
    }
}
