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
     * A freeze pauses a membership from starts_on through ends_on, both included.
     * The membership and staff links include organization_id, so neither can point
     * at another gym (ADR 0003). The exclusion constraint (btree_gist, created with
     * the memberships table) keeps the freezes of one membership from overlapping.
     */
    public function up(): void
    {
        Schema::create('membership_freezes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 500);
            $table->foreignId('frozen_by_staff_id');
            $table->timestampsTz();

            $table->index(['organization_id', 'membership_id']);

            $table->foreign(['organization_id', 'membership_id'])
                ->references(['organization_id', 'id'])->on('memberships')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'frozen_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE membership_freezes ADD CONSTRAINT membership_freezes_ends_on_or_after_start CHECK (ends_on >= starts_on)');
        DB::statement("ALTER TABLE membership_freezes ADD CONSTRAINT membership_freezes_one_at_a_time EXCLUDE USING gist (membership_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_freezes');
    }
};
