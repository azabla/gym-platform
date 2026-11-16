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
     * A membership sold with a promo code names it, so the code's uses can be
     * counted (ADR 0030). A link to buy a plan with a code names it too, with
     * the plan's price when the link was opened, so the membership records
     * its list price and discount once Chapa confirms the payment.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->nullable();

            $table->index(['organization_id', 'promo_code_id']);
            $table->foreign(['organization_id', 'promo_code_id'])
                ->references(['organization_id', 'id'])->on('promo_codes')
                ->restrictOnDelete();
        });

        Schema::table('payment_links', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->nullable();
            $table->bigInteger('list_amount_minor')->nullable();

            $table->foreign(['organization_id', 'promo_code_id'])
                ->references(['organization_id', 'id'])->on('promo_codes')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_code_has_list_amount CHECK ((promo_code_id IS NULL) = (list_amount_minor IS NULL))');
        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_list_amount_covers_amount CHECK (list_amount_minor IS NULL OR list_amount_minor >= amount_minor)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_list_amount_covers_amount');
        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_code_has_list_amount');

        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'promo_code_id']);
            $table->dropColumn(['promo_code_id', 'list_amount_minor']);
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'promo_code_id']);
            $table->dropIndex(['organization_id', 'promo_code_id']);
            $table->dropColumn('promo_code_id');
        });
    }
};
