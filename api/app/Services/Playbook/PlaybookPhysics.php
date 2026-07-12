<?php

declare(strict_types=1);

namespace App\Services\Playbook;

use InvalidArgumentException;

/**
 * The playbook physics (GO-4.2, master plan §7.2 / upgrade U3) — the sourced constants
 * that make a seasonal plan a strategist's plan instead of a horoscope.
 *
 * ══ NO NUMBER LEAVES HERE WITHOUT ITS SOURCE ══
 * `value()` gives the figure. `cite()` gives the figure WITH its provenance, and it is
 * what GO-4.3 puts in a client plan. There is deliberately no way to obtain a physics
 * constant that has no source: `get()` throws if a constant is missing a source string,
 * so a future edit that adds an unsourced number fails loudly instead of quietly shipping
 * an unfootnoted claim into someone's Black Friday deck.
 *
 * An unfootnoted number in a client plan is indistinguishable from an invented one. That
 * is precisely the "generic advice" problem that cost every incumbent its credibility —
 * so the citation is not decoration, it is the product.
 */
class PlaybookPhysics
{
    /**
     * The full constant: value, unit, label, source.
     *
     * @return array{value: mixed, unit: string, label: string, source: string}
     */
    public function get(string $key): array
    {
        /** @var array<string, mixed>|null $c */
        $c = config('playbooks.physics.' . $key);

        if (! is_array($c) || ! array_key_exists('value', $c)) {
            throw new InvalidArgumentException("Unknown playbook constant: {$key}");
        }

        // A constant without provenance must never reach a client plan.
        if (trim((string) ($c['source'] ?? '')) === '') {
            throw new InvalidArgumentException(
                "Playbook constant '{$key}' has no source. Every number in a plan must be footnoted — "
                . 'add a citation, or mark it [HELM DEFAULT] and say so plainly.'
            );
        }

        return [
            'value'  => $c['value'],
            'unit'   => (string) ($c['unit'] ?? ''),
            'label'  => (string) ($c['label'] ?? $key),
            'source' => (string) $c['source'],
        ];
    }

    /** Just the figure. */
    public function value(string $key): mixed
    {
        return $this->get($key)['value'];
    }

    /** A range constant as [min, max]. */
    public function range(string $key): array
    {
        $v = $this->value($key);
        if (! is_array($v) || count($v) < 2) {
            throw new InvalidArgumentException("Playbook constant '{$key}' is not a range.");
        }

        return [$v[0], $v[1]];
    }

    /**
     * The figure WITH its provenance — what goes into a plan block.
     *
     * e.g. "8 weeks — Start warming audiences and testing creative
     *       (Top Growth Marketing BFCM guide; Common Thread Co "6-week window" (2026))"
     *
     * @return array{key: string, text: string, value: mixed, unit: string, label: string, source: string}
     */
    public function cite(string $key): array
    {
        $c = $this->get($key);

        $value = is_array($c['value'])
            ? implode('–', array_map(static fn ($v): string => (string) $v, $c['value']))
            : (string) $c['value'];

        return $c + [
            'key'  => $key,
            'text' => trim($value . ' ' . $c['unit']) . ' — ' . $c['label'],
        ];
    }

    /**
     * Every constant, each with its source. Used by the plan generator and by the test
     * that guarantees nothing here is unsourced.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $out = [];
        foreach (array_keys((array) config('playbooks.physics', [])) as $key) {
            $out[(string) $key] = $this->cite((string) $key);
        }

        return $out;
    }
}
