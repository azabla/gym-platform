<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A gym brings in the members it had before joining from a CSV file
     * (ADR 0044). member_imports keeps each file: who uploaded it, where it
     * stands and what came of its rows. member_import_rows keeps each row as
     * it was in the file, what checking or importing it gave, and the member
     * it made. Rows are pruned 30 days after the import finishes; the import's
     * summary stays. Links to other gym-owned rows include organization_id,
     * so none can point at another gym.
     */
    public function up(): void
    {
        Schema::create('member_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by_staff_id');
            $table->string('file_name');
            $table->string('status', 20);
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('added_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'created_at']);

            $table->foreign(['organization_id', 'uploaded_by_staff_id'])
                ->references(['organization_id', 'id'])->on('staff')
                ->restrictOnDelete();
        });

        Schema::create('member_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_import_id');
            $table->unsignedInteger('line_number');
            $table->jsonb('cells');
            $table->string('outcome', 20);
            $table->text('reason_text')->nullable();
            $table->jsonb('reason_values')->nullable();
            $table->foreignId('member_id')->nullable();
            $table->timestampsTz();

            $table->unique(['member_import_id', 'line_number']);
            $table->index(['member_import_id', 'outcome']);

            $table->foreign(['organization_id', 'member_import_id'])
                ->references(['organization_id', 'id'])->on('member_imports')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'member_id'])
                ->references(['organization_id', 'id'])->on('members')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_import_rows');
        Schema::dropIfExists('member_imports');
    }
};
