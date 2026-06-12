<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Implements the Idempotency-Key header semantics on mutating requests.
 *
 * Client sends Idempotency-Key on POST/PATCH/PUT/DELETE → if we've already
 * processed a request with that key (within the TTL), return the cached
 * response instead of re-executing. Prevents duplicate creates on retries
 * and accidental double-submits.
 *
 * Scope: per-IP for now. Once Auth lands, the scope key should include
 * the authenticated user / API key.
 *
 * Limit: only caches API-Platform routes (anything under /v1).
 *
 * Convention follows Stripe / IETF draft-ietf-httpapi-idempotency-key-header.
 */
final class IdempotencyKeySubscriber
{
    private const HEADER = 'Idempotency-Key';
    private const METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];
    private const TTL_SECONDS = 86400; // 24 hours
    private const CACHE_KEY_PREFIX = 'idem_';
    private const ATTR_KEY = '_idempotency_cache_key';
    private const ATTR_MISS = '_idempotency_miss';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->shouldHandle($request)) {
            return;
        }

        $key = $request->headers->get(self::HEADER);
        if ($key === null || $key === '') {
            return;
        }
        if (strlen($key) > 200) {
            return;
        }

        $cacheKey = $this->buildCacheKey($request, $key);
        $request->attributes->set(self::ATTR_KEY, $cacheKey);

        $item = $this->cache->getItem($cacheKey);
        if (!$item->isHit()) {
            $request->attributes->set(self::ATTR_MISS, true);
            return;
        }

        /** @var array{status: int, headers: array<string, list<string>>, body: string} $cached */
        $cached = $item->get();
        $response = new Response($cached['body'], $cached['status'], []);
        foreach ($cached['headers'] as $name => $values) {
            $response->headers->set($name, $values, false);
        }
        $response->headers->set('Idempotent-Replay', 'true');
        $event->setResponse($response);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -8)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $cacheKey = $request->attributes->get(self::ATTR_KEY);
        if (!\is_string($cacheKey)) {
            return;
        }
        if (!$request->attributes->get(self::ATTR_MISS, false)) {
            return;
        }

        $response = $event->getResponse();

        // Only cache successful or "controlled" responses; not 5xx, which the
        // client should be free to retry without replaying the failure.
        if ($response->getStatusCode() >= 500) {
            return;
        }

        $item = $this->cache->getItem($cacheKey);
        $item->set([
            'status' => $response->getStatusCode(),
            'headers' => array_map(fn ($v) => (array) $v, $response->headers->all()),
            'body' => $response->getContent() ?: '',
        ]);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    private function shouldHandle(Request $request): bool
    {
        if (!\in_array($request->getMethod(), self::METHODS, true)) {
            return false;
        }
        return str_starts_with($request->getPathInfo(), '/v1');
    }

    private function buildCacheKey(Request $request, string $idempotencyKey): string
    {
        $scope = $request->getClientIp() ?? 'anon';
        return self::CACHE_KEY_PREFIX . hash('sha256', $scope . '|' . $request->getMethod() . '|' . $request->getPathInfo() . '|' . $idempotencyKey);
    }
}
