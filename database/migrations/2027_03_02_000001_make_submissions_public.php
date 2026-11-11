<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agencies have no accounts. They submit from a public page, and leave an email
     * address that gets the updates and a link to the tracking page.
     *
     * - `agencies.listed`: the agency is in the list psed-app keeps (agencies:sync), so the
     *   public page offers it. Agencies that only came from the old workbook stay unlisted.
     * - `hr_mechanisms.contact_*`: who to tell. Rows from the workbook have none.
     */
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('listed')->default(false)->after('sector')->index();
        });

        Schema::table('hr_mechanisms', function (Blueprint $table) {
            $table->string('contact_email')->nullable()->after('submitted_by')->index();
            $table->string('contact_name')->nullable()->after('contact_email');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('agency_id')->nullable()->after('field_office_id')->constrained()->nullOnDelete();
        });

        Schema::table('hr_mechanisms', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'contact_name']);
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('listed');
        });
    }
};
