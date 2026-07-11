<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\Channel;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The at-rest cipher listener must not let a partial authConfig update wipe
 * secrets the client didn't resend. Write-only secrets (passwords, tokens) are
 * never sent back to the browser, so the SourceWizard omits them on edit ("leer
 * = unverändert"); a merge-patch then replaces the whole authConfig object.
 * Without the preserve-merge, editing any unrelated field drops the password
 * and IMAP/Jira/Redmine auth starts failing.
 */
final class ChannelAuthConfigCipherListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        parent::tearDown();
    }

    public function testPartialUpdatePreservesOmittedSecret(): void
    {
        $ws = (new Workspace())
            ->setName('WS cipher')
            ->setSlug('ws-cipher-' . substr(bin2hex(random_bytes(4)), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings([]);
        $this->em->persist($ws);

        $channel = (new Channel())
            ->setWorkspace($ws)
            ->setName('Cipher Mailbox')
            ->setAdapterCode('email_imap');
        $channel->setAuthConfig(['username' => 'p181457p14', 'password' => 'super-secret']);
        $this->em->persist($channel);
        $this->em->flush();
        $id = $channel->getId();
        self::assertNotNull($id);

        // Reload fresh so postLoad decrypts and Doctrine's snapshot holds the
        // encrypted value — exactly the state a merge-patch edit runs against.
        $this->em->clear();
        $reloaded = $this->em->find(Channel::class, $id);
        self::assertInstanceOf(Channel::class, $reloaded);
        self::assertSame('super-secret', $reloaded->getAuthConfig()['password'] ?? null);

        // Simulate a merge-patch that replaces authConfig with a partial object
        // (password omitted — the field was left blank in the wizard).
        $reloaded->setAuthConfig(['username' => 'p181457p14-renamed']);
        $this->em->flush();

        $this->em->clear();
        $after = $this->em->find(Channel::class, $id);
        self::assertInstanceOf(Channel::class, $after);
        $auth = $after->getAuthConfig();

        self::assertSame('p181457p14-renamed', $auth['username'] ?? null, 'username update applied');
        self::assertSame('super-secret', $auth['password'] ?? null, 'omitted password preserved, not wiped');
    }

    public function testExplicitNullStillClearsSecret(): void
    {
        $ws = (new Workspace())
            ->setName('WS cipher2')
            ->setSlug('ws-cipher2-' . substr(bin2hex(random_bytes(4)), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings([]);
        $this->em->persist($ws);

        $channel = (new Channel())
            ->setWorkspace($ws)
            ->setName('Cipher Mailbox 2')
            ->setAdapterCode('email_imap');
        $channel->setAuthConfig(['username' => 'u', 'password' => 'p']);
        $this->em->persist($channel);
        $this->em->flush();
        $id = $channel->getId();

        $this->em->clear();
        $reloaded = $this->em->find(Channel::class, $id);
        self::assertInstanceOf(Channel::class, $reloaded);
        // Explicit null is a deliberate clear (omission != null).
        $reloaded->setAuthConfig(['username' => 'u', 'password' => null]);
        $this->em->flush();

        $this->em->clear();
        $after = $this->em->find(Channel::class, $id);
        self::assertInstanceOf(Channel::class, $after);
        // Explicit null clears the secret — the old value must NOT be carried over.
        self::assertNotSame('p', $after->getAuthConfig()['password'] ?? null, 'explicit null clears the secret');
    }
}
