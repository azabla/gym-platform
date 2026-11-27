<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Money a member paid before their gym joined the platform is recorded,
     * when the gym imports its members, as a payment brought in: like cash it
     * has no transaction number, and the staff member who ran the import
     * received it (ADR 0044).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_reference_unless_cash');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_reference_unless_cash CHECK ((method IN ('cash', 'brought_in')) = (reference IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_reference_unless_cash');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_reference_unless_cash CHECK ((method = 'cash') = (reference IS NULL))");
    }
};
