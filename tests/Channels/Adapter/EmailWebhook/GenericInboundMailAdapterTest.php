<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\EmailWebhook;

use App\Channels\Adapter\EmailWebhook\GenericInboundMailAdapter;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Entity\Workspace;
use App\Repository\ContactRepository;
use App\Repository\InboundEventRepository;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class GenericInboundMailAdapterTest extends TestCase
{
    private const MIME = "From: Alice <alice@example.com>\r\nTo: support@firma.de\r\n"
        . "Subject: Angebot?\r\nMessage-ID: <root-123@example.com>\r\n"
        . "In-Reply-To: <prev-9@example.com>\r\nDate: Sat, 19 Jul 2026 14:00:00 +0200\r\n\r\n"
        . "Hallo, koennen Sie ein Angebot schicken?\r\n";

    public function testParsesRawMimeBody(): void
    {
        $result = $this->adapter()->consumeWebhook(
            $this->channel(),
            $this->request(self::MIME, 'message/rfc822'),
        );

        self::assertCount(1, $result->events);
        $e = $result->events[0];
        self::assertSame('root-123@example.com', $e->getExternalId());
        self::assertSame('Angebot?', $e->getSubject());
        self::assertStringContainsString('alice@example.com', (string) $e->getSenderRaw());
        self::assertStringContainsString('Angebot schicken', $e->getBody());
        // Threading headers the MailThreader relies on.
        $headers = $e->getSourceMetadata()['headers'];
        self::assertSame('root-123@example.com', $headers['Message-ID']);
        self::assertSame('prev-9@example.com', $headers['In-Reply-To']);
    }

    public function testParsesPostalBase64Envelope(): void
    {
        $body = json_encode(['message' => base64_encode(self::MIME), 'base64' => true, 'mail_from' => 'alice@example.com']);
        $result = $this->adapter()->consumeWebhook($this->channel(), $this->request((string) $body, 'application/json'));

        self::assertCount(1, $result->events);
        self::assertSame('root-123@example.com', $result->events[0]->getExternalId());
    }

    public function testParsesGenericJsonFields(): void
    {
        $body = json_encode(['from' => 'Bob <bob@example.com>', 'subject' => 'Frage', 'text' => 'Kurze Frage.']);
        $result = $this->adapter()->consumeWebhook($this->channel(), $this->request((string) $body, 'application/json'));

        self::assertCount(1, $result->events);
        $e = $result->events[0];
        self::assertSame('Frage', $e->getSubject());
        self::assertStringStartsWith('sha256:', $e->getExternalId()); // synthesized, stable for retries
        self::assertStringContainsString('bob@example.com', (string) $e->getSenderRaw());
    }

    public function testRejectsUnknownPayload(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->adapter()->consumeWebhook($this->channel(), $this->request('{"foo":"bar"}', 'application/json'));
    }

    public function testDedupReturnsEmpty(): void
    {
        $events = $this->createStub(InboundEventRepository::class);
        $events->method('findByExternalId')->willReturn(new InboundEvent()); // already seen
        $result = $this->adapter($events)->consumeWebhook($this->channel(), $this->request(self::MIME, 'message/rfc822'));

        self::assertCount(0, $result->events);
    }

    public function testValidHmacSignaturePasses(): void
    {
        $body = self::MIME;
        $sig = hash_hmac('sha256', $body, 'topsecret');
        $channel = $this->channel()->setAuthConfig(['signingSecret' => 'topsecret']);
        $result = $this->adapter()->consumeWebhook($channel, $this->request($body, 'message/rfc822', ['HTTP_X_SIGNATURE' => 'sha256=' . $sig]));

        self::assertCount(1, $result->events);
    }

    public function testInvalidHmacSignatureRejected(): void
    {
        $channel = $this->channel()->setAuthConfig(['signingSecret' => 'topsecret']);
        $this->expectException(AccessDeniedHttpException::class);
        $this->adapter()->consumeWebhook($channel, $this->request(self::MIME, 'message/rfc822', ['HTTP_X_SIGNATURE' => 'sha256=deadbeef']));
    }

    // ---- helpers ----

    private function adapter(?InboundEventRepository $events = null): GenericInboundMailAdapter
    {
        $events ??= $this->createStub(InboundEventRepository::class);
        $events->method('findByExternalId')->willReturn(null);
        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findOneByWorkspaceAndEmail')->willReturn(null);

        return new GenericInboundMailAdapter(
            $this->createStub(EntityManagerInterface::class),
            $events,
            $contacts,
            new FileStorage($this->createStub(FilesystemOperator::class)),
        );
    }

    private function channel(): Channel
    {
        return (new Channel())
            ->setName('webhook')
            ->setAdapterCode(GenericInboundMailAdapter::CODE)
            ->setWorkspace(new Workspace());
    }

    /** @param array<string, string> $server */
    private function request(string $content, string $contentType, array $server = []): Request
    {
        return Request::create('/v1/inbound/webhooks/tok', 'POST', [], [], [], ['CONTENT_TYPE' => $contentType] + $server, $content);
    }
}
