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
     * A gym's promo codes (ADR 0030). The code is stored in capitals and is
     * unique within the gym, archived codes included, so a code on an old
     * poster never means something else. The discount is kept as staff type
     * it ("20%" or "500"). The dates are the gym's, both included. A code is
     * for every plan, or only the plans in plan_promo_code, whose composite
     * foreign keys keep it inside one gym.
     */
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('discount', 20);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->integer('max_uses')->nullable();
            $table->boolean('once_per_member')->default(true);
            $table->boolean('available_for_all_plans')->default(true);
            $table->foreignId('created_by_staff_id');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'code']);

            $table->foreign(['organization_id', 'created_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_code_in_capitals CHECK (code = upper(code))');
        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_dates_in_order CHECK (starts_on IS NULL OR ends_on IS NULL OR starts_on <= ends_on)');
        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_max_uses_positive CHECK (max_uses IS NULL OR max_uses > 0)');

        Schema::create('plan_promo_code', function (Blueprint $table) {
            $table->foreignId('organization_id');
            $table->foreignId('plan_id');
            $table->foreignId('promo_code_id');

            $table->primary(['promo_code_id', 'plan_id']);
            $table->index(['organization_id', 'plan_id']);

            $table->foreign(['organization_id', 'plan_id'])
                ->references(['organization_id', 'id'])->on('plans')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'promo_code_id'])
                ->references(['organization_id', 'id'])->on('promo_codes')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_promo_code');
        Schema::dropIfExists('promo_codes');
    }
};
