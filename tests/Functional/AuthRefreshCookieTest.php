<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * M1: the refresh token is delivered as an httpOnly cookie, never in the JSON
 * body; refresh reads it from the cookie; logout clears it. Requests run with
 * HTTPS on so BrowserKit resends the `secure` cookie across requests.
 */
final class AuthRefreshCookieTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTPS', 'on');
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail('cookie.user@example.test')->setFirstName('C')->setLastName('U')->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, 's3cret-pass'));
        $this->em->persist($user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        parent::tearDown();
    }

    public function testLoginSetsHttpOnlyCookieAndOmitsBodyToken(): void
    {
        $this->login();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($body['token']);
        self::assertArrayNotHasKey('refresh_token', $body, 'refresh token must not be in the body');

        $cookie = $this->refreshCookie();
        self::assertNotNull($cookie, 'login must set a refresh_token cookie');
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
    }

    public function testRefreshReadsTheCookieWithNoBodyToken(): void
    {
        $this->login();
        // BrowserKit resends the stored cookie; empty body.
        $this->client->request('POST', '/v1/auth/refresh', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($body['token']);
    }

    public function testLogoutClearsCookieAndRevokes(): void
    {
        $token = $this->login();

        $this->client->request('POST', '/v1/auth/logout', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], '{}');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // The cookie is expired (cleared): clearCookie() sets a past expiry.
        $cleared = $this->refreshCookie();
        self::assertNotNull($cleared, 'logout must emit a clearing Set-Cookie');
        self::assertLessThan(time(), $cleared->getExpiresTime());

        // Refresh now fails (cookie gone + token revoked).
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/v1/auth/refresh', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    private function login(): string
    {
        $this->client->request('POST', '/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'cookie.user@example.test',
            'password' => 's3cret-pass',
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR)['token'];
    }

    private function refreshCookie(): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $c) {
            if ($c->getName() === 'refresh_token') {
                return $c;
            }
        }

        return null;
    }
}
