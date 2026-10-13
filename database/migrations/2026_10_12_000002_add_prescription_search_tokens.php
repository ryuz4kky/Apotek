<?php

use App\Services\PrescriptionSearch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_search_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions');
            $table->string('scope', 10);
            $table->string('token', 64);
            $table->unique(['prescription_id', 'scope', 'token']);
            $table->index(['token', 'scope', 'prescription_id']);
        });
        DB::table('prescriptions')->select('id', 'patient_name', 'doctor')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $rx) {
                PrescriptionSearch::sync($rx->id, decrypt($rx->patient_name), $rx->doctor);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_search_tokens');
    }
};
