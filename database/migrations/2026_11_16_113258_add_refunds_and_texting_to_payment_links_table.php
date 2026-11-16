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
     * A link needing a refund is marked refunded once the gym has given the
     * money back from its Chapa dashboard, with who did it, when and an
     * optional note. A link texted to the member keeps when it was last sent,
     * so it isn't texted twice in a few minutes. See ADR 0031.
     */
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->timestampTz('refunded_at')->nullable();
            $table->foreignId('refunded_by_staff_id')->nullable();
            $table->string('refund_note', 255)->nullable();
            $table->timestampTz('sms_sent_at')->nullable();

            $table->foreign(['organization_id', 'refunded_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT payment_links_refunded_has_a_time CHECK ((status = 'refunded') = (refunded_at IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payment_links DROP CONSTRAINT payment_links_refunded_has_a_time');

        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'refunded_by_staff_id']);
            $table->dropColumn(['refunded_at', 'refunded_by_staff_id', 'refund_note', 'sms_sent_at']);
        });
    }
};
