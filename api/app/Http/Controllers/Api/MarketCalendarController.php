<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketMoment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The EU market calendar (GO-4.1). Read-only.
 *
 * Every row carries its `source` — a calendar entry a human cannot check is one they
 * should not plan a client's quarter around. `kind` distinguishes what is FIXED BY LAW
 * (FR soldes, BE solden) from what is merely commercial (BFCM, back-to-school), because
 * planning around a legal constraint that does not exist is its own kind of wrong number.
 */
class MarketCalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market'   => ['nullable', 'string', 'size:2'],
            'year'     => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'upcoming' => ['nullable', 'boolean'],
        ]);

        $year = (int) ($data['year'] ?? CarbonImmutable::now()->year);

        $rows = MarketMoment::query()
            ->where('year', $year)
            ->when($data['market'] ?? null, fn ($q, $v) => $q->where('market', strtoupper((string) $v)))
            // "What's coming" is the planning question — the past is not actionable.
            ->when($data['upcoming'] ?? false, fn ($q) => $q->whereDate('ends_on', '>=', CarbonImmutable::now()->toDateString()))
            ->orderBy('starts_on')
            ->get()
            ->map(static fn (MarketMoment $m): array => [
                'market'    => $m->market,
                'momentKey' => $m->moment_key,
                'label'     => $m->label,
                'startsOn'  => $m->starts_on->toDateString(),
                'endsOn'    => $m->ends_on->toDateString(),
                'kind'      => $m->kind,          // legal_sale | gift | event
                'source'    => $m->source,
            ])
            ->all();

        return response()->json([
            'year'  => $year,
            'rows'  => $rows,
            'note'  => $rows === []
                ? 'No calendar seeded for this year yet — run `php artisan calendar:seed ' . $year . '`.'
                : 'Dates are computed per year (soldes and Black Friday move) and every row carries its source. '
                    . '`legal_sale` means fixed by law; `event` has no legal force.',
        ]);
    }
}
