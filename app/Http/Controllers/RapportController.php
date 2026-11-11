<?php

namespace App\Http\Controllers;

use App\Http\Requests\RapportStoreRequest;
use App\Http\Requests\RapportUpdateRequest;
use App\Http\Resources\RapportCollection;
use App\Http\Resources\RapportResource;
use App\Models\Rapport;
use App\Models\Cotisation;
use App\Models\Membre;
use App\Models\Credit;
use App\Services\RappelCotisationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RapportController extends Controller
{
    public function index(Request $request)
    {
        $totalCotisationsBif = Cotisation::where('devise', 'BIF')->count();
        $totalCotisationsUSD = Cotisation::where('devise', 'USD')->count();
        // $totalMembres = Cotisation::distinct('membre_id')->count('membre_id');
        $totalMembres = Membre::where('statut', 'actif')->count();
        $taux = 3;
        $cotisationsParMembre = Cotisation::selectRaw('membre_id, COUNT(*) as total, SUM(montant) as total_montant')
            ->groupBy('membre_id')
            ->with('membre')
            ->get();

        $data = [
            'total_cotisations_bif' => $totalCotisationsBif,
            'total_cotisations_usd' => $totalCotisationsUSD,
            'total_membres' => $totalMembres,
            'taux' => $taux,
            'cotisations_par_membre' => $cotisationsParMembre,
        ];

        return sendResponse($data, 'Données du tableau de bord des cotisations récupérées avec succès.');
    }



    public function store(RapportStoreRequest $request)
    {
        $rapport = Rapport::create($request->validated());

        return new RapportResource($rapport);
    }

    public function show(Request $request, Rapport $rapport)
    {
        return new RapportResource($rapport);
    }

    public function update(RapportUpdateRequest $request, Rapport $rapport)
    {
        $rapport->update($request->validated());

        return new RapportResource($rapport);
    }

    public function getCreditReport(Request $request)
    {
        $query = \App\Models\Credit::query();
        $this->applyFilters($query, $request);

        // Crédits dont l'argent a été versé (approuvés, terminés, ou "en_cours" avec une date d'approbation).
        $accordes = (clone $query)->where(function ($q) {
            $q->whereIn('statut', ['approuve', 'termine'])
                ->orWhere(fn ($q) => $q->where('statut', 'en_cours')->whereNotNull('date_approbation'));
        });

        $summary = [
            'total_demande' => (float) (clone $query)->sum('montant_demande'),
            'total_accorde' => (float) (clone $accordes)->sum('montant_accorde'),
            'count' => (clone $query)->count(),
            'total_restant' => (float) (clone $accordes)->sum('montant_restant'),
        ];

        $transactions = $query->with('membre')->latest()->get();

        return sendResponse(['summary' => $summary, 'transactions' => $transactions], 'Rapport de crédit récupéré avec succès.');
    }

    public function getAssistanceReport(Request $request)
    {
        $query = \App\Models\Assistance::query();
        $this->applyFilters($query, $request);

        $summary = [
            // Montant accordé : assistances approuvées ou versées.
            'total_montant' => (float) (clone $query)->whereIn('statut', ['approuve', 'verse'])->sum('montant'),
            'total_verse' => (float) (clone $query)->where('statut', 'verse')->sum('montant'),
            'count' => (clone $query)->count(),
            'approuve' => (clone $query)->whereIn('statut', ['approuve', 'verse'])->count(),
            'rejete' => (clone $query)->where('statut', 'rejete')->count(),
        ];

        $transactions = $query->with(['membre', 'typeAssistance'])->latest()->get();

        return sendResponse(['summary' => $summary, 'transactions' => $transactions], 'Rapport d\'assistance récupéré avec succès.');
    }

    public function getRemboursementReport(Request $request)
    {
        $query = \App\Models\Remboursement::query();
        
        if ($request->filled('categorie_id')) {
            $query->whereHas('credit.membre', function ($q) use ($request) {
                $q->where('categorie_id', $request->categorie_id);
            });
        }
        
        if ($request->filled('date_debut')) {
            $query->where('date_paiement', '>=', $request->date_debut);
        }
        
        if ($request->filled('date_fin')) {
            $query->where('date_paiement', '<=', $request->date_fin);
        }

        $summary = [
            'total_paye' => (float) (clone $query)->sum('montant_paye'),
            'total_penalite' => (float) (clone $query)->sum('penalite'),
            'count' => (clone $query)->count(),
            'en_retard' => (clone $query)->where('statut', 'en_retard')->count(),
        ];

        $transactions = $query->with('credit.membre')->latest()->get();

        return sendResponse(['summary' => $summary, 'transactions' => $transactions], 'Rapport de remboursement récupéré avec succès.');
    }

    public function getCotisationReport(Request $request)
    {
        $query = \App\Models\Cotisation::query();
        $this->applyFilters($query, $request);

        $transactions = $query->latest('date_paiement')->latest('id')->get();
        $payees = $transactions->whereIn('statut', ['paye', 'partiel']);

        // Le membre est identifié par son matricule (une cotisation importée peut ne pas avoir de membre_id).
        $membresParMatricule = Membre::with('categorie')
            ->whereIn('matricule', $payees->pluck('matricule')->filter()->unique())
            ->get()->keyBy('matricule');
        $categorie = fn ($c) => $c->membre?->categorie ?? $membresParMatricule->get($c->matricule)?->categorie;

        $parCategorie = $payees->groupBy(fn ($c) => $categorie($c)?->nom ?? 'Sans catégorie')
            ->map(fn ($lignes, $nom) => [
                'categorie' => $nom,
                'total_fbu' => (float) $lignes->where('devise', 'FBU')->sum('montant'),
                'total_usd' => (float) $lignes->where('devise', 'USD')->sum('montant'),
                'nombre' => $lignes->count(),
            ])->values();

        $summary = [
            // Montants collectés : cotisations payées seulement. La devise est stockée "FBU".
            'total_bif' => (float) $payees->where('devise', 'FBU')->sum('montant'),
            'total_usd' => (float) $payees->where('devise', 'USD')->sum('montant'),
            'count' => $transactions->count(),
            'en_retard' => $transactions->whereIn('statut', ['en_attente', 'en_retard'])->count(),
            'membres_cotisants' => $payees->map(fn ($c) => $c->matricule ?: 'id:' . $c->membre_id)->unique()->count(),
            'membres_actifs' => Membre::where('statut', 'actif')
                ->when($request->filled('categorie_id'), fn ($q) => $q->where('categorie_id', $request->categorie_id))
                ->count(),
            'par_categorie' => $parCategorie,
        ];

        return sendResponse(['summary' => $summary, 'transactions' => $transactions], 'Rapport de cotisation récupéré avec succès.');
    }

    /**
     * État mensuel des cotisations (export 4.4) : chaque membre actif, ce qu'il doit pour la
     * période du mois et ce qui a été enregistré pour son matricule.
     * Un membre semestriel est évalué sur le semestre qui contient le mois.
     */
    public function getEtatCotisations(Request $request, RappelCotisationService $rappels)
    {
        $request->validate(['mois' => 'required|date_format:Y-m']);
        $mois = Carbon::createFromFormat('Y-m', $request->mois)->startOfMonth();

        $membres = Membre::with('categorie')
            ->where('statut', 'actif')
            ->when($request->filled('categorie_id'), fn ($q) => $q->where('categorie_id', $request->categorie_id))
            ->orderBy('nom')->orderBy('prenom')
            ->get()
            ->filter(fn ($m) => $rappels->doitCotiser($m, $mois));

        $lignes = $membres->map(function (Membre $m) use ($rappels, $mois) {
            [$debut, $fin] = $rappels->periode($m, $mois);
            $paye = (float) Cotisation::duMembre($m)
                ->whereIn('statut', ['paye', 'partiel'])
                ->whereBetween('date_paiement', [$debut->toDateString(), $fin->toDateString()])
                ->sum('montant');

            return [
                'matricule' => $m->matricule,
                'nom' => $m->full_name,
                'categorie' => $m->categorie?->nom,
                'periode' => $rappels->libellePeriode($m, $mois),
                'devise' => $m->categorie?->devise ?? 'FBU',
                'montant_attendu' => (float) ($m->categorie?->montant_cotisation ?? 0),
                'montant_paye' => $paye,
                'statut' => $paye > 0 ? 'paye' : 'non_paye',
            ];
        })->values();

        $parCategorie = $lignes->groupBy(fn ($l) => $l['categorie'] ?? 'Sans catégorie')
            ->map(fn ($l, $nom) => [
                'categorie' => $nom,
                'devise' => $l->first()['devise'],
                'membres' => $l->count(),
                'payes' => $l->where('statut', 'paye')->count(),
                'attendu' => $l->sum('montant_attendu'),
                'collecte' => $l->sum('montant_paye'),
            ])->values();

        $summary = [
            'mois' => $mois->format('Y-m'),
            'libelle' => $mois->copy()->locale('fr')->translatedFormat('F Y'),
            'membres' => $lignes->count(),
            'payes' => $lignes->where('statut', 'paye')->count(),
            'non_payes' => $lignes->where('statut', 'non_paye')->count(),
            'collecte_fbu' => $lignes->where('devise', 'FBU')->sum('montant_paye'),
            'collecte_usd' => $lignes->where('devise', 'USD')->sum('montant_paye'),
            'par_categorie' => $parCategorie,
        ];

        return sendResponse(['summary' => $summary, 'lignes' => $lignes], 'État des cotisations récupéré avec succès.');
    }

    /**
     * Membres débiteurs (5.4) : crédits en cours de remboursement, restant dû,
     * échéances en retard et prochaine échéance.
     */
    public function getDebiteurs(Request $request)
    {
        $credits = Credit::with(['membre.categorie', 'remboursements'])
            ->where('montant_restant', '>', 0)
            ->where(function ($q) {
                $q->where('statut', 'approuve')
                    ->orWhere(fn ($q) => $q->where('statut', 'en_cours')->whereNotNull('date_approbation'));
            })
            ->when($request->filled('categorie_id'), fn ($q) => $q->whereHas('membre', fn ($q) => $q->where('categorie_id', $request->categorie_id)))
            ->get();

        $lignes = $credits->map(function (Credit $c) {
            $nonPayees = $c->remboursements->whereIn('statut', ['prevu', 'en_retard'])->sortBy('date_echeance');
            // En retard : marquée par la tâche de nuit, ou non payée et date dépassée.
            $enRetard = $nonPayees->filter(fn ($e) => $e->statut === 'en_retard' || $e->date_echeance < today());
            $prochaine = $nonPayees->first();

            return [
                'credit_id' => $c->id,
                'matricule' => $c->membre?->matricule,
                'nom' => $c->membre?->full_name,
                'categorie' => $c->membre?->categorie?->nom,
                'date_approbation' => $c->date_approbation,
                'montant_accorde' => (float) $c->montant_accorde,
                'total_rembourser' => (float) $c->montant_total_rembourser,
                'deja_paye' => (float) $c->remboursements->sum('montant_paye'),
                'restant_du' => (float) $c->montant_restant,
                'echeances_en_retard' => $enRetard->count(),
                'montant_en_retard' => (float) $enRetard->sum(fn ($e) => max(0, $e->montant_prevu - $e->montant_paye)),
                'prochaine_echeance' => $prochaine?->date_echeance,
                'montant_prochaine' => $prochaine ? max(0, (float) $prochaine->montant_prevu - (float) $prochaine->montant_paye) : 0,
            ];
        })->sortByDesc('montant_en_retard')->values();

        $summary = [
            'debiteurs' => $lignes->pluck('matricule')->unique()->count(),
            'credits' => $lignes->count(),
            'restant_du' => $lignes->sum('restant_du'),
            'en_retard' => $lignes->where('echeances_en_retard', '>', 0)->count(),
            'montant_en_retard' => $lignes->sum('montant_en_retard'),
        ];

        return sendResponse(['summary' => $summary, 'lignes' => $lignes], 'Liste des membres débiteurs récupérée avec succès.');
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('categorie_id')) {
            $dansCategorie = fn ($q) => $q->where('categorie_id', $request->categorie_id);
            if ($query->getModel() instanceof Cotisation) {
                $query->where(function ($q) use ($dansCategorie) {
                    $q->whereHas('membre', $dansCategorie)
                        ->orWhereIn('matricule', Membre::where($dansCategorie)->select('matricule'));
                });
            } else {
                $query->whereHas('membre', $dansCategorie);
            }
        }

        if ($request->filled('date_debut')) {
            $model = $query->getModel();
            $dateField = 'created_at';
            if ($model instanceof \App\Models\Cotisation) $dateField = 'date_paiement';
            elseif ($model instanceof \App\Models\Credit) $dateField = 'date_demande';
            elseif ($model instanceof \App\Models\Assistance) $dateField = 'created_at';
            
            $query->where($dateField, '>=', $request->date_debut);
        }

        if ($request->filled('date_fin')) {
            $model = $query->getModel();
            $dateField = 'created_at';
            if ($model instanceof \App\Models\Cotisation) $dateField = 'date_paiement';
            elseif ($model instanceof \App\Models\Credit) $dateField = 'date_demande';
            elseif ($model instanceof \App\Models\Assistance) $dateField = 'created_at';
            
            $query->where($dateField, '<=', $request->date_fin);
        }
    }
}
