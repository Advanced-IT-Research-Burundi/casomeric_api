<?php

namespace App\Http\Controllers;

use App\Models\Assistance;
use App\Models\Cotisation;
use App\Models\Credit;
use App\Models\Membre;
use App\Models\Remboursement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    //
    public function getDashboardData()
    {
        // NOTE: this implementation assumes monetary columns are named 'montant'.
        // If your schema uses another column name (e.g. 'amount'), replace 'montant' accordingly.

        // Basic stats
        $cotisationsCeMois = Cotisation::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('montant');

        $cotisationsCumulees = Cotisation::sum('montant');

        $membresActifs = Membre::where('statut', 'actif')->count();
        $membresInactifs = Membre::where('statut', 'inactif')->count();

        $creditsTotal = Credit::sum('montant_total_rembourser');
        $creditsEnCours = Credit::where('statut', 'en cours')->count();

        $dettesEnAttente = Remboursement::where('statut', 'en attente')->sum('montant_paye');

        $demandesAssistance = Assistance::count();
        $aidesAccordees = Assistance::where('statut', 'accordee')->count();

        $totalRembourse = Remboursement::where('statut', 'rembourse')->sum('montant_paye');
        $tauxRecouvrement = 0;
        $baseRecouvrement = $totalRembourse + $dettesEnAttente;
        if ($baseRecouvrement > 0) {
            $tauxRecouvrement = round(($totalRembourse / $baseRecouvrement) * 100, 2);
        }

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
            $cotisationsValues[] = (float) Cotisation::whereYear('created_at', $dt->year)
                ->whereMonth('created_at', $dt->month)
                ->sum('montant');

            $creditsValuesForTrend[] = (float) Credit::whereYear('created_at', $dt->year)
                ->whereMonth('created_at', $dt->month)
                ->sum('montant_total_rembourser');

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
            $creditsAccordes6[] = (float) Credit::whereYear('created_at', $dt->year)
                ->whereMonth('created_at', $dt->month)
                ->sum('montant_total_rembourser');

            $rembourses6[] = (float) Remboursement::whereYear('created_at', $dt->year)
                ->whereMonth('created_at', $dt->month)
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
                'evolutionCotisations' => 0, // leave 0 or compute w/ previous month data if desired
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

    function dashboardMember(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        // KPI: member-specific
        $cotisationsCeMois = (float) Cotisation::where('membre_id', $user->id)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('montant');

        // Credits: total due, total paid, remaining
        $creditsTotalDue = (float) Credit::where('membre_id', $user->id)->sum('montant_total_rembourser');

        $creditsId = Credit::where('membre_id', $user->id)->pluck('id')->toArray();

        $creditsTotalPaid = (float) Remboursement::whereIn('credit_id', $creditsId)->sum('montant_paye');
        $creditsRemaining = max(0, $creditsTotalDue - $creditsTotalPaid);

        // Montants reçus en assistance (for member)
        $assistancesReceived = (float) Assistance::where('membre_id', $user->id)->sum('montant');

        // Anomalies / retards
        $anomaliesCount = (int) Credit::where('membre_id', $user->id)
            ->where(function ($q) {
                $q->where('statut', 'Anomalies')
                    ->orWhere('statut', 'anomalie')
                    ->orWhere('statut', 'anomalies');
            })->count();

        // Charts: last 12 months (cotisations / tendance) - member scope
        $labels12 = [];
        $cotisationsValues = [];
        $creditsValuesForTrend = [];
        $assistancesValuesForTrend = [];

        for ($i = 11; $i >= 0; $i--) {
            $dt = Carbon::now()->subMonths($i);
            $labels12[] = $dt->translatedFormat('M Y');

            $cotisationsValues[] = (float) Cotisation::where('membre_id', $user->id)
                ->whereYear('created_at', $dt->year)
                ->whereMonth('created_at', $dt->month)
                ->sum('montant');

            $creditsValuesForTrend[] = (float) Credit::where('membre_id', $user->id)
                ->whereYear('created_at', $dt->year)
                ->whereMonth('created_at', $dt->month)
                ->sum('montant_total_rembourser');

            $assistancesValuesForTrend[] = (float) Assistance::where('membre_id', $user->id)
                ->whereYear('created_at', $dt->year)
                ->whereMonth('created_at', $dt->month)
                ->sum('montant');
        }

        // Credits vs remboursements (last 6 months) + statuses per month for this member
        $labels6 = [];
        $creditsAccordes6 = [];
        $rembourses6 = [];
        $creditsStatus6 = []; // array of status counts per month

        for ($i = 5; $i >= 0; $i--) {
            $dt = Carbon::now()->subMonths($i);
            $labels6[] = $dt->translatedFormat('M Y');

            $creditsAccordes6[] = (float) Credit::where('membre_id', $user->id)
                ->whereYear('created_at', $dt->year)
                ->whereMonth('created_at', $dt->month)
                ->sum('montant_total_rembourser');

            // $rembourses6[] = (float) Remboursement::where('membre_id', $user->id)
            //     ->whereYear('created_at', $dt->year)
            //     ->whereMonth('created_at', $dt->month)
            //     ->sum('montant_paye');

            // statuses counts grouped by statut
            $statusRows = Credit::select('statut', DB::raw('COUNT(*) as total'))
                ->where('membre_id', $user->id)
                ->whereYear('created_at', $dt->year)
                ->whereMonth('created_at', $dt->month)
                ->groupBy('statut')
                ->get()
                ->pluck('total', 'statut')
                ->toArray();

            // normalize into known keys
            $creditsStatus6[] = [
                'approuve' => (int) ($statusRows['approuve'] ?? $statusRows['approuved'] ?? 0),
                'en_cours' => (int) ($statusRows['en cours'] ?? $statusRows['en_cours'] ?? $statusRows['en-cours'] ?? 0),
                'anomalies' => (int) ($statusRows['Anomalies'] ?? $statusRows['anomalies'] ?? $statusRows['anomalie'] ?? 0),
                'refuse' => (int) ($statusRows['refuse'] ?? $statusRows['refusé'] ?? 0),
                'autres' => (int) (array_sum($statusRows) - (
                    ($statusRows['approuve'] ?? 0) +
                    ($statusRows['en cours'] ?? 0) +
                    ($statusRows['Anomalies'] ?? 0) +
                    ($statusRows['refuse'] ?? 0)
                )),
            ];
        }

        $response = [
            'success' => true,
            'stats' => [
                'cotisationsMois' => $cotisationsCeMois,
                'creditsTotal' => $creditsTotalDue,
                'creditsTotalPaye' => $creditsTotalPaid,
                'creditsEnCours' => $creditsRemaining,
                'montantsAssistancesRecus' => $assistancesReceived,
                'anomalies' => $anomaliesCount,
            ],
            'charts' => [
                'cotisations' => [
                    'labels' => $labels12,
                    'values' => $cotisationsValues,
                ],
                'credits' => [
                    'labels' => $labels6,
                    'accordes' => $creditsAccordes6,
                    'rembourses' => $rembourses6 ?? [],
                    'statuses' => $creditsStatus6, // per-month status breakdown for the member
                ],
                'tendance' => [
                    'labels' => $labels12,
                    'cotisations' => $cotisationsValues,
                    'credits' => $creditsValuesForTrend,
                    'assistances' => $assistancesValuesForTrend,
                ],
            ],
        ];

        return sendResponse($response,"");
    }
}
