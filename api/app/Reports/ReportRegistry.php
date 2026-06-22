<?php

declare(strict_types=1);

namespace App\Reports;

use App\Reports\Contracts\ReportType;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Resolves report types by key from config/reports.php. Mirrors
 * PlatformRegistry — the rest of the app depends on this, never on a concrete
 * report class.
 */
final class ReportRegistry
{
    public function __construct(private readonly Container $app) {}

    public function has(string $key): bool
    {
        return isset($this->map()[$key]);
    }

    public function for(string $key): ReportType
    {
        $map = $this->map();
        if (! isset($map[$key])) {
            throw new RuntimeException("Unknown report type: {$key}");
        }

        return $this->app->make($map[$key]);
    }

    /** @return array<int, array{key: string, label: string}> */
    public function list(): array
    {
        $out = [];
        foreach ($this->map() as $class) {
            /** @var ReportType $report */
            $report = $this->app->make($class);
            $out[]  = ['key' => $report->key(), 'label' => $report->label()];
        }

        return $out;
    }

    /** @return array<string, class-string<ReportType>> */
    private function map(): array
    {
        /** @var array<string, class-string<ReportType>> $types */
        $types = (array) config('reports.types', []);

        return $types;
    }
}
