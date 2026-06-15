<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Pragmatic password-strength check.
 *
 *   - At least 10 characters
 *   - At least three of the four character classes
 *     (lowercase / uppercase / digit / symbol)
 *   - Not in a small hard-coded blocklist of the most-common passwords
 *
 * Returns an array of failed-rule keys (empty array = OK). The caller
 * surfaces them as 422 form errors so the SPA can render checkmarks
 * next to each rule.
 *
 * Pulling in zxcvbn would yield a smarter score, but adds a dependency
 * + dictionary file with no Composer-managed updates and very little
 * extra security value over these four rules. We can swap later if
 * NIST-style "haveibeenpwned" lookups land.
 */
final class PasswordPolicy
{
    private const MIN_LENGTH = 10;
    private const MIN_CHARACTER_CLASSES = 3;

    /**
     * Top of the rockyou-style "most-cracked-passwords" lists. Kept
     * intentionally short — a real attacker just types the user's
     * email-prefix and 123456 anyway, so the long tail matters less
     * than the top dozen.
     *
     * @var list<string>
     */
    private const BLOCKLIST = [
        'password', 'password1', 'passwort', 'qwerty', 'qwertz', 'qwerty123',
        '123456', '12345678', '123456789', '1234567890', 'iloveyou',
        'admin', 'admin123', 'administrator', 'welcome', 'welcome123',
        'letmein', 'monkey', 'dragon', 'shadow', 'master', 'sunshine',
        'football', 'baseball', 'changeme', 'changeme123', 'secret',
        'starwars', 'whatever', 'trustno1', 'azerty',
    ];

    /**
     * @return list<string> failing rule keys; empty when the password
     *                     satisfies the policy
     */
    public function violations(string $password): array
    {
        $errors = [];
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $errors[] = 'too_short';
        }
        if ($this->countClasses($password) < self::MIN_CHARACTER_CLASSES) {
            $errors[] = 'too_few_classes';
        }
        if (in_array(mb_strtolower($password), self::BLOCKLIST, true)) {
            $errors[] = 'blocklisted';
        }
        return $errors;
    }

    public function minLength(): int
    {
        return self::MIN_LENGTH;
    }

    public function minClasses(): int
    {
        return self::MIN_CHARACTER_CLASSES;
    }

    private function countClasses(string $password): int
    {
        $classes = 0;
        if (preg_match('/[a-z]/', $password)) $classes++;
        if (preg_match('/[A-Z]/', $password)) $classes++;
        if (preg_match('/[0-9]/', $password)) $classes++;
        if (preg_match('/[^A-Za-z0-9]/', $password)) $classes++;
        return $classes;
    }
}
