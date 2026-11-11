<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The PSED process: a submission is received, routed to an Action Officer, acknowledged by
     * that officer (the 20-day clock starts), evaluated, forwarded to the PSED Chief, and the
     * three documents (reviewed mechanism, transmittal, HR checklist) go to the agency by email.
     */
    public function up(): void
    {
        Schema::table('hr_mechanisms', function (Blueprint $table) {
            $table->foreignId('action_officer_id')->nullable()->after('processor')->constrained('users')->nullOnDelete();
            $table->timestamp('routed_at')->nullable()->after('date_submitted');
            $table->timestamp('acknowledged_at')->nullable()->after('routed_at');
            $table->timestamp('due_at')->nullable()->after('acknowledged_at')->comment('End of the 20-working-day turnaround');
        });

        Schema::create('hr_mechanism_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_mechanism_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->comment('reviewed, transmittal or checklist');
            $table->string('path');
            $table->string('name');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hr_mechanism_id', 'kind']);
        });

        // The old statuses become the new ones. An old "For Signature" has been through the Chief.
        DB::table('hr_mechanisms')->where('status', 'For Review')->update(['status' => 'Received by PSED']);
        DB::table('hr_mechanisms')->where('status', 'For Signature')->update(['status' => 'Reviewed by PSED Chief']);
        DB::table('hr_mechanisms')->where('status', 'For Compliance/Returned')->update(['status' => 'For Compliance']);
        DB::table('hr_mechanism_events')->where('to_status', 'For Review')->update(['to_status' => 'Received by PSED']);
        DB::table('hr_mechanism_events')->where('from_status', 'For Review')->update(['from_status' => 'Received by PSED']);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_mechanism_attachments');

        Schema::table('hr_mechanisms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('action_officer_id');
            $table->dropColumn(['routed_at', 'acknowledged_at', 'due_at']);
        });
    }
};
