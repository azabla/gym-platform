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
     * The check constraint keeps owners working at all branches: an owner can
     * never be limited to some branches of their own gym.
     */
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->string('role', 50);
            $table->boolean('works_at_all_branches')->default(true);
            $table->timestampsTz();

            $table->unique(['organization_id', 'user_id']);
            $table->unique(['organization_id', 'id']);
        });

        DB::statement("ALTER TABLE staff ADD CONSTRAINT staff_owner_works_at_all_branches CHECK (role <> 'owner' OR works_at_all_branches)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
