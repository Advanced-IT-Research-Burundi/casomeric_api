<?php

namespace App\Console\Commands;

use App\Services\RappelCotisationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Rappelle aux membres actifs les cotisations non enregistrées pour un mois
 * (par défaut le mois précédent), et envoie un récapitulatif au personnel.
 * Planifiée chaque mois (routes/console.php).
 */
class RappelerCotisations extends Command
{
    protected $signature = 'cotisations:rappels
                            {--mois= : Mois à vérifier (AAAA-MM), par défaut le mois précédent}
                            {--simulation : Affiche les membres concernés sans envoyer de notification}';

    protected $description = 'Envoie un rappel aux membres dont la cotisation du mois (ou du semestre) n\'est pas enregistrée';

    public function handle(RappelCotisationService $service): int
    {
        $mois = $this->option('mois')
            ? Carbon::createFromFormat('Y-m', $this->option('mois'))->startOfMonth()
            : now()->startOfMonth()->subMonth();

        $resultat = $service->envoyerRappels($mois, (bool) $this->option('simulation'));

        foreach ($resultat['membres'] as $ligne) {
            $this->line("  - {$ligne}");
        }
        $this->info(count($resultat['membres']) . " membre(s) sans cotisation, {$resultat['rappels']} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
