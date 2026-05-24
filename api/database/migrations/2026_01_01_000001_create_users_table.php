<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name', 120);
            $t->string('email', 190)->unique();
            $t->string('password', 255);
            // role: master_admin | manager | team_member | brand_user
            $t->string('role', 30)->default('master_admin');
            // status: active | invited | disabled
            $t->string('status', 20)->default('active');
            // encrypted at application layer
            $t->string('mfa_secret', 255)->nullable();
            $t->timestampTz('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->string('remember_token', 100)->nullable();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
