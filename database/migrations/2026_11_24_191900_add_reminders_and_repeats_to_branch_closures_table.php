<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When the branch's members were last told of the closure (when it was
     * added, or the day before), so a reminder isn't sent twice and taking it
     * back tells only those who knew; and closures that come back every year
     * on the same Ethiopian date, with when next year's was made. See
     * ADR 0039.
     */
    public function up(): void
    {
        Schema::table('branch_closures', function (Blueprint $table) {
            $table->timestampTz('members_told_at')->nullable();
            $table->timestampTz('reminded_at')->nullable();
            $table->boolean('repeats_yearly')->default(false);
            $table->timestampTz('repeated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_closures', function (Blueprint $table) {
            $table->dropColumn(['members_told_at', 'reminded_at', 'repeats_yearly', 'repeated_at']);
        });
    }
};
