<?php

declare(strict_types=1);

namespace App\Automation;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared credential check for the token-authed automation endpoints (the
 * "back into Worktide" side used by n8n). The single shared secret lives in
 * AUTOMATION_API_TOKEN and is presented as X-Worktide-Automation-Token.
 *
 * Empty token in config → the endpoints behave as if they don't exist (404),
 * so a fresh install can't be poked. A present-but-wrong token → 403.
 */
final class AutomationTokenGuard
{
    public function __construct(private readonly string $token) {}

    public function assert(Request $request): void
    {
        $configured = trim($this->token);
        if ($configured === '') {
            throw new NotFoundHttpException();
        }
        $presented = (string) $request->headers->get('X-Worktide-Automation-Token', '');
        if ($presented === '' || !hash_equals($configured, $presented)) {
            throw new AccessDeniedHttpException('Ungültiges Automation-Token.');
        }
    }
}
