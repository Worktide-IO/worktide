<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer-portal "Rechnungen" tab (wireframe screen 4). Read-only list of the
 * customer's invoices, mirrored from lexoffice by `app:lexoffice:sync-invoices`.
 * Gated by the `invoices` feature flag. PDF download is deferred (lexoffice
 * needs a second /invoices/{id}→/files/{id} hop).
 */
final class PortalInvoicesController
{
    private const STATUS_LABELS = [
        'open' => 'Offen',
        'paid' => 'Bezahlt',
        'voided' => 'Storniert',
    ];

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly InvoiceRepository $invoices,
    ) {}

    #[Route(
        path: '/v1/portal/invoices',
        name: 'api_portal_invoices_list',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('invoices');

        return new JsonResponse([
            'invoices' => array_map(
                $this->invoiceDto(...),
                $this->invoices->findForPortalCustomer($this->portal->customer()),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceDto(Invoice $invoice): array
    {
        $overdue = $invoice->isOverdue();
        $status = $invoice->getStatus()->value;

        return [
            'id' => $invoice->getId()?->toRfc4122(),
            'number' => $invoice->getNumber(),
            'issuedOn' => $invoice->getIssuedOn()->format('Y-m-d'),
            'dueOn' => $invoice->getDueOn()?->format('Y-m-d'),
            'totalCents' => $invoice->getTotalCents(),
            'openCents' => $invoice->getOpenCents(),
            'currency' => $invoice->getCurrency(),
            'status' => $overdue ? 'overdue' : $status,
            'statusLabel' => $overdue ? 'Überfällig' : (self::STATUS_LABELS[$status] ?? $status),
        ];
    }
}
