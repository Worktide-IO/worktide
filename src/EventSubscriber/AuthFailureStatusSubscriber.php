<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * Lexik's default JSON-login failure handler swallows every
 * AuthenticationException (UserNotFound, BadCredentials,
 * TooManyLoginAttempts, …) into a generic 401. That hides the rate-
 * limit signal from clients — the SPA sees "wrong password" when in
 * truth the server is shedding load.
 *
 * Intercept the failure event and, if the underlying exception is the
 * throttling one, replace the response with a 429 + Retry-After hint
 * the client can show ("Bitte in 60s erneut versuchen").
 *
 * The generic 401-on-bad-credentials path is left alone — Symfony does
 * NOT distinguish UserNotFound from BadCredentials by design (don't
 * leak whether the user exists). We only override for the throttling
 * case, which is observable through the wire anyway.
 */
final class AuthFailureStatusSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Higher priority than Lexik's default listener (which is 0) so we
        // run first and can replace its response.
        return [Events::AUTHENTICATION_FAILURE => ['onFailure', 16]];
    }

    public function onFailure(AuthenticationFailureEvent $event): void
    {
        $exception = $event->getException();
        $previous = $exception->getPrevious();
        if (
            !$exception instanceof TooManyLoginAttemptsAuthenticationException
            && !$previous instanceof TooManyLoginAttemptsAuthenticationException
        ) {
            return;
        }
        $event->setResponse(new JsonResponse(
            [
                'code' => Response::HTTP_TOO_MANY_REQUESTS,
                'message' => 'Too many login attempts. Please retry shortly.',
            ],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => '60'],
        ));
    }
}
