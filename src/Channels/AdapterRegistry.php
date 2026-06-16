<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Looks up concrete adapters by Channel.adapterCode. The DI
 * container injects two `!tagged_iterator` collections (one for
 * inbound, one for outbound); the registry indexes them by code
 * on construction.
 *
 * Optional ConversationThreader instances are injected the same
 * way but are keyed per-adapter-code (not all adapters thread).
 *
 * Tag conventions in services.yaml:
 *
 *   _instanceof:
 *     App\Channels\InboundAdapter:
 *       tags: [{ name: 'worktide.channel.inbound' }]
 *     App\Channels\OutboundAdapter:
 *       tags: [{ name: 'worktide.channel.outbound' }]
 *     App\Channels\ConversationThreader:
 *       tags: [{ name: 'worktide.channel.threader' }]
 *
 * (declared in config/services.yaml in C.3 when the first concrete
 * adapter ships).
 */
final class AdapterRegistry
{
    /** @var array<string, InboundAdapter> */
    private array $inboundByCode = [];

    /** @var array<string, OutboundAdapter> */
    private array $outboundByCode = [];

    /** @var array<string, ConversationThreader> */
    private array $threaderByCode = [];

    /**
     * @param iterable<InboundAdapter>        $inbound
     * @param iterable<OutboundAdapter>       $outbound
     * @param iterable<ConversationThreader>  $threaders
     * @param array<string, string>           $threaderCodeMap  adapterCode → threader-service-id (resolved via $threaders iterator)
     */
    public function __construct(
        iterable $inbound,
        iterable $outbound,
        iterable $threaders,
        array $threaderCodeMap = [],
    ) {
        foreach ($inbound as $a) {
            $this->inboundByCode[$a->getCode()] = $a;
        }
        foreach ($outbound as $a) {
            $this->outboundByCode[$a->getCode()] = $a;
        }
        // Threaders don't have a getCode() of their own (one threader can
        // serve multiple adapters); the map is configured in services.yaml.
        $threaderList = [];
        foreach ($threaders as $t) {
            $threaderList[] = $t;
        }
        foreach ($threaderCodeMap as $code => $idx) {
            if (isset($threaderList[$idx])) {
                $this->threaderByCode[$code] = $threaderList[$idx];
            }
        }
    }

    public function getInbound(string $code): InboundAdapter
    {
        return $this->inboundByCode[$code]
            ?? throw new UnknownAdapterException("No inbound adapter for code '$code'.");
    }

    public function tryInbound(string $code): ?InboundAdapter
    {
        return $this->inboundByCode[$code] ?? null;
    }

    public function getOutbound(string $code): OutboundAdapter
    {
        return $this->outboundByCode[$code]
            ?? throw new UnknownAdapterException("No outbound adapter for code '$code'.");
    }

    public function tryOutbound(string $code): ?OutboundAdapter
    {
        return $this->outboundByCode[$code] ?? null;
    }

    public function getThreader(string $code): ?ConversationThreader
    {
        return $this->threaderByCode[$code] ?? null;
    }

    /**
     * @return list<string>  every adapter code we know of (inbound ∪ outbound)
     */
    public function knownCodes(): array
    {
        $all = array_unique(array_merge(
            array_keys($this->inboundByCode),
            array_keys($this->outboundByCode),
        ));
        sort($all);
        return array_values($all);
    }
}
