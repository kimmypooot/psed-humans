<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The HR checklist the Chief signs with their DICT PNPKI certificate replaces the one the Action Officer
     * forwarded. Who signed it, and who issued their certificate, is kept with the file.
     */
    public function up(): void
    {
        Schema::table('hr_mechanism_attachments', function (Blueprint $table) {
            $table->string('signed_by')->nullable()->after('name')->comment('Common name on the PNPKI certificate');
            $table->string('signer_issuer')->nullable()->after('signed_by');
            $table->timestamp('signed_checked_at')->nullable()->after('signer_issuer')->comment('When the signature was checked on upload');
        });
    }

    public function down(): void
    {
        Schema::table('hr_mechanism_attachments', function (Blueprint $table) {
            $table->dropColumn(['signed_by', 'signer_issuer', 'signed_checked_at']);
        });
    }
};
