<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->date('date');
            $t->char('base_currency', 3);
            $t->char('target_currency', 3);
            $t->decimal('rate', 14, 6);
            $t->timestampTz('created_at')->useCurrent();

            $t->unique(['date', 'base_currency', 'target_currency']);
            $t->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
