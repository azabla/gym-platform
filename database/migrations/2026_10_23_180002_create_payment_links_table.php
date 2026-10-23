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
     * A payment link is a checkout on Chapa's payment page for an amount owed
     * on a membership. tx_ref is our reference for it at Chapa, unique across
     * the platform. When Chapa confirms the payment, the link points at the
     * payment it became (or, when nothing was due any more, is marked
     * refund_needed). The membership, branch, staff and payment links include
     * organization_id, so none of them can point at another gym (ADR 0003).
     * A membership has at most one pending link (ADR 0009).
     */
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_id');
            $table->foreignId('branch_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('tx_ref', 50)->unique();
            $table->text('checkout_url');
            $table->string('status', 20);
            $table->foreignId('created_by_staff_id');
            $table->foreignId('payment_id')->nullable();
            $table->string('chapa_reference', 100)->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_staff_id')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'membership_id']);
            $table->index(['organization_id', 'status']);

            $table->foreign(['organization_id', 'membership_id'])
                ->references(['organization_id', 'id'])->on('memberships')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'branch_id'])
                ->references(['organization_id', 'id'])->on('branches')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'cancelled_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'payment_id'])
                ->references(['organization_id', 'id'])->on('payments')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_amount_positive CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE payment_links ADD CONSTRAINT payment_links_cancellation_complete CHECK ((cancelled_at IS NULL) = (cancelled_by_staff_id IS NULL))');
        DB::statement("ALTER TABLE payment_links ADD CONSTRAINT payment_links_paid_with_a_payment CHECK ((status = 'paid') = (payment_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX payment_links_one_pending_per_membership ON payment_links (membership_id) WHERE status = 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
