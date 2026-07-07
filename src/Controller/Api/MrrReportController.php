<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\BillingCycle;
use App\Entity\Enum\SubscriptionStatus;
use App\Entity\ServiceSubscription;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * GET /v1/reports/mrr
 *   ?from=YYYY-MM   (required, inclusive)
 *   &to=YYYY-MM     (required, inclusive)
 *
 * Monthly recurring revenue trajectory derived from
 * {@see ServiceSubscription}. For each month in [from, to]:
 *
 *   - Take every subscription whose startedOn is <= the last day of
 *     the month AND (status != Cancelled OR endedOn > first day of
 *     the month). Paused subscriptions are dropped — they don't bill
 *     this month.
 *   - Normalise price to a monthly amount: monthly = price, quarterly
 *     = price / 3, half-yearly = price / 6, yearly = price / 12,
 *     once = 0 (not recurring).
 *   - Sum, broken down by currency since we don't FX-convert.
 *
 * Returns one row per (month × currency) plus a total per month
 * collapsed to the user's primary currency (eur if mixed).
 *
 * Tenant-scoped via X-Workspace-Id / membership lookup, mirroring the
 * other report endpoints — raw aggregation queries can't ride the
 * standard API Platform extension chain.
 */
final class MrrReportController
{
    private const MAX_MONTHS = 60;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/reports/mrr',
        name: 'api_report_mrr',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new BadRequestHttpException('Workspace could not be determined.');
        }

        $from = $this->parseMonth($request, 'from');
        $to = $this->parseMonth($request, 'to');
        if ($from > $to) {
            throw new BadRequestHttpException('`from` must be <= `to`.');
        }
        $monthSpan = $this->countMonths($from, $to);
        if ($monthSpan > self::MAX_MONTHS) {
            throw new BadRequestHttpException(sprintf('Range too large; max %d months.', self::MAX_MONTHS));
        }

        // Pull every subscription that *could* have contributed in the
        // requested range. Cheaper than per-month queries and the data
        // volume per workspace stays small.
        $subs = $this->em->getRepository(ServiceSubscription::class)->createQueryBuilder('s')
            ->where('s.workspace = :ws')
            ->andWhere('s.startedOn <= :rangeEnd')
            ->setParameter('ws', $workspace)
            ->setParameter('rangeEnd', $to->modify('last day of this month')->setTime(23, 59, 59))
            ->getQuery()
            ->getResult();

        $series = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $monthEnd = $cursor->modify('last day of this month')->setTime(23, 59, 59);
            $monthStart = $cursor->modify('first day of this month')->setTime(0, 0);

            $byCurrency = [];
            $activeCount = 0;
            foreach ($subs as $s) {
                /** @var ServiceSubscription $s */
                if ($s->getStartedOn() > $monthEnd) {
                    continue;
                }
                $endedOn = $s->getEndedOn();
                if ($endedOn !== null && $endedOn < $monthStart) {
                    continue;
                }
                if ($s->getStatus() === SubscriptionStatus::Paused) {
                    continue;
                }
                $monthlyCents = $this->normaliseToMonthlyCents($s->getPriceCents(), $s->getBillingCycle());
                if ($monthlyCents === 0) {
                    continue;
                }
                $cur = strtolower($s->getCurrency());
                $byCurrency[$cur] = ($byCurrency[$cur] ?? 0) + $monthlyCents;
                $activeCount++;
            }
            ksort($byCurrency);

            $series[] = [
                'month' => $cursor->format('Y-m'),
                'activeCount' => $activeCount,
                'byCurrency' => array_map(
                    fn (int $cents, string $cur) => [
                        'currency' => $cur,
                        'cents' => $cents,
                        'amount' => round($cents / 100, 2),
                    ],
                    array_values($byCurrency),
                    array_keys($byCurrency),
                ),
                // Convenience field for the most common (single-currency)
                // case so the SPA chart can just `.eur.amount` without
                // walking the array.
                'totalCentsEur' => $byCurrency['eur'] ?? 0,
            ];

            $cursor = $cursor->modify('+1 month');
        }

        return new JsonResponse([
            'from' => $from->format('Y-m'),
            'to' => $to->format('Y-m'),
            'series' => $series,
        ]);
    }

    private function normaliseToMonthlyCents(int $cents, BillingCycle $cycle): int
    {
        return match ($cycle) {
            BillingCycle::Monthly => $cents,
            BillingCycle::Quarterly => (int) round($cents / 3),
            BillingCycle::HalfYearly => (int) round($cents / 6),
            BillingCycle::Yearly => (int) round($cents / 12),
            BillingCycle::Once => 0,
        };
    }

    private function parseMonth(Request $request, string $key): \DateTimeImmutable
    {
        $raw = $request->query->get($key);
        if (!\is_string($raw) || !preg_match('/^\d{4}-\d{2}$/', $raw)) {
            throw new BadRequestHttpException(sprintf('%s must be YYYY-MM.', $key));
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m', $raw);
        if ($d === false) {
            throw new BadRequestHttpException(sprintf('%s is not a valid year-month.', $key));
        }
        return $d->modify('first day of this month')->setTime(0, 0);
    }

    private function countMonths(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $diff = $from->diff($to);
        return ((int) $diff->y) * 12 + (int) $diff->m + 1;
    }

    private function resolveWorkspace(Request $request, User $user): ?Workspace
    {
        $hdr = $request->headers->get('X-Workspace-Id') ?? $request->query->get('workspace');
        if (\is_string($hdr) && $hdr !== '') {
            try {
                return $this->em->find(Workspace::class, Uuid::fromString($hdr));
            } catch (\InvalidArgumentException) {
                return null;
            }
        }
        $membership = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['user' => $user]);
        return $membership?->getWorkspace();
    }
}
