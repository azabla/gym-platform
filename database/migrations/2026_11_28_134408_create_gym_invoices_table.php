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
     * The platform's invoices to gyms (ADR 0045). Each covers a period of 1,
     * 3, 6 or 12 months, is issued 7 days before the period starts, is due on
     * its first day and pauses the gym if still unpaid on pauses_on. It keeps
     * what it was issued with (plan name, branches, prices, discount), so a
     * later change of plan never changes it; a wrong one is voided. Numbers
     * run across the whole platform, from platform_counters. Only one invoice
     * that isn't voided may cover a period's first day.
     */
    public function up(): void
    {
        Schema::create('platform_counters', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->bigInteger('last_number');
        });

        Schema::create('gym_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->bigInteger('number')->unique();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->date('issued_on');
            $table->date('due_on');
            $table->date('pauses_on');
            $table->string('plan_name');
            $table->smallInteger('branches');
            $table->smallInteger('months');
            $table->bigInteger('monthly_price_minor');
            $table->bigInteger('extra_branch_price_minor');
            $table->smallInteger('discount_percent');
            $table->bigInteger('total_minor');
            $table->bigInteger('vat_minor');
            $table->char('currency', 3);
            $table->string('tin', 20)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'period_starts_on']);
        });

        DB::statement('CREATE UNIQUE INDEX gym_invoices_one_per_period ON gym_invoices (organization_id, period_starts_on) WHERE voided_at IS NULL');
        DB::statement('ALTER TABLE gym_invoices ADD CONSTRAINT gym_invoices_period_in_order CHECK (period_ends_on >= period_starts_on AND pauses_on >= due_on)');
        DB::statement('ALTER TABLE gym_invoices ADD CONSTRAINT gym_invoices_amounts_not_negative CHECK (total_minor >= 0 AND vat_minor >= 0 AND vat_minor <= total_minor)');
        DB::statement('ALTER TABLE gym_invoices ADD CONSTRAINT gym_invoices_months CHECK (months IN (1, 3, 6, 12) AND branches >= 1)');
        DB::statement('ALTER TABLE gym_invoices ADD CONSTRAINT gym_invoices_voided_together CHECK ((voided_at IS NULL) = (voided_by_user_id IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL))');
        DB::statement('ALTER TABLE gym_invoices ADD CONSTRAINT gym_invoices_not_paid_and_voided CHECK (paid_at IS NULL OR voided_at IS NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gym_invoices');
        Schema::dropIfExists('platform_counters');
    }
};
