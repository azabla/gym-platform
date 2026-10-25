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
     * Members buy a plan in their app through a payment link, and the
     * membership is created once Chapa confirms the payment (ADR 0013). So:
     *
     * - every link names the member paying it (filled in from the membership
     *   for existing links), and a link is for a membership or for a plan;
     * - a member has at most one open link for buying a plan;
     * - a membership bought in the app has no staff member who sold it.
     */
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable();
            $table->foreignId('plan_id')->nullable();
        });

        DB::statement('UPDATE payment_links SET member_id = memberships.member_id FROM memberships WHERE memberships.id = payment_links.membership_id');

        Schema::table('payment_links', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable(false)->change();
            $table->foreignId('membership_id')->nullable()->change();

            $table->index(['organization_id', 'member_id']);
            $table->foreign(['organization_id', 'member_id'])
                ->references(['organization_id', 'id'])->on('members')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'plan_id'])
                ->references(['organization_id', 'id'])->on('plans')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_for_a_membership_or_a_plan CHECK (membership_id IS NOT NULL OR plan_id IS NOT NULL)');
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT payment_links_paid_plan_has_its_membership CHECK (plan_id IS NULL OR status <> 'paid' OR membership_id IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX payment_links_one_pending_purchase_per_member ON payment_links (member_id) WHERE status = 'pending' AND plan_id IS NOT NULL");

        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignId('sold_by_staff_id')->nullable()->change();
            $table->boolean('sold_in_app')->default(false);
        });

        DB::statement('ALTER TABLE memberships ADD CONSTRAINT memberships_sold_by_staff_or_in_app CHECK ((sold_by_staff_id IS NULL) = sold_in_app)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE memberships DROP CONSTRAINT memberships_sold_by_staff_or_in_app');

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('sold_in_app');
            $table->foreignId('sold_by_staff_id')->nullable(false)->change();
        });

        DB::statement('DROP INDEX payment_links_one_pending_purchase_per_member');
        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_paid_plan_has_its_membership');
        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_for_a_membership_or_a_plan');

        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'plan_id']);
            $table->dropForeign(['organization_id', 'member_id']);
            $table->dropIndex(['organization_id', 'member_id']);
            $table->foreignId('membership_id')->nullable(false)->change();
            $table->dropColumn(['plan_id', 'member_id']);
        });
    }
};
