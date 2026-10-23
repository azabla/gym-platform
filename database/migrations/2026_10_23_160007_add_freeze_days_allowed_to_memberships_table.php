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
     * Copied from the plan when the membership is sold, like its price, so a later
     * change to the plan doesn't change what members already bought. Memberships
     * sold before freezing existed get 0.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->smallInteger('freeze_days_allowed')->default(0);
        });

        DB::statement('ALTER TABLE memberships ADD CONSTRAINT memberships_freeze_days_allowed_not_negative CHECK (freeze_days_allowed >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('freeze_days_allowed');
        });
    }
};
