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
     * What gyms paid towards the platform's invoices (ADR 0045), recorded by
     * a platform admin from the gym's receipt: bank transfer, Telebirr, CBE
     * Birr or cash. An invoice may be paid in parts. A transaction number is
     * recorded once per method, so the same receipt can't count twice; cash
     * has none. The composite key keeps a payment on its own gym's invoice.
     */
    public function up(): void
    {
        Schema::create('gym_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('gym_invoice_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->date('paid_on');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign(['organization_id', 'gym_invoice_id'])->references(['organization_id', 'id'])->on('gym_invoices')->restrictOnDelete();
            $table->index('gym_invoice_id');
            $table->index('paid_on');
        });

        DB::statement('CREATE UNIQUE INDEX gym_invoice_payments_reference_once ON gym_invoice_payments (method, reference) WHERE reference IS NOT NULL');
        DB::statement('ALTER TABLE gym_invoice_payments ADD CONSTRAINT gym_invoice_payments_amount_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE gym_invoice_payments ADD CONSTRAINT gym_invoice_payments_reference_unless_cash CHECK ((method = 'cash') = (reference IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gym_invoice_payments');
    }
};
