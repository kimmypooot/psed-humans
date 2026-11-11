<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Who a transmittal is addressed to, remembered from the last one so the next is already filled in. */
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('head_name')->nullable()->after('sector');
            $table->string('head_position')->nullable()->after('head_name');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['head_name', 'head_position']);
        });
    }
};
