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
     * The language messages are written in (English or Amharic): each gym has
     * a default for its members, a member may have their own (null means the
     * gym's), and each staff member picks one for their own messages. See
     * ADR 0034.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('message_language', 2)->default('en');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->string('message_language', 2)->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('message_language', 2)->default('en');
        });

        foreach (['organizations', 'members', 'users'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_message_language_is_known CHECK (message_language IN ('en', 'am'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['organizations', 'members', 'users'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_message_language_is_known");

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('message_language');
            });
        }
    }
};
