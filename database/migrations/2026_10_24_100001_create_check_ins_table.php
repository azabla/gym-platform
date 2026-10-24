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
     * Every check-in at a gym's door, allowed or denied: by QR scan or at the
     * desk, at which branch, by which staff member, with the membership it
     * used and, when denied, why. A scan of a credential we don't know has no
     * member. Links to other gym-owned rows include organization_id, so none
     * can point at another gym (ADR 0011).
     */
    public function up(): void
    {
        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->nullable();
            $table->foreignId('branch_id');
            $table->foreignId('membership_id')->nullable();
            $table->ulid('access_credential_id')->nullable();
            $table->string('method', 8);
            $table->string('result', 8);
            $table->string('reason_code', 32)->nullable();
            $table->foreignId('checked_in_by_staff_id');
            $table->timestampTz('checked_in_at');
            $table->string('idempotency_key', 100)->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'checked_in_at']);
            $table->index(['organization_id', 'member_id', 'checked_in_at']);

            $table->foreign(['organization_id', 'member_id'])
                ->references(['organization_id', 'id'])->on('members')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'branch_id'])
                ->references(['organization_id', 'id'])->on('branches')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'membership_id'])
                ->references(['organization_id', 'id'])->on('memberships')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'access_credential_id'])
                ->references(['organization_id', 'id'])->on('access_credentials')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'checked_in_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE check_ins ADD CONSTRAINT check_ins_denial_has_reason CHECK ((result = 'denied') = (reason_code IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX check_ins_idempotency_key_unique ON check_ins (organization_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_ins');
    }
};
