<?php

declare(strict_types=1);

namespace App\Services\Creative;

/**
 * GO-5.1 — the ALLOWLIST BOUNDARY (master plan §8: "inputs strictly: confirmed
 * brand_style, proven-hook tags + their Verified benchmarks, product facts
 * (name/price/stock), moment context"). This value object is the ONLY thing a
 * CreativeGenerator sees, and `toLlmPayload()` rebuilds a scrubbed copy
 * containing ONLY the whitelisted keys — the exact discipline PlanNarrator uses
 * for plans.
 *
 * Nothing that isn't set through this constructor can reach a model. A future
 * field can only widen what the LLM sees by being added to the whitelist here,
 * which the allowlist test guards — so a customer email or a brand_id sitting on
 * a product row can never leak into a prompt.
 */
final class CreativeBrief
{
    /**
     * @param array<int, string> $toneWords          confirmed style voice descriptors
     * @param array<int, string> $paletteHex          confirmed style colours (hex only)
     * @param array{do: array<int,string>, dont: array<int,string>} $doDont
     * @param array<int, array{name: string, type: ?string, stock: ?int}> $products  product FACTS only
     * @param array<int, array{tag: string, medianRoas: ?float, medianCtr: ?float}> $hookBenchmarks  Verified
     */
    public function __construct(
        public readonly string $brandName,
        public readonly array $toneWords,
        public readonly array $paletteHex,
        public readonly array $doDont,
        public readonly array $products,
        public readonly array $hookBenchmarks,
        public readonly ?string $momentLabel,
        public readonly string $currency,
    ) {
    }

    /**
     * The scrubbed payload the model is allowed to see — a strict whitelist.
     * Every value here is either confirmed style, public product facts, the
     * moment label, or Verified aggregate hook benchmarks. No identifiers, no
     * customer data, no internal columns.
     *
     * @return array<string, mixed>
     */
    public function toLlmPayload(): array
    {
        return [
            'brand'    => $this->brandName,
            'tone'     => array_values(array_map('strval', $this->toneWords)),
            'palette'  => array_values(array_map('strval', $this->paletteHex)),
            'do'       => array_values(array_map('strval', $this->doDont['do'] ?? [])),
            'dont'     => array_values(array_map('strval', $this->doDont['dont'] ?? [])),
            'products' => array_values(array_map(static fn (array $p): array => [
                // name / type / stock ONLY — never an id or any other column.
                'name'  => (string) ($p['name'] ?? ''),
                'type'  => isset($p['type']) && $p['type'] !== null ? (string) $p['type'] : null,
                'stock' => isset($p['stock']) && $p['stock'] !== null ? (int) $p['stock'] : null,
            ], $this->products)),
            'provenHooks' => array_values(array_map(static fn (array $h): array => [
                'tag'        => (string) ($h['tag'] ?? ''),
                'medianRoas' => isset($h['medianRoas']) && $h['medianRoas'] !== null ? (float) $h['medianRoas'] : null,
                'medianCtr'  => isset($h['medianCtr']) && $h['medianCtr'] !== null ? (float) $h['medianCtr'] : null,
            ], $this->hookBenchmarks)),
            'moment'   => $this->momentLabel,
            'currency' => $this->currency,
        ];
    }
}
