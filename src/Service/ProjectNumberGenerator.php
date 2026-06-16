<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generates the next project number according to the active
 * Workspace.settings.projectNumber.pattern template.
 *
 * Supported placeholders:
 *
 *   {YEAR}             — 4-digit year of "now" (UTC).
 *   {YEAR2}            — 2-digit year.
 *   {MONTH}            — 2-digit month (zero-padded).
 *   {SEQ}              — Decimal counter, scoped to (workspace, year).
 *                        Width is configurable: `{SEQ:3}` → 001 / 002 /
 *                        … / 999. Without width it's just the raw int.
 *   {CUSTOMER_KEY}     — Uppercase short-name of the project's customer.
 *                        Falls back to "NOCUST" if the project has no
 *                        customer. Useful in agency setups.
 *
 * The sequence is *derived* on demand by scanning existing
 * Project.number values that match the same prefix — no separate
 * counter table. Trade-off: O(scan) per call, but the scan is bounded
 * by workspace-year and Worktide stays out of state-machine debt.
 *
 * Pattern is `null` by default (no auto-fill). Workspace admins set it
 * in the Sicherheit/Projektnummer card under /settings/workspace.
 */
final class ProjectNumberGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function generate(Workspace $workspace, ?Customer $customer = null): ?string
    {
        $pattern = $this->resolvePattern($workspace);
        if ($pattern === null) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $bindings = [
            '{YEAR}' => $now->format('Y'),
            '{YEAR2}' => $now->format('y'),
            '{MONTH}' => $now->format('m'),
            '{CUSTOMER_KEY}' => $customer !== null
                ? $this->customerShortName($customer)
                : 'NOCUST',
        ];

        // First substitute non-SEQ placeholders, then ask the DB for the
        // next sequence using the rendered prefix as a LIKE filter.
        $rendered = strtr($pattern, $bindings);

        return preg_replace_callback(
            '/\{SEQ(?::(\d+))?\}/',
            function (array $m) use ($workspace, $rendered) {
                $width = isset($m[1]) ? (int) $m[1] : 0;
                $prefix = substr($rendered, 0, strpos($rendered, $m[0]) ?: 0);
                $next = $this->nextSequence($workspace, $prefix, $width);
                return $width > 0 ? str_pad((string) $next, $width, '0', STR_PAD_LEFT) : (string) $next;
            },
            $rendered,
        ) ?? $rendered;
    }

    private function resolvePattern(Workspace $workspace): ?string
    {
        $settings = $workspace->getSettings();
        if (!is_array($settings)) {
            return null;
        }
        $raw = $settings['projectNumber']['pattern'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        return $raw;
    }

    private function customerShortName(Customer $customer): string
    {
        $name = trim($customer->getName());
        if ($name === '') {
            return 'CUST';
        }
        // First word, uppercase, alpha-only, truncated to 12 chars. Keeps
        // patterns like INTEWA-2026-01 looking deliberate instead of
        // dragging full "Inteva Pumpensysteme GmbH" into every number.
        $first = strtoupper(preg_replace('/[^a-zA-Z]/', '', explode(' ', $name)[0]) ?? '');
        return substr($first, 0, 12) ?: 'CUST';
    }

    private function nextSequence(Workspace $workspace, string $prefix, int $width): int
    {
        // The widest match the SEQ can produce drives how many trailing
        // chars we slice off the existing numbers. Fall back to a sane
        // upper bound (32 — Project.number column length) when no width.
        $maxWidth = $width > 0 ? $width : 32;

        $rows = $this->em->createQueryBuilder()
            ->select('p.number')
            ->from(Project::class, 'p')
            ->andWhere('p.workspace = :ws')
            ->andWhere('p.number LIKE :prefix')
            ->setParameter('ws', $workspace->getId(), 'uuid')
            ->setParameter('prefix', $prefix . '%')
            ->getQuery()
            ->getArrayResult();

        $max = 0;
        foreach ($rows as $row) {
            $number = $row['number'] ?? null;
            if (!is_string($number)) {
                continue;
            }
            $tail = substr($number, strlen($prefix), $maxWidth);
            // Strip trailing non-digits — patterns may end with hyphens
            // or suffixes after SEQ, those don't break the lookahead.
            if (preg_match('/^(\d+)/', $tail, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $max + 1;
    }
}
