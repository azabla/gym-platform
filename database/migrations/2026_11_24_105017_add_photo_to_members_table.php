<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where the member's photo is on the private photos disk, if they have
     * one. See ADR 0036.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
