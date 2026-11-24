<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Opening hours may run past midnight: a closing time earlier than the
     * opening time, such as 18:00–02:00, means the next day. Only equal times
     * are refused. See ADR 0037.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE branch_opening_hours DROP CONSTRAINT branch_opening_hours_closes_after_opening');
        DB::statement('ALTER TABLE branch_opening_hours ADD CONSTRAINT branch_opening_hours_closes_at_another_time CHECK (closes_at <> opens_at)');
    }

    /**
     * Reverse the migrations. Hours that run past midnight must be gone first.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE branch_opening_hours DROP CONSTRAINT branch_opening_hours_closes_at_another_time');
        DB::statement('ALTER TABLE branch_opening_hours ADD CONSTRAINT branch_opening_hours_closes_after_opening CHECK (closes_at > opens_at)');
    }
};
