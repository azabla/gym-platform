<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The codes sent by SMS for signing in: kept as a keyed hash, with how
     * many times someone tried them, until when they work, and when they were
     * used. They are deleted a day after they expire (ADR 0010).
     */
    public function up(): void
    {
        Schema::create('sign_in_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 16)->index();
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sign_in_codes');
    }
};
