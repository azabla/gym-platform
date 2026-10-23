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
     * list_price_minor is the plan's price on the day of sale; price_minor is what
     * the member paid. Memberships sold before discounts existed paid the list
     * price. The checks keep the price paid at or below the list price, and give
     * a discount_reason exactly when the member paid less.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->bigInteger('list_price_minor')->nullable();
            $table->string('discount_reason', 500)->nullable();
        });

        DB::statement('UPDATE memberships SET list_price_minor = price_minor');
        DB::statement('ALTER TABLE memberships ALTER COLUMN list_price_minor SET NOT NULL');
        DB::statement('ALTER TABLE memberships ADD CONSTRAINT memberships_price_not_above_list_price CHECK (price_minor <= list_price_minor)');
        DB::statement('ALTER TABLE memberships ADD CONSTRAINT memberships_discount_has_reason CHECK ((price_minor < list_price_minor) = (discount_reason IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn(['list_price_minor', 'discount_reason']);
        });
    }
};
