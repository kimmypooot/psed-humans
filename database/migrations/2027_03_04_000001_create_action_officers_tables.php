<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The monitoring workbook names its evaluators by initials ("RMA", "KBMB") and some mechanisms
     * have two ("GQC & RMA"). An officer is one set of initials, with the person's name and
     * account when known; the pivot keeps every officer of a mechanism in the order written.
     */
    public function up(): void
    {
        Schema::create('action_officers', function (Blueprint $table) {
            $table->id();
            $table->string('initials', 10)->unique();
            $table->string('name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('action_officer_hr_mechanism', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_mechanism_id')->constrained()->cascadeOnDelete();
            $table->foreignId('action_officer_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(1)->comment('1 = first named');
            $table->unique(['hr_mechanism_id', 'action_officer_id'], 'mechanism_officer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_officer_hr_mechanism');
        Schema::dropIfExists('action_officers');
    }
};
