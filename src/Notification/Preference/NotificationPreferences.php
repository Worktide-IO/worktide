<?php

declare(strict_types=1);

namespace App\Notification\Preference;

use App\Entity\Enum\NotificationType;

/**
 * A user's notification delivery preferences — the parse/validate + query
 * helper around the free-form JSON stored on {@see \App\Entity\UserPreferences}.
 *
 * Scope: this governs the EMAIL channel only. In-app (the bell) is always on —
 * every resolver-produced notification is persisted regardless — so there is no
 * in-app switch here.
 *
 * `types` defaults every notification type to ON; only an explicit `false`
 * suppresses one. That keeps a stored pref forward-compatible: a type added to
 * the enum later is delivered until the user opts out.
 */
final class NotificationPreferences
{
    public const FREQ_INSTANT = 'instant';
    public const FREQ_DAILY = 'daily';
    public const FREQ_WEEKLY = 'weekly';

    public const FREQUENCIES = [self::FREQ_INSTANT, self::FREQ_DAILY, self::FREQ_WEEKLY];

    /**
     * @param array<string, bool>                    $types      per-type email opt-outs (absent = on)
     * @param array{start: string, end: string}|null $quietHours HH:MM window; suppresses instant email
     */
    private function __construct(
        public readonly bool $email,
        public readonly string $frequency,
        private readonly array $types,
        public readonly ?array $quietHours,
        public readonly bool $chat = false,
        // Debounce window (minutes) for batchable types: after the first such
        // event, collect further ones for this long, then deliver ONE batched
        // email/chat. 0 = no extra wait (sent on the next sweep). Email/chat only.
        public readonly int $delayMinutes = self::DEFAULT_DELAY_MINUTES,
    ) {}

    public const DEFAULT_DELAY_MINUTES = 30;
    public const MAX_DELAY_MINUTES = 1440;

    public static function defaults(): self
    {
        return new self(true, self::FREQ_INSTANT, [], null, false, self::DEFAULT_DELAY_MINUTES);
    }

    /**
     * Build from stored JSON, coercing anything invalid back to the default
     * (never throws — a corrupt row degrades to sensible delivery).
     *
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            return self::defaults();
        }

        $email = (bool) ($data['email'] ?? true);

        $freq = $data['frequency'] ?? null;
        $frequency = \in_array($freq, self::FREQUENCIES, true) ? $freq : self::FREQ_INSTANT;

        $types = [];
        if (isset($data['types']) && \is_array($data['types'])) {
            foreach ($data['types'] as $key => $value) {
                if (\is_string($key)) {
                    $types[$key] = (bool) $value;
                }
            }
        }

        $quietHours = null;
        if (isset($data['quietHours']) && \is_array($data['quietHours'])) {
            $start = $data['quietHours']['start'] ?? null;
            $end = $data['quietHours']['end'] ?? null;
            if (self::isValidTime($start) && self::isValidTime($end)) {
                $quietHours = ['start' => $start, 'end' => $end];
            }
        }

        $chat = (bool) ($data['chat'] ?? false);

        $delayMinutes = self::DEFAULT_DELAY_MINUTES;
        if (isset($data['delayMinutes']) && (\is_int($data['delayMinutes']) || (\is_string($data['delayMinutes']) && ctype_digit($data['delayMinutes'])))) {
            $delayMinutes = max(0, min(self::MAX_DELAY_MINUTES, (int) $data['delayMinutes']));
        }

        return new self($email, $frequency, $types, $quietHours, $chat, $delayMinutes);
    }

    public function typeEnabled(string $type): bool
    {
        return $this->types[$type] ?? true;
    }

    /**
     * Is `$now` inside the quiet-hours window (evaluated in `$tz`)? Handles both
     * same-day (08:00–18:00) and overnight (22:00–07:00) windows.
     */
    public function isWithinQuietHours(\DateTimeImmutable $now, \DateTimeZone $tz): bool
    {
        if ($this->quietHours === null) {
            return false;
        }
        $current = (int) $now->setTimezone($tz)->format('Gi'); // 0..2359
        $start = (int) str_replace(':', '', $this->quietHours['start']);
        $end = (int) str_replace(':', '', $this->quietHours['end']);
        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? ($current >= $start && $current < $end)
            : ($current >= $start || $current < $end);
    }

    /** Should a just-created notification of `$type` be emailed immediately? */
    public function shouldSendInstant(string $type, \DateTimeImmutable $now, \DateTimeZone $tz): bool
    {
        return $this->email
            && $this->frequency === self::FREQ_INSTANT
            && $this->typeEnabled($type)
            && !$this->isWithinQuietHours($now, $tz);
    }

    /**
     * Should a just-created notification of `$type` be pushed to chat now? Chat is
     * instant-only (no digest) and honours the same per-type opt-outs + quiet hours
     * as email.
     */
    public function shouldSendChat(string $type, \DateTimeImmutable $now, \DateTimeZone $tz): bool
    {
        return $this->chat
            && $this->typeEnabled($type)
            && !$this->isWithinQuietHours($now, $tz);
    }

    /** Should a notification of `$type` be rolled into a `$frequency` digest? */
    public function includeInDigest(string $type, string $frequency): bool
    {
        return $this->email
            && $this->frequency === $frequency
            && $this->typeEnabled($type);
    }

    /**
     * Normalised representation for storage + API echo: `types` is expanded to
     * an explicit entry per known {@see NotificationType} so the settings UI can
     * render a stable, complete list of toggles.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $types = [];
        foreach (NotificationType::cases() as $case) {
            $types[$case->value] = $this->typeEnabled($case->value);
        }

        return [
            'email' => $this->email,
            'chat' => $this->chat,
            'frequency' => $this->frequency,
            'delayMinutes' => $this->delayMinutes,
            'types' => $types,
            'quietHours' => $this->quietHours,
        ];
    }

    private static function isValidTime(mixed $value): bool
    {
        return \is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
