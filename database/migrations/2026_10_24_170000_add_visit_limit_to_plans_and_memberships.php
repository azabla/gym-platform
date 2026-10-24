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
     * Visit packs: a plan may limit how many days a member can come in, such
     * as 10 visits in 60 days. Null means unlimited, as every plan so far. The
     * limit is copied onto the membership when it is sold, like the price.
     * Visits used are counted from the check-ins, so they are indexed by
     * membership (ADR 0016).
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->smallInteger('visit_limit')->nullable();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->smallInteger('visit_limit')->nullable();
        });

        Schema::table('check_ins', function (Blueprint $table) {
            $table->index(['membership_id', 'result', 'checked_in_at']);
        });

        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_visit_limit_positive CHECK (visit_limit IS NULL OR visit_limit BETWEEN 1 AND 1000)');
        DB::statement('ALTER TABLE memberships ADD CONSTRAINT memberships_visit_limit_positive CHECK (visit_limit IS NULL OR visit_limit BETWEEN 1 AND 1000)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropIndex(['membership_id', 'result', 'checked_in_at']);
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('visit_limit');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('visit_limit');
        });
    }
};
