<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The IMIS person an officer is, so the account is linked on that person's first sign-in. Not unique: "NL" and "NJL" can be one person. */
    public function up(): void
    {
        Schema::table('action_officers', function (Blueprint $table) {
            $table->unsignedBigInteger('imis_user_id')->nullable()->index()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('action_officers', function (Blueprint $table) {
            $table->dropColumn('imis_user_id');
        });
    }
};
