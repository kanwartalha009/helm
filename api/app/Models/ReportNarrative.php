<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One LLM narrative draft per brand × report type × filter selection.
 * blocks = the model's draft; edited_blocks = the operator's copy (what
 * actually ships). See D-016: rules own the numbers, this owns prose only.
 */
class ReportNarrative extends Model
{
    protected $fillable = [
        'brand_id', 'report_type', 'period_key',
        'blocks', 'edited_blocks',
        'provider', 'model', 'language',
        'window_start', 'window_end',
        'generated_by_user_id', 'generated_at', 'edited_at',
    ];

    protected $casts = [
        'blocks'        => 'array',
        'edited_blocks' => 'array',
        'window_start'  => 'date',
        'window_end'    => 'date',
        'generated_at'  => 'datetime',
        'edited_at'     => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** The blocks that should render: operator's edits win over the draft. */
    public function effectiveBlocks(): array
    {
        return $this->edited_blocks ?: $this->blocks;
    }

    /** @return array<string, mixed> the SPA-facing shape */
    public function toPayload(): array
    {
        return [
            'blocks'      => $this->effectiveBlocks(),
            'draftBlocks' => $this->blocks,
            'isEdited'    => $this->edited_blocks !== null,
            'provider'    => $this->provider,
            'model'       => $this->model,
            'language'    => $this->language,
            'generatedAt' => $this->generated_at?->toIso8601String(),
            'editedAt'    => $this->edited_at?->toIso8601String(),
        ];
    }
}
