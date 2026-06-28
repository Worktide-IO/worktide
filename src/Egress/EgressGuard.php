<?php

declare(strict_types=1);

namespace App\Egress;

use App\Entity\Channel;

/**
 * Central, default-deny gate for every outbound data path. Each egress call
 * site asks the guard before letting data leave the system; nothing leaves
 * unless its module has been explicitly approved by the operator.
 *
 * Approval lives in config (`EGRESS_ALLOW`), not the database — so a leak can't
 * be caused by a stray UI toggle or a bad row, and the default (empty) is total
 * lockdown. The value is a comma-separated list of tokens:
 *
 *   ""                                  → everything blocked (default)
 *   "llm"                               → LLM allowed (all channels)
 *   "ticket_push:<uuid>"                → ticket push allowed only for that channel
 *   "llm,social_publish,ticket_push:<uuid>"
 *
 * A bare module token approves that module for every channel; a `module:<uuid>`
 * token approves it only for the channel with that id. Unknown/empty tokens are
 * ignored (still blocked).
 */
final class EgressGuard
{
    /**
     * module value → set of allowed channel ids, or ['*'] for all channels.
     *
     * @var array<string, list<string>>
     */
    private array $allow = [];

    public function __construct(?string $allow = null)
    {
        foreach (explode(',', (string) $allow) as $raw) {
            $entry = trim($raw);
            if ($entry === '') {
                continue;
            }
            [$module, $channel] = array_pad(explode(':', $entry, 2), 2, null);
            $module = trim((string) $module);
            if (EgressModule::tryFrom($module) === null) {
                continue; // ignore unknown module tokens — fail closed
            }
            $scope = $channel !== null && trim($channel) !== '' ? trim($channel) : '*';
            $existing = $this->allow[$module] ?? [];
            if ($scope === '*') {
                $this->allow[$module] = ['*'];
            } elseif ($existing !== ['*']) {
                $existing[] = $scope;
                $this->allow[$module] = array_values(array_unique($existing));
            }
        }
    }

    public function isAllowed(EgressModule $module, ?Channel $channel = null): bool
    {
        $scopes = $this->allow[$module->value] ?? [];
        if ($scopes === []) {
            return false;
        }
        if (\in_array('*', $scopes, true)) {
            return true;
        }
        $channelId = $channel?->getId()?->toRfc4122();

        return $channelId !== null && \in_array($channelId, $scopes, true);
    }

    /**
     * @throws EgressBlockedException when the module (for this channel) is not approved
     */
    public function assertAllowed(EgressModule $module, ?Channel $channel = null): void
    {
        if (!$this->isAllowed($module, $channel)) {
            throw new EgressBlockedException($module, $channel?->getId()?->toRfc4122());
        }
    }
}
