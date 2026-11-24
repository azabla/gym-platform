<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Scans the staff app made offline and sent later (ADR 0038): what the
     * door decided is the result, and when the server, deciding again for
     * that moment, would have decided otherwise the check-in is flagged with
     * its reason for staff to review. The GQ1 step a QR check-in used is kept,
     * so the same code scanned twice can be found.
     */
    public function up(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->boolean('offline')->default(false);
            $table->bigInteger('code_step')->nullable();
            $table->boolean('server_disagrees')->default(false);
            $table->string('server_reason_code', 32)->nullable();

            $table->index(['access_credential_id', 'code_step']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropIndex(['access_credential_id', 'code_step']);
            $table->dropColumn(['offline', 'code_step', 'server_disagrees', 'server_reason_code']);
        });
    }
};
