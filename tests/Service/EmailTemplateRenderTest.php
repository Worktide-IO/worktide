<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Smoke test for the branded system-email templates: verifies they inherit the
 * shared email/base layout and render the `brand` Twig global (logo header,
 * primary-color button, legal footer). Guards against a broken template chain
 * or an unwired branding global.
 */
final class EmailTemplateRenderTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get(Environment::class);
    }

    public function testPasswordResetHtmlIsBranded(): void
    {
        $html = $this->twig->render('email/password_reset.html.twig', [
            'locale' => 'de',
            'resetUrl' => 'https://example.test/reset?token=abc',
            'firstName' => 'Sven',
            'expiresAt' => new \DateTimeImmutable('2030-01-01 10:00'),
        ]);

        // Base layout applied (logo header target) + branded button color.
        self::assertStringContainsString('/branding/logo', $html);
        self::assertStringContainsString('#0F8C72', $html);
        // Content + footer legal entity.
        self::assertStringContainsString('Neues Passwort setzen', $html);
        self::assertStringContainsString('Worktide', $html);
        self::assertStringContainsString('https://example.test/reset?token=abc', $html);
    }

    /**
     * Every flow template reads `locale|default('de')`, so a caller that forgets
     * to pass `locale` renders in German instead of throwing "Variable locale
     * does not exist" (which previously left mails stuck in the failed queue).
     */
    public function testTemplateRendersWithoutLocaleFallsBackToGerman(): void
    {
        $html = $this->twig->render('email/password_reset.html.twig', [
            // deliberately NO 'locale' key
            'resetUrl' => 'https://example.test/reset?token=abc',
            'firstName' => 'Sven',
            'expiresAt' => new \DateTimeImmutable('2030-01-01 10:00'),
        ]);

        self::assertStringContainsString('Neues Passwort setzen', $html);
        self::assertStringNotContainsString('Set a new password', $html);
    }

    public function testPortalInvitationRendersWelcomeText(): void
    {
        $html = $this->twig->render('email/portal_set_password.html.twig', [
            'locale' => 'de',
            'setPasswordUrl' => 'https://portal.test/set?token=xyz',
            'firstName' => 'Sven',
            'expiresAt' => new \DateTimeImmutable('2030-01-01 10:00'),
            'welcomeText' => 'Willkommen bei uns! Wir freuen uns auf die Zusammenarbeit.',
        ]);

        self::assertStringContainsString('Willkommen bei uns!', $html);
        self::assertStringContainsString('Zugang zum Kundenportal', $html);

        // No welcome text → the greeting block is simply omitted.
        $plain = $this->twig->render('email/portal_set_password.html.twig', [
            'locale' => 'de',
            'setPasswordUrl' => 'https://portal.test/set?token=xyz',
            'firstName' => 'Sven',
            'expiresAt' => new \DateTimeImmutable('2030-01-01 10:00'),
            'welcomeText' => null,
        ]);
        self::assertStringNotContainsString('Willkommen bei uns!', $plain);
    }

    public function testPortalSetPasswordTextHasLegalFooter(): void
    {
        $txt = $this->twig->render('email/portal_set_password.txt.twig', [
            'locale' => 'de',
            'setPasswordUrl' => 'https://portal.test/set?token=xyz',
            'firstName' => 'Sven',
            'expiresAt' => new \DateTimeImmutable('2030-01-01 10:00'),
        ]);

        self::assertStringContainsString('https://portal.test/set?token=xyz', $txt);
        // Footer separator + legal entity from the shared base.txt layout.
        self::assertStringContainsString('Worktide', $txt);
    }
}
