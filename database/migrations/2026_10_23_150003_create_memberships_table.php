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
     * A membership runs from starts_on through ends_on, both included. The member,
     * plan and staff links all include organization_id, so none of them can point
     * at another gym (ADR 0003). The exclusion constraint gives each member at most
     * one membership on any day, ignoring cancelled ones; it needs the btree_gist
     * extension to compare member_id inside a GiST index.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id');
            $table->foreignId('plan_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->foreignId('sold_by_staff_id');
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_staff_id')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'member_id', 'starts_on']);
            $table->index(['organization_id', 'plan_id']);

            $table->foreign(['organization_id', 'member_id'])
                ->references(['organization_id', 'id'])->on('members')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'plan_id'])
                ->references(['organization_id', 'id'])->on('plans')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'sold_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'cancelled_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE memberships ADD CONSTRAINT memberships_ends_on_or_after_start CHECK (ends_on >= starts_on)');
        DB::statement('ALTER TABLE memberships ADD CONSTRAINT memberships_price_not_negative CHECK (price_minor >= 0)');
        DB::statement('ALTER TABLE memberships ADD CONSTRAINT memberships_cancellation_complete CHECK ((cancelled_at IS NULL) = (cancelled_by_staff_id IS NULL) AND (cancelled_at IS NULL) = (cancellation_reason IS NULL))');
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_one_at_a_time EXCLUDE USING gist (member_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&) WHERE (cancelled_at IS NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
