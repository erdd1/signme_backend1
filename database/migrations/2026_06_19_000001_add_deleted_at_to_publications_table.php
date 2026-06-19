<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
            $table->index(['churchId', 'updated_at'], 'idx_publications_church_updated');
            $table->index(['churchId', 'deleted_at'], 'idx_publications_church_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropIndex('idx_publications_church_updated');
            $table->dropIndex('idx_publications_church_deleted');
            $table->dropSoftDeletes();
        });
    }
};