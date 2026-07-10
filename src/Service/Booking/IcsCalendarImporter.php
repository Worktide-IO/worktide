<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\CalendarBusyBlock;
use App\Entity\StaffCalendarConnection;
use App\Http\OutboundUrlGuard;
use App\Repository\CalendarBusyBlockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Imports a staff member's ICS calendar feed into {@see CalendarBusyBlock}s so
 * the booking slot engine can subtract their real busy time (free/busy sync,
 * v1 — one-way, poll-based). A future upgrade swaps the ICS fetch for a
 * Google/Outlook OAuth FreeBusy call, keeping the same busy-block target.
 *
 * Fetch is SSRF-guarded (operator-supplied URL) and behind the default-deny
 * {@see EgressModule::CalendarSync} egress gate. Parsing is intentionally modest:
 * single VEVENTs with DTSTART/DTEND (UTC `Z`, `TZID=`, or all-day `VALUE=DATE`)
 * and DURATION. Recurring events (RRULE) are NOT expanded in v1 — only the base
 * occurrence is imported.
 */
final class IcsCalendarImporter
{
    private const SOURCE = 'ics';
    /** Import window: don't hoard years of history/future. */
    private const PAST_DAYS = 1;
    private const FUTURE_DAYS = 120;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly OutboundUrlGuard $urlGuard,
        private readonly EgressGuard $egress,
        private readonly EntityManagerInterface $em,
        private readonly CalendarBusyBlockRepository $busyBlocks,
    ) {}

    /**
     * Fetch + reimport one connection's busy blocks. Throws on fetch/parse
     * failure (the command records it as lastError).
     *
     * @return int number of busy blocks imported
     */
    public function syncConnection(StaffCalendarConnection $connection): int
    {
        if (!$this->egress->isAllowed(EgressModule::CalendarSync)) {
            throw new \RuntimeException('Calendar sync egress not allowed (EGRESS_ALLOW).');
        }

        $url = $connection->icsUrl();
        $this->urlGuard->assertPublicHttpUrl($url); // SSRF guard — operator-supplied URL

        $response = $this->http->request('GET', $url, [
            'timeout' => 15,
            'max_duration' => 20,
            'headers' => ['Accept' => 'text/calendar, text/plain, */*'],
        ]);
        $body = $response->getContent(); // throws on non-2xx

        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        $windowStart = $now->modify('-' . self::PAST_DAYS . ' days');
        $windowEnd = $now->modify('+' . self::FUTURE_DAYS . ' days');

        $owner = $connection->getOwner();
        $workspace = $connection->getWorkspace();

        // Replace-all for this owner's ICS blocks (simplest correct re-sync).
        $this->busyBlocks->deleteForOwnerSource($owner, self::SOURCE);

        $count = 0;
        foreach ($this->parseIcs($body) as $event) {
            [$start, $end, $uid] = [$event['start'], $event['end'], $event['uid']];
            if ($end <= $windowStart || $start >= $windowEnd) {
                continue; // outside the import window
            }
            $block = (new CalendarBusyBlock())
                ->setOwner($owner)
                ->setStartAt($start)
                ->setEndAt($end)
                ->setExternalUid($uid)
                ->setSource(self::SOURCE);
            $block->setWorkspace($workspace);
            $this->em->persist($block);
            ++$count;
        }

        $connection->setLastSyncedAt($now)->setLastError(null);
        $this->em->flush();

        return $count;
    }

    /**
     * Parse VEVENTs into busy intervals. Public so it can be unit-tested with a
     * fixture string without a live fetch.
     *
     * @return list<array{start: \DateTimeImmutable, end: \DateTimeImmutable, uid: ?string}>
     */
    public function parseIcs(string $ics): array
    {
        // Unfold RFC 5545 continuation lines (CRLF/LF + space/tab).
        $unfolded = preg_replace("/\r?\n[ \t]/", '', $ics) ?? $ics;
        $lines = preg_split("/\r?\n/", $unfolded) ?: [];

        $out = [];
        $inEvent = false;
        $cur = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === 'BEGIN:VEVENT') {
                $inEvent = true;
                $cur = [];
                continue;
            }
            if ($trimmed === 'END:VEVENT') {
                $inEvent = false;
                $parsed = $this->eventToBusy($cur);
                if ($parsed !== null) {
                    $out[] = $parsed;
                }
                continue;
            }
            if (!$inEvent) {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $left = substr($line, 0, $colon);   // NAME;PARAM=…
            $value = substr($line, $colon + 1);
            $parts = explode(';', $left);
            $name = strtoupper($parts[0]);
            $params = [];
            foreach (\array_slice($parts, 1) as $p) {
                if (str_contains($p, '=')) {
                    [$k, $v] = explode('=', $p, 2);
                    $params[strtoupper($k)] = $v;
                }
            }
            $cur[$name] = ['value' => $value, 'params' => $params];
        }

        return $out;
    }

    /**
     * @param array<string, array{value: string, params: array<string, string>}> $ev
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable, uid: ?string}|null
     */
    private function eventToBusy(array $ev): ?array
    {
        // Skip cancelled / free (transparent) events — they don't block time.
        if (strtoupper($ev['STATUS']['value'] ?? '') === 'CANCELLED') {
            return null;
        }
        if (strtoupper($ev['TRANSP']['value'] ?? '') === 'TRANSPARENT') {
            return null;
        }
        if (!isset($ev['DTSTART'])) {
            return null;
        }

        $start = $this->parseDateTime($ev['DTSTART']['value'], $ev['DTSTART']['params']);
        if ($start === null) {
            return null;
        }

        $end = null;
        if (isset($ev['DTEND'])) {
            $end = $this->parseDateTime($ev['DTEND']['value'], $ev['DTEND']['params']);
        } elseif (isset($ev['DURATION'])) {
            $end = $this->applyDuration($start, $ev['DURATION']['value']);
        }
        if ($end === null) {
            // All-day with no end → whole day; otherwise skip (no span to block).
            $isDate = strtoupper($ev['DTSTART']['params']['VALUE'] ?? '') === 'DATE';
            $end = $isDate ? $start->modify('+1 day') : null;
        }
        if ($end === null || $end <= $start) {
            return null;
        }

        return ['start' => $start, 'end' => $end, 'uid' => $ev['UID']['value'] ?? null];
    }

    /**
     * @param array<string, string> $params
     */
    private function parseDateTime(string $value, array $params): ?\DateTimeImmutable
    {
        $value = trim($value);
        try {
            // All-day date: YYYYMMDD
            if (strtoupper($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $value)) {
                return new \DateTimeImmutable(substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2) . ' 00:00:00', new \DateTimeZone(date_default_timezone_get()));
            }
            // UTC: 20260711T080000Z
            if (str_ends_with($value, 'Z')) {
                return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            }
            // TZID param, else floating → app default tz.
            $tzId = $params['TZID'] ?? date_default_timezone_get();
            $tz = \in_array($tzId, timezone_identifiers_list(), true) ? new \DateTimeZone($tzId) : new \DateTimeZone(date_default_timezone_get());

            return new \DateTimeImmutable($value, $tz);
        } catch (\Exception) {
            return null;
        }
    }

    private function applyDuration(\DateTimeImmutable $start, string $duration): ?\DateTimeImmutable
    {
        try {
            $neg = str_starts_with($duration, '-');
            $spec = ltrim($duration, '+-');
            $interval = new \DateInterval($spec); // e.g. PT1H30M, P1D

            return $neg ? $start->sub($interval) : $start->add($interval);
        } catch (\Exception) {
            return null;
        }
    }
}
