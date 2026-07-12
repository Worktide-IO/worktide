<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Newsletter;
use App\Entity\Workspace;
use App\Service\Newsletter\NewsletterMailer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Address;

/**
 * Phase D: the per-workspace sender identity (settings.newsletter.senderName +
 * replyTo) is applied by NewsletterMailer::resolveSender. Exercised directly
 * (reflection) so it needs neither egress nor a real transport — the From address
 * itself always stays the global MAILER_FROM; only the display name is overridden.
 */
final class NewsletterSenderResolutionTest extends KernelTestCase
{
    private function resolve(Newsletter $newsletter): array
    {
        $mailer = self::getContainer()->get(NewsletterMailer::class);
        $method = new \ReflectionMethod($mailer, 'resolveSender');

        return $method->invoke($mailer, $newsletter);
    }

    private function newsletterWith(array $newsletterSettings): Newsletter
    {
        $ws = (new Workspace())->setName('WS')->setSlug('ws')->setLocale('de')->setTimezone('Europe/Berlin')
            ->setSettings($newsletterSettings === [] ? [] : ['newsletter' => $newsletterSettings]);
        $node = (new Newsletter())->setTitle('N');
        $node->setWorkspace($ws);

        return $node;
    }

    public function testWorkspaceSenderNameAndReplyToApplied(): void
    {
        [$from, $replyTo] = $this->resolve($this->newsletterWith([
            'senderName' => 'Acme Newsletter',
            'replyTo' => 'team@acme.example',
        ]));

        self::assertInstanceOf(Address::class, $from);
        self::assertSame('Acme Newsletter', $from->getName());
        self::assertInstanceOf(Address::class, $replyTo);
        self::assertSame('team@acme.example', $replyTo->getAddress());
    }

    public function testDefaultsWhenUnset(): void
    {
        [$from, $replyTo] = $this->resolve($this->newsletterWith([]));

        self::assertInstanceOf(Address::class, $from);
        self::assertNotSame('', $from->getName(), 'falls back to a default display name');
        self::assertNull($replyTo);
    }

    public function testInvalidReplyToIsIgnored(): void
    {
        [, $replyTo] = $this->resolve($this->newsletterWith(['replyTo' => 'not-an-email']));

        self::assertNull($replyTo);
    }
}
