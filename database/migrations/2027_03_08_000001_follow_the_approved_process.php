<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statuses before and after the process the PSED Chief approved. In the old process the agency was emailed when the
     * Chief decided, so those rows are what are now Transmittal (Approved) and Transmittal (For Compliance). The order of the
     * renames matters: "Reviewed by PSED Chief" changes meaning, so it is moved on before "For PSED Chief Review" takes it.
     */
    private const RENAMES = [
        'Reviewed by PSED Chief' => 'Transmittal (Approved)',
        'For Compliance' => 'Transmittal (For Compliance)',
        'For PSED Chief Review' => 'Reviewed by PSED Chief',
    ];

    public function up(): void
    {
        Schema::table('hr_mechanisms', function (Blueprint $table) {
            $table->timestamp('date_transmitted')->nullable()->after('date_returned')->comment('When the Action Officer sent the signed transmittal to the agency');
            $table->timestamp('date_distributed')->nullable()->after('date_approved')->comment('When the approved copies went to the Field Office and the agency');
        });

        foreach (self::RENAMES as $old => $new) {
            DB::table('hr_mechanisms')->where('status', $old)->update(['status' => $new]);
            DB::table('hr_mechanism_events')->where('from_status', $old)->update(['from_status' => $new]);
            DB::table('hr_mechanism_events')->where('to_status', $old)->update(['to_status' => $new]);
        }

        DB::table('hr_mechanisms')->whereIn('status', ['Transmittal (Approved)', 'Transmittal (For Compliance)'])->whereNull('date_transmitted')
            ->update(['date_transmitted' => DB::raw('COALESCE(date_returned, date_reviewed)')]);
    }

    public function down(): void
    {
        foreach (array_reverse(self::RENAMES, true) as $old => $new) {
            DB::table('hr_mechanisms')->where('status', $new)->update(['status' => $old]);
            DB::table('hr_mechanism_events')->where('from_status', $new)->update(['from_status' => $old]);
            DB::table('hr_mechanism_events')->where('to_status', $new)->update(['to_status' => $old]);
        }

        Schema::table('hr_mechanisms', function (Blueprint $table) {
            $table->dropColumn(['date_transmitted', 'date_distributed']);
        });
    }
};
