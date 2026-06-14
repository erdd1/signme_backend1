<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_logs', function (Blueprint $table) {
            // Augmenter la taille du champ errorMessage pour contenir les erreurs SQL complètes
            $table->text('errorMessage')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('signature_logs', function (Blueprint $table) {
            $table->string('errorMessage')->nullable()->change();
        });
    }
};
