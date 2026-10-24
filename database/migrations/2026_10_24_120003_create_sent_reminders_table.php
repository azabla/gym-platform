<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each reminder sent about a membership, so the hourly reminder command
     * sends each one once, however often it runs (ADR 0012). The days are
     * before the membership ends; the day after it ends is -1.
     */
    public function up(): void
    {
        Schema::create('sent_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('membership_id');
            $table->string('type', 40);
            $table->smallInteger('days_before_end');
            $table->timestampTz('sent_at');

            $table->unique(['membership_id', 'type', 'days_before_end']);
            $table->foreign(['organization_id', 'membership_id'])
                ->references(['organization_id', 'id'])->on('memberships')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sent_reminders');
    }
};
