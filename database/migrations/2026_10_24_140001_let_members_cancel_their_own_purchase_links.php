<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A member who starts buying one plan and then chooses another cancels
     * their own unpaid link, so a link to buy a plan may be cancelled with no
     * staff member who cancelled it (ADR 0013). Links for a membership are
     * still cancelled by staff only.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_cancellation_complete');
        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_cancellation_complete CHECK ((cancelled_by_staff_id IS NULL OR cancelled_at IS NOT NULL) AND (cancelled_at IS NULL OR cancelled_by_staff_id IS NOT NULL OR plan_id IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_cancellation_complete');
        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_cancellation_complete CHECK ((cancelled_at IS NULL) = (cancelled_by_staff_id IS NULL))');
    }
};
