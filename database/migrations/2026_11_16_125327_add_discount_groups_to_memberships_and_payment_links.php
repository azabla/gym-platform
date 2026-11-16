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
     * A membership sold at a discount group's price names the group, and so
     * does a link to buy a plan at it, which keeps the plan's price as its
     * list amount as a link with a promo code does (ADR 0030 and ADR 0032).
     * A link names a code or a group, never both.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignId('discount_group_id')->nullable();

            $table->index(['organization_id', 'discount_group_id']);
            $table->foreign(['organization_id', 'discount_group_id'])
                ->references(['organization_id', 'id'])->on('discount_groups')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE memberships ADD CONSTRAINT memberships_code_or_group CHECK (promo_code_id IS NULL OR discount_group_id IS NULL)');

        Schema::table('payment_links', function (Blueprint $table) {
            $table->foreignId('discount_group_id')->nullable();

            $table->foreign(['organization_id', 'discount_group_id'])
                ->references(['organization_id', 'id'])->on('discount_groups')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_code_has_list_amount');
        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_discount_has_list_amount CHECK ((promo_code_id IS NULL AND discount_group_id IS NULL) = (list_amount_minor IS NULL))');
        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_code_or_group CHECK (promo_code_id IS NULL OR discount_group_id IS NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_code_or_group');
        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_discount_has_list_amount');

        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'discount_group_id']);
            $table->dropColumn('discount_group_id');
        });

        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_code_has_list_amount CHECK ((promo_code_id IS NULL) = (list_amount_minor IS NULL))');
        DB::statement('ALTER TABLE memberships DROP CONSTRAINT memberships_code_or_group');

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'discount_group_id']);
            $table->dropIndex(['organization_id', 'discount_group_id']);
            $table->dropColumn('discount_group_id');
        });
    }
};
