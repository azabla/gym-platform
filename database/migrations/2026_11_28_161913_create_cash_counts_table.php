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
     * A staff member's cash count at a branch (ADR 0046): the cash the system
     * says they took there since their last count (cash payments less cash
     * refunds, not voided), what they counted, and a note when the two
     * differ. An owner or manager then marks it checked. Each counted payment
     * points at its count, so it is never counted twice; the composite keys
     * keep counts and payments in one gym.
     */
    public function up(): void
    {
        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('staff_id');
            $table->bigInteger('expected_minor');
            $table->bigInteger('counted_minor');
            $table->char('currency', 3);
            $table->text('note')->nullable();
            $table->timestampTz('closed_at');
            $table->unsignedBigInteger('checked_by_staff_id')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->text('check_note')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'branch_id'])->references(['organization_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['organization_id', 'staff_id'])->references(['organization_id', 'id'])->on('staff')->restrictOnDelete();
            $table->foreign(['organization_id', 'checked_by_staff_id'])->references(['organization_id', 'id'])->on('staff')->restrictOnDelete();
            $table->index(['organization_id', 'closed_at']);
        });

        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_counted_not_negative CHECK (counted_minor >= 0)');
        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_checked_together CHECK ((checked_at IS NULL) = (checked_by_staff_id IS NULL))');
        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_difference_explained CHECK (counted_minor = expected_minor OR note IS NOT NULL)');

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_count_id')->nullable();

            $table->foreign(['organization_id', 'cash_count_id'])->references(['organization_id', 'id'])->on('cash_counts')->restrictOnDelete();
            $table->index(['received_by_staff_id', 'branch_id', 'cash_count_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'cash_count_id']);
            $table->dropIndex(['received_by_staff_id', 'branch_id', 'cash_count_id']);
            $table->dropColumn('cash_count_id');
        });

        Schema::dropIfExists('cash_counts');
    }
};
