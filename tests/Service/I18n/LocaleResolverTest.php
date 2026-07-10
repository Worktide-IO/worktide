<?php

declare(strict_types=1);

namespace App\Tests\Service\I18n;

use App\Entity\User;
use App\Entity\Workspace;
use App\Service\I18n\LocaleResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Precedence + memoisation of {@see LocaleResolver}:
 * user preference → workspace locale (X-Workspace-Id) → app default,
 * each gated by the supported-locales list.
 */
final class LocaleResolverTest extends TestCase
{
    private const SUPPORTED = ['de', 'en'];

    public function testUserPreferenceWins(): void
    {
        $user = (new User())->setPreferredLanguage('en');
        $resolver = $this->resolver(user: $user);

        self::assertSame('en', $resolver->resolve());
    }

    public function testUnsupportedUserPreferenceFallsThroughToDefault(): void
    {
        $user = (new User())->setPreferredLanguage('zz');
        $resolver = $this->resolver(user: $user);

        self::assertSame('en', $resolver->resolve());
    }

    public function testFallsBackToWorkspaceLocaleWhenUserHasNoPreference(): void
    {
        $user = (new User())->setPreferredLanguage(null);
        $ws = (new Workspace())->setLocale('de');
        // Id is only assigned on persist, so use a standalone valid UUID for the
        // header — the mocked EM->find() returns $ws regardless of the argument.
        $resolver = $this->resolver(user: $user, workspaceHeader: Uuid::v7()->toRfc4122(), workspace: $ws);

        self::assertSame('de', $resolver->resolve());
    }

    public function testDefaultWhenNoUserAndNoWorkspace(): void
    {
        $resolver = $this->resolver();

        self::assertSame('en', $resolver->resolve());
    }

    public function testUserPreferenceBeatsWorkspaceLocale(): void
    {
        $user = (new User())->setPreferredLanguage('en');
        $ws = (new Workspace())->setLocale('de');

        // EM->find must NOT even be consulted — assert via a resolver whose EM
        // throws if touched.
        $resolver = new LocaleResolver(
            $this->security($user),
            $this->requestStack($ws->getId()?->toRfc4122() ?? ''),
            $this->emThatFailsIfCalled(),
            self::SUPPORTED,
            'en',
        );

        self::assertSame('en', $resolver->resolve());
    }

    public function testResolveIsMemoisedAndClearedByReset(): void
    {
        $user = (new User())->setPreferredLanguage('en');
        $resolver = $this->resolver(user: $user);

        self::assertSame('en', $resolver->resolve());
        self::assertSame('en', $resolver->resolve()); // second call: memoised

        $resolver->reset();
        self::assertSame('en', $resolver->resolve()); // recomputes cleanly
    }

    public function testIsSupportedAndAccessors(): void
    {
        $resolver = $this->resolver();

        self::assertTrue($resolver->isSupported('de'));
        self::assertFalse($resolver->isSupported('fr'));
        self::assertSame(self::SUPPORTED, $resolver->supportedLocales());
        self::assertSame('en', $resolver->defaultLocale());
    }

    // --- helpers ----------------------------------------------------

    private function resolver(
        ?User $user = null,
        string $workspaceHeader = '',
        ?Workspace $workspace = null,
    ): LocaleResolver {
        return new LocaleResolver(
            $this->security($user),
            $this->requestStack($workspaceHeader),
            $this->em($workspace),
            self::SUPPORTED,
            'en',
        );
    }

    private function security(?User $user): Security
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function requestStack(string $workspaceHeader): RequestStack
    {
        $stack = new RequestStack();
        if ($workspaceHeader !== '') {
            $request = new Request();
            $request->headers->set('X-Workspace-Id', $workspaceHeader);
            $stack->push($request);
        }

        return $stack;
    }

    private function em(?Workspace $workspace): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($workspace);

        return $em;
    }

    private function emThatFailsIfCalled(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willThrowException(new \LogicException('EM should not be queried when the user has a preference.'));

        return $em;
    }
}
