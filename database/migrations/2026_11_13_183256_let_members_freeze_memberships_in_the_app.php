<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Members freeze their own memberships in the app, so a freeze no longer
     * always has a staff member who made it: none means the member did, in
     * the app, as with a membership bought in the app (ADR 0013 and
     * ADR 0026).
     */
    public function up(): void
    {
        Schema::table('membership_freezes', function (Blueprint $table) {
            $table->foreignId('frozen_by_staff_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations. Freezes made in the app would have no staff
     * member to point at, so they must be gone first.
     */
    public function down(): void
    {
        Schema::table('membership_freezes', function (Blueprint $table) {
            $table->foreignId('frozen_by_staff_id')->nullable(false)->change();
        });
    }
};
