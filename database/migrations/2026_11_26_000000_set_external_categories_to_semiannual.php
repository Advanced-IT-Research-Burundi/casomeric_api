<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The configured amount remains a monthly amount; external services pay
     * six of those amounts together at the end of each calendar semester.
     */
    public function up(): void
    {
        DB::table('categorie_membres')
            ->whereIn('nom', [
                'service_externe_10',
                'service_externe_5',
                'Service Externe (Cadres)',
                'Service Externe (Secrétaires d’ambassade)',
            ])
            ->update(['frequence_paiement' => 'semestriel']);
    }

    public function down(): void
    {
        // The previous frequency may have been edited by an administrator;
        // it cannot be safely inferred during a rollback.
    }
};
