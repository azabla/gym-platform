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
     * A gym's discount groups, such as "Student, 10%" (ADR 0032), with the
     * discount as staff type it. Group names are unique within a gym,
     * ignoring capitals; archived groups free theirs. A member belongs to at
     * most one group (member_discounts), optionally until a day; composite
     * foreign keys keep both inside one gym.
     */
    public function up(): void
    {
        Schema::create('discount_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('discount', 20);
            $table->foreignId('created_by_staff_id');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'id']);

            $table->foreign(['organization_id', 'created_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX discount_groups_organization_id_name_unique ON discount_groups (organization_id, lower(name)) WHERE deleted_at IS NULL');

        Schema::create('member_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id');
            $table->foreignId('discount_group_id');
            $table->date('until')->nullable();
            $table->foreignId('assigned_by_staff_id');
            $table->timestampsTz();

            $table->unique('member_id');
            $table->index(['organization_id', 'discount_group_id']);

            $table->foreign(['organization_id', 'member_id'])
                ->references(['organization_id', 'id'])->on('members')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'discount_group_id'])
                ->references(['organization_id', 'id'])->on('discount_groups')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'assigned_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_discounts');
        Schema::dropIfExists('discount_groups');
    }
};
