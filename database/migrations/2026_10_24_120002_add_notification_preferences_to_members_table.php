<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which topics the member wants pushes about at this gym, such as
     * {"visits": false}. A topic not listed is on (ADR 0012).
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->jsonb('notification_preferences')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
