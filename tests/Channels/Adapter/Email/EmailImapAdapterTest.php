<?php

declare(strict_types=1);

namespace App\Tests\Channels\Adapter\Email;

use App\Channels\Adapter\Email\EmailImapAdapter;
use App\Repository\ContactRepository;
use App\Repository\InboundEventRepository;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attribute;

/**
 * php-imap 6 parses address headers into an Attribute wrapping Address objects.
 * The adapter used to read `->mail` off the Attribute itself, which silently
 * yielded null — so every inbound mail landed with an empty sender. These pin
 * that the first Address is read correctly and its display name MIME-decoded.
 */
final class EmailImapAdapterTest extends TestCase
{
    private function adapter(): EmailImapAdapter
    {
        return new EmailImapAdapter(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(InboundEventRepository::class),
            $this->createStub(ContactRepository::class),
            new FileStorage($this->createStub(FilesystemOperator::class)),
        );
    }

    private function fromAttr(string $personal, string $mail): Attribute
    {
        return new Attribute('from', new Address((object) ['personal' => $personal, 'mail' => $mail]));
    }

    public function testNameAndAddress(): void
    {
        $raw = $this->adapter()->senderRawFromHeader($this->fromAttr('Sven Wappler', 'sven@wappler.systems'));
        self::assertSame('Sven Wappler <sven@wappler.systems>', $raw);
    }

    public function testBareAddressWhenNoDisplayName(): void
    {
        $raw = $this->adapter()->senderRawFromHeader($this->fromAttr('', 'info@wappler.systems'));
        self::assertSame('info@wappler.systems', $raw);
    }

    public function testMimeEncodedDisplayNameIsDecoded(): void
    {
        $raw = $this->adapter()->senderRawFromHeader(
            $this->fromAttr('=?utf-8?Q?J=C3=B6rg_M=C3=BCller?=', 'joerg@example.com'),
        );
        self::assertSame('Jörg Müller <joerg@example.com>', $raw);
    }

    public function testNullHeaderYieldsNull(): void
    {
        self::assertNull($this->adapter()->senderRawFromHeader(null));
    }

    public function testEmptyAttributeYieldsNull(): void
    {
        self::assertNull($this->adapter()->senderRawFromHeader(new Attribute('from')));
    }
}
