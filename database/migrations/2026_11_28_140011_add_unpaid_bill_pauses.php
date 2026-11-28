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
     * A gym can now be paused by the platform itself for an unpaid invoice,
     * and resumed by itself once it is paid (ADR 0045). A pause records its
     * cause: by an admin, who is named, or for an unpaid bill, with no admin.
     * A resume may also have no admin, when a payment brought it. An invoice
     * pauses its gym at most once (paused_gym_at), so a gym an admin resumes
     * by hand isn't paused again by the next run for the same invoice.
     */
    public function up(): void
    {
        Schema::table('gym_suspensions', function (Blueprint $table) {
            $table->string('cause', 20)->default('by_admin');
            $table->unsignedBigInteger('suspended_by_user_id')->nullable()->change();
        });

        DB::statement('ALTER TABLE gym_suspensions DROP CONSTRAINT gym_suspensions_resumed_together');
        DB::statement('ALTER TABLE gym_suspensions ADD CONSTRAINT gym_suspensions_resumed_by_only_when_resumed CHECK (resumed_at IS NOT NULL OR resumed_by_user_id IS NULL)');
        DB::statement("ALTER TABLE gym_suspensions ADD CONSTRAINT gym_suspensions_admin_unless_unpaid_bill CHECK ((cause = 'unpaid_bill') = (suspended_by_user_id IS NULL))");

        Schema::table('gym_invoices', function (Blueprint $table) {
            $table->timestampTz('paused_gym_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gym_invoices', function (Blueprint $table) {
            $table->dropColumn('paused_gym_at');
        });

        DB::statement('ALTER TABLE gym_suspensions DROP CONSTRAINT gym_suspensions_admin_unless_unpaid_bill');
        DB::statement('ALTER TABLE gym_suspensions DROP CONSTRAINT gym_suspensions_resumed_by_only_when_resumed');
        DB::statement('ALTER TABLE gym_suspensions ADD CONSTRAINT gym_suspensions_resumed_together CHECK ((resumed_at IS NULL) = (resumed_by_user_id IS NULL))');

        Schema::table('gym_suspensions', function (Blueprint $table) {
            $table->dropColumn('cause');
        });
    }
};
