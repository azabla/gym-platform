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
     * What a gym spent (ADR 0047): how much, on what, at which branch or for
     * the whole gym (no branch), the gym's date it was paid, how, to whom, a
     * reference, a note and the receipt, and who recorded it. A mistake is
     * voided with a reason, never deleted. Composite keys keep the branch,
     * category and staff in the gym.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('expense_category_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('paid_on');
            $table->string('method', 20);
            $table->string('payee')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->string('receipt_path')->nullable();
            $table->unsignedBigInteger('recorded_by_staff_id')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by_staff_id')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'branch_id'])->references(['organization_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['organization_id', 'expense_category_id'])->references(['organization_id', 'id'])->on('expense_categories')->restrictOnDelete();
            $table->foreign(['organization_id', 'recorded_by_staff_id'])->references(['organization_id', 'id'])->on('staff')->restrictOnDelete();
            $table->foreign(['organization_id', 'voided_by_staff_id'])->references(['organization_id', 'id'])->on('staff')->restrictOnDelete();
            $table->index(['organization_id', 'paid_on']);
        });

        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_voided_together CHECK ((voided_at IS NULL) = (voided_by_staff_id IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
