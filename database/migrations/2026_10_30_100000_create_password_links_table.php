<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The links sent to staff by SMS to set their first password (an invite)
     * or reset a forgotten one: kept as a keyed hash of the token, with until
     * when they work and when they were used. They are deleted a day after
     * they expire (ADR 0019).
     */
    public function up(): void
    {
        Schema::create('password_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->string('token_hash', 64)->unique();
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
        Schema::dropIfExists('password_links');
    }
};
