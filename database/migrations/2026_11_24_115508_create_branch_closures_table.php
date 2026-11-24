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
     * Days a branch is closed, such as a holiday or repairs, from starts_on
     * to ends_on (both included), with a reason: closed all day, or open
     * other hours those days (closing earlier than opening means after
     * midnight). They override the weekly hours. See ADR 0037.
     */
    public function up(): void
    {
        Schema::create('branch_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 100);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->foreignId('created_by_staff_id');
            $table->timestampsTz();

            $table->index(['branch_id', 'starts_on']);

            $table->foreign(['organization_id', 'branch_id'])
                ->references(['organization_id', 'id'])->on('branches')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'created_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE branch_closures ADD CONSTRAINT branch_closures_ends_after_starting CHECK (ends_on >= starts_on)');
        DB::statement('ALTER TABLE branch_closures ADD CONSTRAINT branch_closures_hours_together CHECK ((opens_at IS NULL) = (closes_at IS NULL))');
        DB::statement('ALTER TABLE branch_closures ADD CONSTRAINT branch_closures_closes_at_another_time CHECK (closes_at <> opens_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_closures');
    }
};
