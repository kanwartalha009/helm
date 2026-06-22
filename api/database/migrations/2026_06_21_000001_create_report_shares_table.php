<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots of generated reports for the public share link (GET /api/r/{token})
 * and as the saved artifact behind a PDF export. `filters` is the period/compare
 * set; `content` holds the operator's edited narrative + comments at send time.
 * Additive, non-destructive — new table only.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_shares', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $t->string('report_type', 64);
            $t->string('token', 64)->unique();
            $t->json('filters')->nullable();
            $t->json('content')->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampsTz();

            $t->index(['brand_id', 'report_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_shares');
    }
};
