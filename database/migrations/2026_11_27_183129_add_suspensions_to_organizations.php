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
     * The platform pauses a gym that should stop working for now, keeping
     * everything it has (ADR 0043). organizations.suspended_at says whether
     * it is paused, read on every request with the gym; gym_suspensions keeps
     * each pause: who, when and why, and who resumed it. At most one pause
     * is open per gym.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestampTz('suspended_at')->nullable();
        });

        Schema::create('gym_suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->timestampTz('suspended_at');
            $table->foreignId('suspended_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestampTz('resumed_at')->nullable();
            $table->foreignId('resumed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resume_note')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'suspended_at']);
        });

        DB::statement('CREATE UNIQUE INDEX gym_suspensions_one_open_per_gym ON gym_suspensions (organization_id) WHERE resumed_at IS NULL');
        DB::statement('ALTER TABLE gym_suspensions ADD CONSTRAINT gym_suspensions_resumed_together CHECK ((resumed_at IS NULL) = (resumed_by_user_id IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gym_suspensions');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });
    }
};
