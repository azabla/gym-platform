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
     * Who did what with a gym's money (ADR 0046): payments, refunds and voids,
     * sales and cancellations, discounts, payment links, plan prices and promo
     * codes. Each row says who (no one for Chapa or the platform), at which
     * branch when there is one, what it was about, what changed, and whether
     * an owner would want to look (flagged). Rows are written in the same
     * transaction as the action and never changed or deleted.
     */
    public function up(): void
    {
        Schema::create('activity_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('action', 40);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('details');
            $table->boolean('flagged')->default(false);
            $table->timestampTz('occurred_at');

            $table->foreign(['organization_id', 'branch_id'])->references(['organization_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['organization_id', 'staff_id'])->references(['organization_id', 'id'])->on('staff')->restrictOnDelete();
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        // The log is only ever added to.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_records_are_kept() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Activity records are never changed or deleted.';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement('CREATE TRIGGER activity_records_are_kept BEFORE UPDATE OR DELETE ON activity_records FOR EACH ROW EXECUTE FUNCTION activity_records_are_kept()');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_records');
        DB::statement('DROP FUNCTION IF EXISTS activity_records_are_kept()');
    }
};
