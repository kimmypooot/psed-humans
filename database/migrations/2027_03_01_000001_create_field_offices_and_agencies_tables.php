<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The CSC Field and Satellite Offices, and the agencies each one oversees.
     * `imis_code` ties an office to IMIS's organizational unit, so Field Office
     * staff are shown their own office.
     */
    public function up(): void
    {
        Schema::create('field_offices', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 20)->unique();
            $table->string('imis_code', 50)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->string('sector', 10)->nullable()->comment('NGA, LGU, SUC or GOCC');
            $table->foreignId('field_office_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['name', 'field_office_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('field_office_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->after('field_office_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
            $table->dropConstrainedForeignId('field_office_id');
        });
        Schema::dropIfExists('agencies');
        Schema::dropIfExists('field_offices');
    }
};
