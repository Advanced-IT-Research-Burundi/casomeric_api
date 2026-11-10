<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who decided / paid an assistance, and the proof of payment (versement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistances', function (Blueprint $table) {
            if (!Schema::hasColumn('assistances', 'preuve_versement')) {
                $table->string('preuve_versement', 255)->nullable()->after('justificatif');
            }
            if (!Schema::hasColumn('assistances', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('motif')->constrained('users');
            }
            if (!Schema::hasColumn('assistances', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('approved_by')->constrained('users');
            }
            if (!Schema::hasColumn('assistances', 'verse_by')) {
                $table->foreignId('verse_by')->nullable()->after('rejected_by')->constrained('users');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assistances', function (Blueprint $table) {
            foreach (['verse_by', 'rejected_by', 'approved_by'] as $column) {
                if (Schema::hasColumn('assistances', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            if (Schema::hasColumn('assistances', 'preuve_versement')) {
                $table->dropColumn('preuve_versement');
            }
        });
    }
};
