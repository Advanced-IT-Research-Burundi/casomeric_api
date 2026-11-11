<?php

namespace App\Http\Controllers;

use App\Models\Assistance;
use App\Models\Cotisation;
use App\Models\Credit;
use App\Models\Membre;
use App\Models\Remboursement;
use App\Services\RappelCotisationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    //
    public function getDashboardData()
    {
        // Cotisations encaissées (payées), rattachées au mois de la retenue (date_paiement).
        $cotisationsPayees = fn () => Cotisation::whereIn('statut', ['paye', 'partiel']);
        $cotisationsDuMois = fn (Carbon $mois) => (float) $cotisationsPayees()
            ->whereYear('date_paiement', $mois->year)
            ->whereMonth('date_paiement', $mois->month)
            ->sum('montant');

        $cotisationsCeMois = $cotisationsDuMois(now());
        $cotisationsMoisPrecedent = $cotisationsDuMois(now()->subMonthNoOverflow());
        $evolutionCotisations = $cotisationsMoisPrecedent > 0
            ? round(($cotisationsCeMois - $cotisationsMoisPrecedent) / $cotisationsMoisPrecedent * 100, 1)
            : 0;
        $cotisationsCumulees = (float) $cotisationsPayees()->sum('montant');

        $membresActifs = Membre::where('statut', 'actif')->count();
        $membresInactifs = Membre::where('statut', 'inactif')->count();

        // Crédits dont l'argent est sorti de la caisse ("en_cours" avec date = données anciennes).
        $creditsAccordes = fn () => Credit::where(function ($q) {
            $q->whereIn('statut', ['approuve', 'termine'])
                ->orWhere(fn ($q) => $q->where('statut', 'en_cours')->whereNotNull('date_approbation'));
        });
        $creditsTotal = (float) $creditsAccordes()->sum('montant_accorde');
        $creditsEnCours = $creditsAccordes()->where('montant_restant', '>', 0)->count();
        // Dettes à recouvrer : ce qui reste dû sur les crédits en remboursement.
        $dettesEnAttente = (float) $creditsAccordes()->where('montant_restant', '>', 0)->sum('montant_restant');

        $demandesAssistance = Assistance::count();
        $aidesAccordees = Assistance::whereIn('statut', ['approuve', 'verse'])->count();

        // Taux de recouvrement : payé / dû sur les échéances arrivées à terme.
        $echeancesEchues = Remboursement::whereDate('date_echeance', '<=', today());
        $duEchu = (float) (clone $echeancesEchues)->sum('montant_prevu');
        $tauxRecouvrement = $duEchu > 0
            ? round(min(100, (float) (clone $echeancesEchues)->sum('montant_paye') / $duEchu * 100), 2)
            : 0;

        // Solde de la caisse : même calcul que pour les versements d'assistance (CaisseService).
        $soldeDisponible = app(\App\Services\CaisseService::class)->solde();

        // Charts data
        // last 12 months for cotisations & tendance
        $labels12 = [];
        $cotisationsValues = [];
        $creditsValuesForTrend = [];
        $assistancesValuesForTrend = [];

        for ($i = 11; $i >= 0; $i--) {
            $dt = Carbon::now()->subMonths($i);
            $labels12[] = $dt->translatedFormat('M Y'); // ex: "déc. 2025" (requires locales)
            $cotisationsValues[] = $cotisationsDuMois($dt);

            $creditsValuesForTrend[] = (float) $creditsAccordes()->whereYear('date_approbation', $dt->year)
                ->whereMonth('date_approbation', $dt->month)
                ->sum('montant_accorde');

            $assistancesValuesForTrend[] = (float) Assistance::whereYear('created_at', $dt->year)
                ->whereMonth('created_at', $dt->month)
                ->sum('montant');
        }

        // credits vs remboursements (last 6 months)
        $labels6 = [];
        $creditsAccordes6 = [];
        $rembourses6 = [];
        for ($i = 5; $i >= 0; $i--) {
            $dt = Carbon::now()->subMonths($i);
            $labels6[] = $dt->translatedFormat('M Y');
            $creditsAccordes6[] = (float) $creditsAccordes()->whereYear('date_approbation', $dt->year)
                ->whereMonth('date_approbation', $dt->month)
                ->sum('montant_accorde');

            $rembourses6[] = (float) Remboursement::whereYear('date_paiement', $dt->year)
                ->whereMonth('date_paiement', $dt->month)
                ->sum('montant_paye');
        }

        // membres repartition
        $membresActifsCount = $membresActifs;
        $membresInactifsCount = $membresInactifs;

        // assistances par type
        $assistancesByType = Assistance::select('type_assistance_id', DB::raw('COUNT(*) as total'))
            ->groupBy('type_assistance_id')
            ->join('type_assistances', 'assistances.type_assistance_id', '=', 'type_assistances.id')
            ->select('type_assistances.nom as type', DB::raw('COUNT(assistances.id) as total'))
            ->groupBy('type_assistances.nom')
            ->orderByDesc('total')
            ->get();

        $assistLabels = $assistancesByType->pluck('type')->toArray();
        $assistValues = $assistancesByType->pluck('total')->toArray();

        $response = [
            'success' => true,
            'stats' => [
                'cotisationsMois' => (float) $cotisationsCeMois,
                'cotisationsCumulatif' => (float) $cotisationsCumulees,
                'evolutionCotisations' => $evolutionCotisations,
                'membresActifs' => $membresActifsCount,
                'membresInactifs' => $membresInactifsCount,
                'creditsTotal' => (float) $creditsTotal,
                'creditsEnCours' => $creditsEnCours,
                'dettesAttente' => (float) $dettesEnAttente,
                'demandesAssistance' => $demandesAssistance,
                'aidesAccordees' => $aidesAccordees,
                'tauxRecouvrement' => $tauxRecouvrement,
                'soldeDisponible' => (float) $soldeDisponible,
            ],
            'charts' => [
                'cotisations' => [
                    'labels' => $labels12,
                    'values' => $cotisationsValues,
                ],
                'membres' => [
                    'actifs' => $membresActifsCount,
                    'inactifs' => $membresInactifsCount,
                ],
                'credits' => [
                    'labels' => $labels6,
                    'accordes' => $creditsAccordes6,
                    'rembourses' => $rembourses6,
                ],
                'assistances' => [
                    'labels' => $assistLabels,
                    'values' => $assistValues,
                ],
                'tendance' => [
                    'labels' => $labels12,
                    'cotisations' => $cotisationsValues,
                    'credits' => $creditsValuesForTrend,
                    'assistances' => $assistancesValuesForTrend,
                ],
            ],
        ];

        return sendResponse($response, 'Dashboard data retrieved successfully.');
    }

    /**
     * Situation financière du membre connecté (identifié par son matricule).
     */
    function dashboardMember(Request $request, RappelCotisationService $rappels)
    {
        $membre = Membre::connecte();
        if (!$membre) {
            return sendError('Membre non trouvé.', [], 404);
        }

        return sendResponse($this->situation($membre, $rappels), 'Situation financière du membre récupérée avec succès.');
    }

    /** Situation financière d'un membre, consultée par le personnel (route réservée). */
    public function situationMembre(Membre $membre, RappelCotisationService $rappels)
    {
        return sendResponse($this->situation($membre, $rappels), 'Situation financière du membre récupérée avec succès.');
    }

    private function situation(Membre $membre, RappelCotisationService $rappels): array
    {

        $cotisations = fn () => Cotisation::duMembre($membre)->whereIn('statut', ['paye', 'partiel']);

        // Crédits dont l'argent a été versé et qui sont en cours de remboursement ou terminés.
        $credits = Credit::where('membre_id', $membre->id)
            ->where(function ($q) {
                $q->whereIn('statut', ['approuve', 'termine'])
                    ->orWhere(fn ($q) => $q->where('statut', 'en_cours')->whereNotNull('date_approbation'));
            })
            ->get();
        $creditIds = $credits->pluck('id');

        $echeances = Remboursement::whereIn('credit_id', $creditIds);
        $prochaineEcheance = (clone $echeances)
            ->whereIn('statut', ['prevu', 'en_retard', 'partiel'])
            ->orderBy('date_echeance')
            ->first();

        // Assistances réellement accordées (approuvées ou versées).
        $assistances = fn () => Assistance::where('membre_id', $membre->id)->whereIn('statut', ['approuve', 'verse']);

        $echeancesEnRetard = (clone $echeances)->where('statut', 'en_retard')->count();
        // Périodes des 12 derniers mois sans cotisation enregistrée pour ce matricule
        $membre->load('categorie');
        $periodesNonPayees = $rappels->periodesNonPayees($membre);
        $cotisationsEnRetard = count($periodesNonPayees);

        // Cotisations et assistances des 12 derniers mois
        $labels12 = [];
        $cotisationsValues = [];
        $assistancesValues = [];
        for ($i = 11; $i >= 0; $i--) {
            $dt = Carbon::now()->subMonths($i);
            $labels12[] = $dt->translatedFormat('M Y');
            $cotisationsValues[] = (float) $cotisations()
                ->whereYear('date_paiement', $dt->year)
                ->whereMonth('date_paiement', $dt->month)
                ->sum('montant');
            $assistancesValues[] = (float) $assistances()
                ->whereYear('date_demande', $dt->year)
                ->whereMonth('date_demande', $dt->month)
                ->sum('montant');
        }

        $response = [
            'membre' => [
                'matricule' => $membre->matricule,
                'nom' => $membre->full_name,
                'statut' => $membre->statut,
            ],
            'stats' => [
                'cotisationsMois' => (float) $cotisations()
                    ->whereYear('date_paiement', now()->year)
                    ->whereMonth('date_paiement', now()->month)
                    ->sum('montant'),
                'cotisationsTotal' => (float) $cotisations()->sum('montant'),
                'creditsTotal' => (float) $credits->sum('montant_total_rembourser'),
                'creditsTotalPaye' => (float) (clone $echeances)->sum('montant_paye'),
                'creditsEnCours' => (float) $credits->sum('montant_restant'),
                'prochaineEcheance' => $prochaineEcheance ? [
                    'credit_id' => $prochaineEcheance->credit_id,
                    'numero' => $prochaineEcheance->numero_echeance,
                    'date' => $prochaineEcheance->date_echeance,
                    'montant' => max(0, (float) $prochaineEcheance->montant_prevu - (float) $prochaineEcheance->montant_paye),
                ] : null,
                'montantsAssistancesRecus' => (float) $assistances()->sum('montant'),
                'echeancesEnRetard' => $echeancesEnRetard,
                'cotisationsEnRetard' => $cotisationsEnRetard,
                'periodesNonPayees' => $periodesNonPayees,
                'anomalies' => $echeancesEnRetard + $cotisationsEnRetard,
            ],
            'charts' => [
                'labels' => $labels12,
                'cotisations' => $cotisationsValues,
                'assistances' => $assistancesValues,
            ],
        ];

        return $response;
    }
}
