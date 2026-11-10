<?php

namespace App\Console\Commands;

use App\Models\Remboursement;
use Illuminate\Console\Command;

/**
 * Marks as "en_retard" every planned installment whose due date has passed
 * on a credit that is being repaid. Runs every day (routes/console.php).
 *
 * Late fees (pénalités) are not computed yet: they stay at 0 until the rule is decided.
 */
class MarquerEcheancesEnRetard extends Command
{
    protected $signature = 'remboursements:retards';

    protected $description = 'Marque en retard les échéances non payées dont la date est dépassée';

    public function handle(): int
    {
        $nombre = Remboursement::query()
            ->where('statut', 'prevu')
            ->whereDate('date_echeance', '<', today())
            ->whereHas('credit', function ($q) {
                $q->where('montant_restant', '>', 0)
                    ->where(function ($q) {
                        $q->where('statut', 'approuve')
                            ->orWhere(function ($q) {
                                $q->where('statut', 'en_cours')->whereNotNull('date_approbation');
                            });
                    });
            })
            ->update(['statut' => 'en_retard']);

        $this->info("{$nombre} échéance(s) marquée(s) en retard.");

        return self::SUCCESS;
    }
}
