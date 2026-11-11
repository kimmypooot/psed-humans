<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per submission of an HR mechanism by an agency (the monitoring
     * workbook's one row per receipt). A returned mechanism that is submitted
     * again stays the same row; `hr_mechanism_events` keeps the history.
     */
    public function up(): void
    {
        Schema::create('hr_mechanisms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('tracking_code', 30)->unique();
            $table->string('mechanism_type', 40)->index();
            $table->string('title')->nullable()->comment('What the agency calls it, e.g. "Revised R&R"');
            $table->string('copies', 20)->nullable()->comment('"eCOPY" or the number of printed copies');
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->string('status', 30)->index();
            $table->string('processor', 20)->nullable()->comment('Initials of the evaluator');
            $table->timestamp('date_submitted')->nullable();
            $table->timestamp('date_processed')->nullable();
            $table->timestamp('date_evaluated')->nullable()->comment('Evaluated by PSED staff');
            $table->timestamp('date_reviewed')->nullable()->comment('Reviewed and approved by the PSED Chief');
            $table->timestamp('date_returned')->nullable();
            $table->timestamp('date_submitted_to_rd')->nullable();
            $table->timestamp('date_approved')->nullable();
            $table->text('remarks')->nullable();
            $table->string('receipt_details')->nullable();
            $table->string('location')->nullable()->comment('Where the signed copy is kept');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_legacy')->default(false)->comment('Imported from the monitoring workbook');
            $table->timestamps();

            $table->index(['agency_id', 'status']);
        });

        Schema::create('hr_mechanism_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_mechanism_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_mechanism_events');
        Schema::dropIfExists('hr_mechanisms');
    }
};
