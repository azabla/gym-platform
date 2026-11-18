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
     * Each staff member's choice, at each gym, of push and SMS for each topic
     * of their messages (null: push on, SMS off), and which app a phone's push
     * token is for, so staff messages reach the staff app only and members'
     * the members app only. Existing tokens are the members app's. See
     * ADR 0035.
     */
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->jsonb('notification_preferences')->nullable();
        });

        Schema::table('push_tokens', function (Blueprint $table) {
            $table->string('app', 10)->default('members');
        });

        DB::statement("ALTER TABLE push_tokens ADD CONSTRAINT push_tokens_app_is_known CHECK (app IN ('members', 'staff'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE push_tokens DROP CONSTRAINT push_tokens_app_is_known');

        Schema::table('push_tokens', function (Blueprint $table) {
            $table->dropColumn('app');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
