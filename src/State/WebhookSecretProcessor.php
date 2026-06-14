<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Webhook;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * API Platform 4 refuses to denormalize a property declared with
 * {@code readable: false}, so the standard JSON-body deserialization can't
 * carry the webhook secret. This decorator runs after deserialization, lifts
 * `secret` directly off the raw JSON body and writes it onto the Webhook
 * before the upstream persist processor flushes the entity.
 *
 * The secret never appears in any normalized response — see
 * {@see Webhook::$secret} attribute — so once set it is invisible until the
 * operator rotates it with another POST/PATCH.
 *
 * For PATCH, callers may omit `secret`; existing values stay intact.
 */
#[AsDecorator('api_platform.doctrine.orm.state.persist_processor')]
final class WebhookSecretProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PersistProcessor $inner,
        private readonly RequestStack $requests,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Webhook) {
            $request = $this->requests->getCurrentRequest();
            if ($request !== null) {
                $raw = $request->getContent();
                if (\is_string($raw) && $raw !== '') {
                    try {
                        $payload = json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
                        if (\is_array($payload) && \is_string($payload['secret'] ?? null) && $payload['secret'] !== '') {
                            $data->setSecret($payload['secret']);
                        }
                    } catch (\JsonException) {
                        // Body parser-validated upstream; treat as no secret update.
                    }
                }
            }
        }
        return $this->inner->process($data, $operation, $uriVariables, $context);
    }
}
