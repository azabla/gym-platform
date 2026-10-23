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
     * The price is stored as minor units plus a currency (ADR 0001). Plan names
     * are unique within a gym, ignoring capitals; archived plans (deleted_at set)
     * free their name. The (organization_id, id) key lets memberships link to a
     * plan with a composite foreign key, so they can't use another gym's plan.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->smallInteger('duration_count');
            $table->string('duration_unit', 10);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'id']);
        });

        DB::statement('CREATE UNIQUE INDEX plans_organization_id_name_unique ON plans (organization_id, lower(name)) WHERE deleted_at IS NULL');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_price_not_negative CHECK (price_minor >= 0)');
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_duration_positive CHECK (duration_count > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
