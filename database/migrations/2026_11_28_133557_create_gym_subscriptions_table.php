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
     * How the platform bills one gym (ADR 0045): its plan, any negotiated
     * prices, how many months each invoice covers, the end of its free trial,
     * the first day of the next period to invoice (the gym's date), how many
     * days after the due day it pauses, and its TIN for the invoice. A gym
     * without a row isn't billed. A plan in use can't be deleted.
     */
    public function up(): void
    {
        Schema::create('gym_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('platform_plan_id')->constrained()->restrictOnDelete();
            $table->bigInteger('monthly_price_override_minor')->nullable();
            $table->bigInteger('extra_branch_price_override_minor')->nullable();
            $table->char('currency', 3);
            $table->smallInteger('months_per_invoice')->default(1);
            $table->date('trial_ends_on')->nullable();
            $table->date('next_period_starts_on');
            $table->smallInteger('grace_days')->default(14);
            $table->string('tin', 20)->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE gym_subscriptions ADD CONSTRAINT gym_subscriptions_months_per_invoice CHECK (months_per_invoice IN (1, 3, 6, 12))');
        DB::statement('ALTER TABLE gym_subscriptions ADD CONSTRAINT gym_subscriptions_grace_days CHECK (grace_days BETWEEN 0 AND 60)');
        DB::statement('ALTER TABLE gym_subscriptions ADD CONSTRAINT gym_subscriptions_overrides_not_negative CHECK (COALESCE(monthly_price_override_minor, 0) >= 0 AND COALESCE(extra_branch_price_override_minor, 0) >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gym_subscriptions');
    }
};
