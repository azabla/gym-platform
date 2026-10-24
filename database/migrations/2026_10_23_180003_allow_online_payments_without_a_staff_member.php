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
     * Nobody at the desk takes an online payment, so a payment Chapa confirmed
     * has no staff member who received it, and every other payment has one
     * (ADR 0009).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('received_by_staff_id')->nullable()->change();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_received_by_staff_unless_online CHECK ((method = 'chapa') = (received_by_staff_id IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_received_by_staff_unless_online');

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('received_by_staff_id')->nullable(false)->change();
        });
    }
};
