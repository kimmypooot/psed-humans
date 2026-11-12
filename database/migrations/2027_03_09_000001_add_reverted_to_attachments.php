<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the PSED Chief undoes a decision, the checklist they signed is no longer the checklist. It is kept in the
     * history of the mechanism, but the one before it is the current one again.
     */
    public function up(): void
    {
        Schema::table('hr_mechanism_attachments', function (Blueprint $table) {
            $table->timestamp('reverted_at')->nullable()->after('signed_checked_at')->comment('When the step that made this the current document was undone');
        });
    }

    public function down(): void
    {
        Schema::table('hr_mechanism_attachments', function (Blueprint $table) {
            $table->dropColumn('reverted_at');
        });
    }
};
