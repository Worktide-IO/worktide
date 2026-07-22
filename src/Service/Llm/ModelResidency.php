<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * Where a model processes prompt data — the basis for the GDPR/DSGVO signal the
 * UI shows per model. Deliberately a data-processing posture, NOT a hard
 * "GDPR compliant yes/no" verdict (that depends on the customer's DPA + config):
 *
 *  - Local: on-infra inference, the prompt never leaves the infrastructure —
 *    the strongest data-residency position.
 *  - Eu: EU/CH-hosted, prompts stay in Europe and aren't used for training
 *    (e.g. Infomaniak, FADP/GDPR) — usable without a cross-border transfer.
 *  - Us: processed in the US by default — usable only under a DPA/SCCs; an EU
 *    region may be available via Bedrock/Vertex (out of scope for the direct API).
 */
enum ModelResidency: string
{
    case Local = 'local';
    case Eu = 'eu';
    case Us = 'us';

    /** Whether prompt data stays within the EU/EEA (or on-infra) without a transfer. */
    public function staysInEu(): bool
    {
        return $this !== self::Us;
    }
}
