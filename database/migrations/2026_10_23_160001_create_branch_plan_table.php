<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The branches a plan is limited to (used only while available_at_all_branches
     * is false). Both foreign keys include organization_id, so the database refuses
     * to link a plan to another gym's branch.
     */
    public function up(): void
    {
        Schema::create('branch_plan', function (Blueprint $table) {
            $table->foreignId('organization_id');
            $table->foreignId('branch_id');
            $table->foreignId('plan_id');

            $table->primary(['plan_id', 'branch_id']);
            $table->index(['organization_id', 'branch_id']);

            $table->foreign(['organization_id', 'branch_id'])
                ->references(['organization_id', 'id'])->on('branches')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'plan_id'])
                ->references(['organization_id', 'id'])->on('plans')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_plan');
    }
};
