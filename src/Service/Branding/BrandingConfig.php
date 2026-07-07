<?php

declare(strict_types=1);

namespace App\Service\Branding;

/**
 * White-label branding, resolved from BRAND_* env vars.
 *
 * Single source of truth for the instance's branding: consumed by the public
 * {@see \App\Controller\Api\BrandingController} endpoint (read by both SPAs at
 * runtime) and exposed to Twig as the `brand` global for system emails.
 *
 * All values are optional; getters apply fall-backs so an unconfigured instance
 * reproduces the stock Worktide look.
 */
final readonly class BrandingConfig
{
    public function __construct(
        private string $name,
        private string $legalName,
        private string $logoUrl,
        private string $logoUrlDark,
        private string $primaryColor,
        private string $accentColor,
        private string $imprintUrl,
        private string $privacyUrl,
        private string $supportEmail,
        private string $mailFrom,
        private string $mailFromName,
        private string $defaultUri,
    ) {}

    public function name(): string
    {
        return $this->name !== '' ? $this->name : 'Worktide';
    }

    /** Legal/entity name for the email footer; falls back to the product name. */
    public function legalName(): string
    {
        return $this->legalName !== '' ? $this->legalName : $this->name();
    }

    /**
     * Absolute logo URL. When BRAND_LOGO_URL is unset we point at the backend's
     * own /branding/logo route (bundled or mounted file). Built from DEFAULT_URI
     * because emails render in the async CLI worker — there is no request.
     */
    public function logoUrl(): string
    {
        if ($this->logoUrl !== '') {
            return $this->logoUrl;
        }

        return rtrim($this->defaultUri, '/') . '/branding/logo';
    }

    /** Optional dark-mode logo (frontend only); empty string if unset. */
    public function logoUrlDark(): string
    {
        return $this->logoUrlDark;
    }

    public function primaryColor(): string
    {
        return $this->primaryColor !== '' ? $this->primaryColor : '#0F8C72';
    }

    public function accentColor(): string
    {
        return $this->accentColor !== '' ? $this->accentColor : '#E0623A';
    }

    /** Impressum link; empty string means "no link" (hidden in UI/emails). */
    public function imprintUrl(): string
    {
        return $this->imprintUrl;
    }

    /** Datenschutz link; empty string means "no link". */
    public function privacyUrl(): string
    {
        return $this->privacyUrl;
    }

    /** Support contact for the email footer; falls back to the from-address. */
    public function supportEmail(): string
    {
        return $this->supportEmail !== '' ? $this->supportEmail : $this->mailFrom;
    }

    /** Display name for the mail "From" header; falls back to the product name. */
    public function mailFromName(): string
    {
        return $this->mailFromName !== '' ? $this->mailFromName : $this->name();
    }

    /**
     * JSON shape returned by GET /v1/branding and consumed by the SPAs.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'legalName' => $this->legalName(),
            'logoUrl' => $this->logoUrl(),
            'logoUrlDark' => $this->logoUrlDark(),
            'primaryColor' => $this->primaryColor(),
            'accentColor' => $this->accentColor(),
            'imprintUrl' => $this->imprintUrl(),
            'privacyUrl' => $this->privacyUrl(),
            'supportEmail' => $this->supportEmail(),
        ];
    }
}
