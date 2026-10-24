<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The QR credentials members show at the door: a ULID (it is part of each
     * GQ1 code), the member and gym, the user and sign-in token it was issued
     * to, the encrypted secret, and the last 30-second step that was scanned,
     * so a code works only once. A member has one active credential at a time
     * (ADR 0011).
     */
    public function up(): void
    {
        Schema::create('access_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->constrained()->nullOnDelete();
            $table->text('secret');
            $table->string('algorithm', 8);
            $table->bigInteger('last_used_step')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 20)->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'member_id']);

            $table->foreign(['organization_id', 'member_id'])
                ->references(['organization_id', 'id'])->on('members')
                ->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX access_credentials_one_active_per_member ON access_credentials (member_id) WHERE revoked_at IS NULL');
        DB::statement('ALTER TABLE access_credentials ADD CONSTRAINT access_credentials_revocation_complete CHECK ((revoked_at IS NULL) = (revoked_reason IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_credentials');
    }
};
