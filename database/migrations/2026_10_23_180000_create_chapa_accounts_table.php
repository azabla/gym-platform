<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A gym's own Chapa account, one per gym. Only the secret key is kept,
     * encrypted with the app key (a text column, because the encrypted value
     * is much longer than the key). The mode and the key's last 4 characters
     * are kept in plain text, so the gym can see which key is connected
     * without decrypting it (ADR 0009).
     */
    public function up(): void
    {
        Schema::create('chapa_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->text('secret_key');
            $table->string('mode', 4);
            $table->string('key_hint', 4);
            $table->foreignId('connected_by_staff_id');
            $table->timestampsTz();

            $table->foreign(['organization_id', 'connected_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapa_accounts');
    }
};
