<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();
            $t->string('platform', 30);
            $t->date('target_date');
            // status: queued | running | success | failed
            $t->string('status', 20);
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->integer('records_processed')->nullable();
            $t->text('error_message')->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['brand_id', 'target_date']);
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
