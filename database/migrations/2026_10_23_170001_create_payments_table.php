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
     * A payment is money a member paid towards a membership, or (type refund)
     * money given back. The amount is always positive and stored as minor units
     * plus a currency (ADR 0001). The membership, branch and staff links include
     * organization_id, so none of them can point at another gym (ADR 0003).
     *
     * Payments are never deleted: one recorded by mistake is voided, with who
     * voided it and why. A transaction number (reference) is required for every
     * method but cash, and can be recorded only once per gym and method among
     * the payments that aren't voided (ADR 0008).
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->bigInteger('receipt_number');
            $table->string('type', 20);
            $table->foreignId('membership_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->foreignId('branch_id');
            $table->foreignId('received_by_staff_id');
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by_staff_id')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'receipt_number']);
            $table->index(['organization_id', 'membership_id']);
            $table->index(['organization_id', 'paid_on']);

            $table->foreign(['organization_id', 'membership_id'])
                ->references(['organization_id', 'id'])->on('memberships')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'branch_id'])
                ->references(['organization_id', 'id'])->on('branches')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'received_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'voided_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_reference_unless_cash CHECK ((method = 'cash') = (reference IS NULL))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_void_complete CHECK ((voided_at IS NULL) = (voided_by_staff_id IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL))');
        DB::statement('CREATE UNIQUE INDEX payments_reference_unique ON payments (organization_id, method, reference) WHERE voided_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
