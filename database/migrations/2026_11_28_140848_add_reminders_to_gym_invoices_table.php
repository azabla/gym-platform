<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When the gym's owners were reminded of an unpaid invoice (ADR 0045): on
     * its due day, a week after it, and the day before it pauses the gym.
     * Each reminder goes once.
     */
    public function up(): void
    {
        Schema::table('gym_invoices', function (Blueprint $table) {
            $table->timestampTz('due_reminder_sent_at')->nullable();
            $table->timestampTz('overdue_reminder_sent_at')->nullable();
            $table->timestampTz('pause_warning_sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gym_invoices', function (Blueprint $table) {
            $table->dropColumn(['due_reminder_sent_at', 'overdue_reminder_sent_at', 'pause_warning_sent_at']);
        });
    }
};
