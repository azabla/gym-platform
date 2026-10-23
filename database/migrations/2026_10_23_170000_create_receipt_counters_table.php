<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The last receipt number each gym gave. Payment::nextReceiptNumber() takes
     * the next one with a single upsert inside the payment's transaction, which
     * locks the gym's row until the payment is saved: receipt numbers never
     * repeat, and a payment that fails gives its number back (ADR 0008).
     */
    public function up(): void
    {
        Schema::create('receipt_counters', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->bigInteger('last_number');

            $table->primary('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipt_counters');
    }
};
