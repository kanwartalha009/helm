<?php

declare(strict_types=1);

namespace App\Reports\Contracts;

use App\Models\Brand;

/**
 * Every report type — current and future — implements this interface. The
 * controller and the SPA depend only on this contract, never on a concrete
 * report. Mirrors the PlatformAdapter pattern (spec §6).
 *
 * build() returns a render-ready, JSON-serialisable payload. No HTML is built
 * on the server — the SPA's white-label template renders the payload, so the
 * same data drives the in-app view, the PDF (print), and the public share link.
 */
interface ReportType
{
    /** Stable key — 'overall-performance', 'country', 'product', … */
    public function key(): string;

    /** Human label for the report picker. */
    public function label(): string;

    /** @return array<string, mixed> render-ready payload */
    public function build(Brand $brand, ReportFilters $filters): array;
}
