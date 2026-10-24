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
     * When each branch is open, one row per open weekday (1 is Monday, 7 is
     * Sunday), in the gym's time zone. A branch without rows is always open;
     * once it has some, a weekday without one is closed. The branch link
     * includes organization_id, so no row can point at another gym's branch
     * (ADR 0016).
     */
    public function up(): void
    {
        Schema::create('branch_opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id');
            $table->smallInteger('weekday');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestampsTz();

            $table->unique(['branch_id', 'weekday']);

            $table->foreign(['organization_id', 'branch_id'])
                ->references(['organization_id', 'id'])->on('branches')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE branch_opening_hours ADD CONSTRAINT branch_opening_hours_weekday_iso CHECK (weekday BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE branch_opening_hours ADD CONSTRAINT branch_opening_hours_closes_after_opening CHECK (closes_at > opens_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_opening_hours');
    }
};
