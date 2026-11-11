<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The names the monitoring workbook used for an agency ("MGO ALLEN, NORTHERN SAMAR", "NAVAL W.D."),
     * kept so an import files its rows under the official agency instead of creating a look-alike.
     */
    public function up(): void
    {
        Schema::create('agency_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_office_id')->nullable()->constrained()->nullOnDelete()->comment('The sheet it was written on');
            $table->string('name');
            $table->string('name_key')->index()->comment('AgencyMapper::key(name)');
            $table->timestamps();

            $table->unique(['name_key', 'field_office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_aliases');
    }
};
