<?php

namespace App\Services;

use App\Models\Assistance;
use App\Models\Configuration;
use App\Models\Cotisation;
use App\Models\Credit;
use App\Models\Remboursement;

/**
 * The fund (caisse): how much money it holds.
 *
 *   solde = solde initial (configuration "solde_initial_caisse")
 *         + cotisations payées
 *         + remboursements reçus (montant_paye)
 *         − crédits accordés (montant_accorde des crédits approuvés / terminés)
 *         − assistances versées
 *
 * Computed from the source tables, so it is always in step with them.
 * Money for an assistance is taken from the fund when it is paid out ("verse").
 */
class CaisseService
{
    public const CLE_SOLDE_INITIAL = 'solde_initial_caisse';

    /** @return array<string, float> */
    public function details(): array
    {
        $soldeInitial = (float) (Configuration::where('cle', self::CLE_SOLDE_INITIAL)->value('valeur') ?? 0);

        $cotisations = (float) Cotisation::where('statut', 'paye')->sum('montant');
        $remboursements = (float) Remboursement::sum('montant_paye');

        // Credits whose money has left the fund: granted (approuve), repaid (termine),
        // or older data left "en_cours" after approval.
        $credits = (float) Credit::where(function ($q) {
            $q->whereIn('statut', ['approuve', 'termine'])
                ->orWhere(fn ($q) => $q->where('statut', 'en_cours')->whereNotNull('date_approbation'));
        })->sum('montant_accorde');

        $assistancesVersees = (float) Assistance::where('statut', 'verse')->sum('montant');
        // Approved but not paid yet: still in the fund, but already promised.
        $assistancesAVerser = (float) Assistance::where('statut', 'approuve')->sum('montant');

        $entrees = $soldeInitial + $cotisations + $remboursements;
        $sorties = $credits + $assistancesVersees;
        $solde = round($entrees - $sorties, 2);

        return [
            'solde_initial' => $soldeInitial,
            'cotisations' => $cotisations,
            'remboursements' => $remboursements,
            'credits_accordes' => $credits,
            'assistances_versees' => $assistancesVersees,
            'total_entrees' => round($entrees, 2),
            'total_sorties' => round($sorties, 2),
            'solde' => $solde,
            'assistances_a_verser' => $assistancesAVerser,
            'solde_disponible' => round($solde - $assistancesAVerser, 2),
        ];
    }

    public function solde(): float
    {
        return $this->details()['solde'];
    }
}
