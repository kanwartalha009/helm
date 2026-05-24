<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name', 120);
            $t->string('slug', 140)->unique();
            // IANA timezone — daily_metrics.date is in this timezone, never UTC
            $t->string('timezone', 64)->default('UTC');
            $t->char('base_currency', 3)->default('USD');
            $t->string('group_tag', 60)->nullable();
            // status: active | paused | archived
            $t->string('status', 20)->default('active');
            $t->timestampsTz();

            $t->index('status');
            $t->index('group_tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
