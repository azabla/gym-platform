<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Staff who leave are archived, not deleted, so the payments, check-ins
     * and sales they recorded still name them. An archived staff member can't
     * act at the gym, and adding them again restores the same record
     * (ADR 0019).
     */
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->softDeletesTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropSoftDeletesTz();
        });
    }
};
