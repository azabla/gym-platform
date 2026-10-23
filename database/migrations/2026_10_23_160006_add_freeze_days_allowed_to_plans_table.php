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
     * How many days in total a membership of this plan may be frozen. 0 means it
     * can't be frozen, except by owners and managers.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->smallInteger('freeze_days_allowed')->default(0);
        });

        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_freeze_days_allowed_not_negative CHECK (freeze_days_allowed >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('freeze_days_allowed');
        });
    }
};
