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
     * Announcements from a gym to its members, in the gym's own words: to
     * every branch or one, sent now or at send_at. A sent one keeps when it
     * went and to how many members; a cancelled one never goes. Links to other
     * gym-owned rows include organization_id, so none can point at another
     * gym (ADR 0015).
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('posted_by_staff_id');
            $table->string('title', 80);
            $table->string('body', 1000);
            $table->boolean('include_recently_expired')->default(false);
            $table->timestampTz('send_at');
            $table->timestampTz('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'send_at']);

            $table->foreign(['organization_id', 'branch_id'])
                ->references(['organization_id', 'id'])->on('branches')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'posted_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE announcements ADD CONSTRAINT announcements_sent_or_cancelled CHECK (sent_at IS NULL OR cancelled_at IS NULL)');
        DB::statement('ALTER TABLE announcements ADD CONSTRAINT announcements_recipients_once_sent CHECK (recipients_count IS NULL OR sent_at IS NOT NULL)');
        DB::statement('CREATE INDEX announcements_due ON announcements (send_at) WHERE sent_at IS NULL AND cancelled_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
