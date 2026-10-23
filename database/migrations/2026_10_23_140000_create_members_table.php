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
     * The home branch is linked together with organization_id, so it must be a
     * branch of the member's own gym. A phone number belongs to at most one
     * active member per gym; archived members (deleted_at set) free their number.
     */
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('home_branch_id');
            $table->string('full_name');
            $table->string('phone', 16);
            $table->string('email')->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 16)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'full_name']);
            $table->index(['organization_id', 'home_branch_id']);

            $table->foreign(['organization_id', 'home_branch_id'])
                ->references(['organization_id', 'id'])->on('branches')
                ->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX members_organization_id_phone_unique ON members (organization_id, phone) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
