<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * id is already unique. This key lets tables that point at a payment, such
     * as payment links, use a composite foreign key (organization_id,
     * payment_id), so the database refuses links between gyms. See ADR 0003.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['organization_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'id']);
        });
    }
};
