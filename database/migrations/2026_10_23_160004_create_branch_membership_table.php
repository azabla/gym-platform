<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The branches a membership is valid at (used only while valid_at_all_branches
     * is false). Both foreign keys include organization_id, so the database refuses
     * links between gyms. A branch can't be deleted while a membership names it.
     */
    public function up(): void
    {
        Schema::create('branch_membership', function (Blueprint $table) {
            $table->foreignId('organization_id');
            $table->foreignId('branch_id');
            $table->foreignId('membership_id');

            $table->primary(['membership_id', 'branch_id']);
            $table->index(['organization_id', 'branch_id']);

            $table->foreign(['organization_id', 'branch_id'])
                ->references(['organization_id', 'id'])->on('branches')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'membership_id'])
                ->references(['organization_id', 'id'])->on('memberships')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_membership');
    }
};
