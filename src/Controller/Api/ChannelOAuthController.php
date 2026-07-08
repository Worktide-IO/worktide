<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Channels\Adapter\EmailGraph\GraphSubscriptionManager;
use App\Channels\OAuth\OAuth2Client;
use App\Channels\OAuth\OAuth2ConfigurationException;
use App\Channels\OAuth\OAuth2TokenException;
use App\Entity\Channel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * OAuth2 endpoints for adapter-backed channels (email_graph, email_gmail).
 *
 *   GET  /v1/channels/{id}/oauth/start    → 302 to provider authorize URL
 *   GET  /v1/channels/oauth/callback      → exchange code, redirect back to SPA
 *
 * The start endpoint is JWT-authenticated like the rest of /v1; the
 * callback endpoint is public (the provider redirects through the
 * user's browser, no JWT to forward) and identifies the channel via
 * the signed `state` parameter the start endpoint emitted.
 *
 * The callback always redirects to a fixed SPA route
 * (`/inbox?oauth=ok|err`) so the SPA can show a success / failure
 * toast without coupling to this controller's response shape.
 */
final class ChannelOAuthController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly OAuth2Client $oauth,
        private readonly GraphSubscriptionManager $graphSubscriptions,
        private readonly string $spaRedirectBase,
    ) {}

    #[Route(
        path: '/v1/channels/{id}/oauth/start',
        name: 'api_channels_oauth_start',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['GET'],
    )]
    public function start(string $id): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        try {
            $channel = $this->em->find(Channel::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid channel UUID.');
        }
        if ($channel === null) {
            throw new NotFoundHttpException('Channel not found.');
        }
        // Tenant guard mirrors the workspace-scope extension that the
        // generic API Platform reads ride; raw controllers don't.
        $member = $this->em->getRepository(\App\Entity\WorkspaceMember::class)
            ->findOneBy(['user' => $user, 'workspace' => $channel->getWorkspace()]);
        if ($member === null) {
            throw new AccessDeniedHttpException();
        }

        try {
            $url = $this->oauth->buildAuthorizeUrl($channel);
        } catch (OAuth2ConfigurationException $e) {
            return new JsonResponse([
                'error' => 'oauth_not_configured',
                'detail' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Return the URL rather than 302 so the SPA can either redirect
        // top-level or open in a popup — its choice. Top-level redirect
        // is the simpler flow; popup is needed for embedded contexts.
        return new JsonResponse(['authorizeUrl' => $url]);
    }

    #[Route(
        path: '/v1/channels/oauth/callback',
        name: 'api_channels_oauth_callback',
        methods: ['GET'],
    )]
    public function callback(Request $request): RedirectResponse
    {
        $code = (string) $request->query->get('code', '');
        $state = (string) $request->query->get('state', '');
        $error = (string) $request->query->get('error', '');

        if ($error !== '') {
            return new RedirectResponse($this->spaRedirectUrl('err', $error));
        }
        if ($code === '' || $state === '') {
            return new RedirectResponse($this->spaRedirectUrl('err', 'missing_params'));
        }

        try {
            $channelId = $this->oauth->decodeState($state);
            $channel = $this->em->find(Channel::class, Uuid::fromString($channelId));
            if ($channel === null) {
                return new RedirectResponse($this->spaRedirectUrl('err', 'channel_not_found'));
            }
            $this->oauth->exchangeCode($channel, $code);

            // Register a Graph push subscription immediately so mail arrives in
            // near-realtime without waiting for the reconcile cron. Best-effort:
            // a failure here (e.g. unreachable notificationUrl in dev) must not
            // fail the OAuth success — the cron + the 2-min poll are the safety net.
            if ($channel->getAdapterCode() === 'email_graph') {
                try {
                    $this->graphSubscriptions->subscribe($channel);
                } catch (\Throwable) {
                    // swallow — reconcile cron will retry
                }
            }
        } catch (OAuth2TokenException | OAuth2ConfigurationException $e) {
            return new RedirectResponse($this->spaRedirectUrl('err', $e->getMessage()));
        } catch (\Throwable $e) {
            return new RedirectResponse($this->spaRedirectUrl('err', 'unexpected: ' . $e->getMessage()));
        }
        return new RedirectResponse($this->spaRedirectUrl('ok'));
    }

    private function spaRedirectUrl(string $status, ?string $message = null): string
    {
        $url = rtrim($this->spaRedirectBase, '/') . '/inbox?oauth=' . $status;
        if ($message !== null) {
            $url .= '&message=' . rawurlencode(substr($message, 0, 200));
        }
        return $url;
    }
}
