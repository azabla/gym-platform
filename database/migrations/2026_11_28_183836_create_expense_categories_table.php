<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The categories every gym starts with, by key. Their names are shown in
     * each reader's language until the gym renames one.
     */
    private const DEFAULTS = ['rent', 'salaries', 'utilities', 'equipment', 'maintenance', 'cleaning', 'marketing', 'taxes', 'other'];

    /**
     * Run the migrations.
     *
     * What a gym spends its money on (ADR 0047): a starting list each gym has,
     * by key, which it can rename (name set) or archive, and categories of its
     * own (name set, no key). Names are unique in a gym among those not
     * archived. Every gym already open gets the starting list.
     */
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('default_key', 20)->nullable();
            $table->string('name')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'default_key']);
        });

        DB::statement('CREATE UNIQUE INDEX expense_categories_name_unique ON expense_categories (organization_id, lower(name)) WHERE deleted_at IS NULL AND name IS NOT NULL');
        DB::statement('ALTER TABLE expense_categories ADD CONSTRAINT expense_categories_named CHECK (default_key IS NOT NULL OR name IS NOT NULL)');

        $now = now();

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            DB::table('expense_categories')->insert(array_map(fn (string $key): array => [
                'organization_id' => $organizationId,
                'default_key' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ], self::DEFAULTS));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
