<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\TranslatableInterface;
use App\Service\I18n\LocaleResolver;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Overlays the active-locale translation onto every {@see TranslatableInterface}
 * on the way out — format-agnostic (JSON-LD and plain JSON alike).
 *
 * It does not decorate a specific API Platform normalizer (those are
 * per-format); instead it registers ahead of them (autoconfigured priority 0
 * beats API Platform's negative item-normalizer priorities), tags the context
 * so it isn't re-entered, and delegates to the real normalizer to build the
 * array. It then replaces each translatable base field with the resolved
 * value, leaving the raw `translations` map in place for editing UIs.
 *
 * Missing translation ⇒ base (source-language) value is kept — never blanked.
 */
final class TranslatableNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'TRANSLATABLE_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        private readonly LocaleResolver $localeResolver,
    ) {}

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $context[self::ALREADY_CALLED] = true;

        $normalized = $this->normalizer->normalize($data, $format, $context);

        if ($data instanceof TranslatableInterface && \is_array($normalized)) {
            $locale = $this->localeResolver->resolve();
            foreach ($data::translatableFields() as $field) {
                if (!\array_key_exists($field, $normalized)) {
                    continue;
                }
                $translated = $data->getTranslation($field, $locale);
                if ($translated !== null) {
                    $normalized[$field] = $translated;
                }
            }
        }

        return $normalized;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return !isset($context[self::ALREADY_CALLED]) && $data instanceof TranslatableInterface;
    }

    /**
     * @return array<class-string|'*'|'object'|string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        // Support hinges on a runtime context flag, so this normalizer cannot
        // be cached as "always handles TranslatableInterface".
        return [TranslatableInterface::class => false];
    }
}
