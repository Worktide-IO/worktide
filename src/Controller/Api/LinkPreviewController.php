<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\LinkPreview\LinkPreviewResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /v1/links/preview?url=<encoded>
 *
 * Resolves an EXTERNAL URL (pasted into an editor) into a card-ready preview
 * (title / description / thumbnail) via oEmbed or OpenGraph, so the SPA can
 * render a rich smart-link chip. Deliberately separate from the INTERNAL entity
 * resolver ({@see LinkResolverController} at /v1/links/resolve): this one makes
 * an egress-gated, SSRF-guarded outbound fetch.
 *
 * Returns 200 with the preview payload on a hit, or 204 (No Content) when the
 * URL is blocked (egress off / SSRF), unresolvable, or has no usable metadata —
 * the SPA falls back to a plain host chip. Authenticated + per-user rate limited.
 */
final class LinkPreviewController
{
    public function __construct(
        private readonly Security $security,
        private readonly LinkPreviewResolver $resolver,
        private readonly RateLimiterFactory $linkPreviewLimiter,
    ) {}

    #[Route(
        path: '/v1/links/preview',
        name: 'api_links_preview',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $url = trim((string) ($request->query->get('url') ?? ''));
        if ($url === '') {
            throw new BadRequestHttpException('url query parameter required.');
        }

        $this->throttle($user);

        $preview = $this->resolver->resolve($url);
        if ($preview === null) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        return new JsonResponse($preview);
    }

    private function throttle(User $user): void
    {
        $limit = $this->linkPreviewLimiter->create($user->getId()?->toRfc4122() ?? 'unknown');
        $reservation = $limit->consume();
        if (!$reservation->isAccepted()) {
            $retryAfter = max(1, $reservation->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, \sprintf('Too many requests — retry in %ds.', $retryAfter));
        }
    }
}
