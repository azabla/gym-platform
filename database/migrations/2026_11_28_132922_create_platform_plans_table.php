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
     * What the platform charges gyms (ADR 0045): a monthly price for the first
     * branch, a price for each extra branch, and a discount for paying 3, 6 or
     * 12 months at a time. Prices include VAT and are stored as minor units
     * (ADR 0001). Plans belong to the platform, not to a gym; a retired plan
     * keeps the gyms already on it but isn't offered for new ones.
     */
    public function up(): void
    {
        Schema::create('platform_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->bigInteger('monthly_price_minor');
            $table->bigInteger('extra_branch_price_minor');
            $table->char('currency', 3);
            $table->smallInteger('discount_3_months')->default(0);
            $table->smallInteger('discount_6_months')->default(0);
            $table->smallInteger('discount_12_months')->default(0);
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX platform_plans_name_unique ON platform_plans (lower(name))');
        DB::statement('ALTER TABLE platform_plans ADD CONSTRAINT platform_plans_prices_not_negative CHECK (monthly_price_minor >= 0 AND extra_branch_price_minor >= 0)');
        DB::statement('ALTER TABLE platform_plans ADD CONSTRAINT platform_plans_discounts_in_range CHECK (discount_3_months BETWEEN 0 AND 50 AND discount_6_months BETWEEN 0 AND 50 AND discount_12_months BETWEEN 0 AND 50)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_plans');
    }
};
