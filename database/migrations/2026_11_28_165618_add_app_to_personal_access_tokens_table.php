<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which app a sign-in is for (ADR 0046): the staff app (signed in with a
     * password) or the members app (with an SMS code). A sign-in unused for
     * 30 days (staff) or 90 (members) expires. Sign-ins from before this have
     * none and get the staff app's shorter time.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('app', 10)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('app');
        });
    }
};
