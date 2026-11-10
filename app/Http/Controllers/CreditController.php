<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreditStoreRequest;
use App\Http\Requests\CreditUpdateRequest;
use App\Mail\AccepteCredit;
use App\Mail\DemandeApprobation;
use App\Mail\DemandeCredit;
use App\Mail\RefuserCredit;
use App\Models\Cotisation;
use App\Models\CotisationMensuelle;
use App\Models\Credit;
use App\Models\Membre;
use App\Models\Notification;
use App\Models\User;
use App\Models\Remboursement;
use App\Services\RemboursementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use App\Events\NotificationSent;
use App\Models\Configuration;
use Illuminate\Support\Facades\Auth;

use function PHPSTORM_META\type;

class CreditController extends Controller
{
    
    
    public function approuveCredit(Request $request, $id)
    {
        
        // check role for connected user 
        if(!Auth::user()->hasRoles(['admin','gestionnaire','responsable'])){
            return sendError("Vous n'avez pas la permission d'approuver ce crédit.", [], Response::HTTP_FORBIDDEN);
        }
        $credit = Credit::findOrFail($id);
        $user = Auth::user();

        // Étape 1 : un gestionnaire ne peut traiter qu'un crédit "en attente".
        // Étape 2 : responsable / admin peuvent traiter "en attente" ou "attente responsable".
        $allowed = $user->hasRole('gestionnaire')
            ? $credit->isPending()
            : ($credit->isPending() || $credit->isWaitingResponsable());

        if (!$allowed) {
            return sendError(
                "Ce crédit ne peut pas être approuvé à cette étape (statut : {$credit->statut}).",
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if($user->hasRole('gestionnaire') ){
            $responsables = User::where('role', 'responsable')->get();
            if ($responsables->isEmpty()) {
                return sendError(
                    "Aucun responsable n'est enregistré pour donner l'approbation finale.",
                    [],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $credit->update([
                'statut' => 'en_cours',
                ]
            );
            $responsableEmail = $responsables->pluck('email')->toArray();

            Mail::to($responsableEmail )
            ->send(new DemandeApprobation($credit->load('membre')));
            foreach ($responsables as $responsable) {
                Notification::addNotification(
                    'Une demande d\'approbation de crédit a été faite par ' . $user->name . ' pour le membre ' . $credit->membre->nom . ' ' . $credit->membre->prenom . ' pour un montant de ' . $credit->montant_demande . ' BIF',
                    $responsable->id,
                    'Demande d\'approbation de crédit',
                    'credit'
                );
            }

        }else{
            try {
                DB::beginTransaction();
                // La date d'approbation doit être connue AVANT de générer les échéances :
                // la première échéance tombe un mois après l'approbation.
                $credit->fill([
                    'statut' => 'approuve',
                    'date_approbation' => now(),
                    'date_fin' => now()->addMonths($credit->duree_mois ?? 12),
                    'approved_by' => Auth::id(),
                    // Au moment de l'approbation, on considère que le montant accordé est celui demandé si non défini
                    'montant_accorde' => $credit->capital(),
                ]);
                // Recalcul sur le montant accordé avant de créer les échéances.
                $credit->recalculer();
                $credit->montant_restant = $credit->montant_total_rembourser;
                $credit->save();
                $this->generateEcheances($credit->fresh());

                 Notification::addNotification(
                'Approbation de crédit a été faite par ' . auth()->user()->name . ' pour le membre ' . $credit->membre->nom . ' ' . $credit->membre->prenom . ' pour un montant de ' . $credit->montant_demande . ' BIF',
                    $credit->membre->user_id,
                'Demande d\'approbation de crédit',
                'credit'
            );

                Mail::to($credit->membre->email)
                ->cc(EMAIL_COPIES)
                ->queue(new AccepteCredit($credit->load('membre')));
                
                DB::commit();
                
            }catch (\Throwable $th) {
                DB::rollBack();
                throw $th;
            }
            // Générer / régénérer les échéances à l'approbation
        } 
        return sendResponse($credit, 'Crédit approuvé avec succès.');
    }
    
    public function refuserCredit(Request $request, $id)
    {
        // Get Credit ID et update statut to refuser et send email to member
        $credit = Credit::findOrFail($id);
        $user = Auth::user();

        if (!$user->hasRoles(['admin', 'gestionnaire', 'responsable'])) {
            return sendError("Vous n'avez pas la permission de refuser ce crédit.", [], Response::HTTP_FORBIDDEN);
        }

        // Même règle que l'approbation : le gestionnaire seulement sur "en attente",
        // le responsable / admin aussi sur "attente responsable".
        $allowed = $user->hasRole('gestionnaire')
            ? $credit->isPending()
            : ($credit->isPending() || $credit->isWaitingResponsable());

        if (!$allowed) {
            return sendError(
                "Ce crédit ne peut plus être refusé (statut : {$credit->statut}).",
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $credit->update([
            'statut' => 'rejete',
            'rejected_by' => Auth::id(),
            'commentaire' => $request->comment ?? "",
        ]);
        
        try {
            //code...
            Mail::to($credit->membre->email)
            ->cc(EMAIL_COPIES)
            ->queue(new RefuserCredit($credit->load('membre')));
            Notification::create([
                'type' => 'credit',
                'title' => 'Credit rejeté',
                'message' => 'Le credit a ete rejeté par ' . $credit->membre->nom . ' ' . $credit->membre->prenom . ' pour un montant de ' . $credit->montant_demande . ' BIF',
                'time' => now(),
                'read' => false,
                'user_id' => Auth::id(),
                'assignee_id' => $credit->membre->user_id,
            ]);
        } catch (\Throwable $th) {
            //throw $th;
        }
        return sendResponse($credit, 'Credit refuser successfully.');
    }
    public function demandeCredit(Request $request)
    {
        
        $Email_admin = User::where('role', 'admin')->orWhere('role', 'gestionnaire')->pluck('email')->toArray();
        
        // Le membre choisit le montant, la durée et le motif. Le taux vient de la configuration
        // et le total / la mensualité sont calculés ici : les valeurs envoyées par le navigateur sont ignorées.
        $request->validate([
            'montant_demande' => 'required|numeric|min:1|max:99999999',
            'duree_mois' => 'required|integer|min:1|max:120',
            'motif' => 'nullable|string',
        ]);
        
        try {
            $membre = Membre::where('user_id', Auth::id())->first();
            if (!$membre) {
                return sendError("Membre non trouvé.", [], Response::HTTP_NOT_FOUND);
            }
        } catch (\Exception $e) {
            return sendError($e->getMessage());
        }
        // ✅ Check business rules before creating credit
        $hasIrregularCotisations = Cotisation::where('membre_id', $membre->id)
        ->whereIn('statut', ['en_attente', 'en_retard'])
        ->exists();
        
        $hasUnpaidCredits = Credit::where('membre_id', $membre->id)
        ->where('montant_restant', '!=', 0)
        ->exists();
        
        // if ($hasIrregularCotisations || $hasUnpaidCredits) {
        //     return sendError(
        //         'Vous ne pouvez pas demander un crédit tant que vous avez des cotisations irrégulières ou des crédits impayés.',
        //         [],
        //         Response::HTTP_FORBIDDEN
        //     );
        // }
        
        try {
            DB::beginTransaction();
            //code...
            $tauxInteret = Credit::tauxParDefaut();
            $calcul = Credit::calculerRemboursement(
                (float) $request->montant_demande,
                $tauxInteret,
                (int) $request->duree_mois
            );

            $credit = Credit::create([
                'montant_demande' => $request->montant_demande,
                'taux_interet' => $tauxInteret,
                'duree_mois' => $request->duree_mois,
                'montant_total_rembourser' => $calcul['montant_total_rembourser'],
                'montant_mensualite' => $calcul['montant_mensualite'],
                'membre_id' => $membre->id,
                'created_by' => Auth::id(),
                'statut' => 'en_attente',
                'date_demande' => now(),
                'motif' => $request->motif,
                'montant_accorde' => 0,
                'user_id' => Auth::id(),
            ]);
            
            Notification::create([
                'type' => 'credit',
                'title' => 'Nouvelle demande de credit',
                'message' => 'Une nouvelle demande de credit a ete effectuee par ' . $membre->nom . ' ' . $membre->prenom . ' pour un montant de ' . $request->montant_demande . ' BIF',
                'time' => now(),
                'read' => false,
                'user_id' => Auth::id(),
            ]);
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            return sendError($th->getMessage());
        }
        try {
            // Envoie de l'email a l'admin
            Mail::to($Email_admin)
            ->cc(auth()->user()->email)
            ->queue(new DemandeCredit($credit->load('membre')));
        } catch (\Exception $e) {
            return sendError($e->getMessage());
        }
        return sendResponse($credit, 'Credit created successfully.');
    }
    public function mesCredits()
    {
        try {
            $membre = Membre::where('user_id', Auth::id())->first();
            if (!$membre) {
                return sendError("Membre non trouvé.", [], Response::HTTP_NOT_FOUND);
            }
            $credits = Credit::where('membre_id', $membre->id)->latest()->paginate();
            return sendResponse($credits, 'Credits retrieved successfully.');
        } catch (\Exception $e) {
            return sendError($e->getMessage());
        }
    }
    
    
    public function store(CreditStoreRequest $request)
    {
        
        $date_fin = now()->addMonths($request->duree_mois ?? 12);
        //$date_fin = $request->has('date_fin') ? $request->date_approbation->now()->addMonths(12) : null;
        $hasIrregularCotisations = Cotisation::where('membre_id', $request->membre_id)
        ->whereIn('statut', ['en_attente', 'en_retard'])
        ->exists();
        
        $hasUnpaidCredits = Credit::where('membre_id', $request->membre_id)
        ->where('montant_restant', '!=', 0)
        ->exists();
        
        // if ($hasIrregularCotisations || $hasUnpaidCredits) {
        //     return sendError(
        //         'Vous ne pouvez pas demander un crédit tant que vous avez des cotisations irrégulières ou des crédits impayés.',
        //         [],
        //         Response::HTTP_FORBIDDEN
        //     );
        // }
        $data = $request->validated();
        if (!isset($data['taux_interet']) || $data['taux_interet'] === '' || $data['taux_interet'] === null) {
            $data['taux_interet'] = Credit::tauxParDefaut();
        }
        $data['montant_accorde'] = $data['montant_accorde'] ?? 0;

        $credit = new Credit(array_merge($data, [
            'created_by' => Auth::id(),
            'date_fin' => $date_fin,
            'user_id' => Auth::id(),
        ]));
        // Total et mensualité toujours calculés par le serveur.
        $credit->recalculer()->save();
        
        // Générer automatiquement les échéances pour ce crédit
        $this->generateEcheances($credit);
        
        return sendResponse($credit->load('remboursements'), 'Credit created successfully.');
    }
    
    public function index(Request $request)
    {
        // The full list (with members' details) is for staff; members use "Mes crédits".
        if (!Auth::user()->hasRoles(['admin', 'gestionnaire', 'responsable'])) {
            return sendError("Vous n'avez pas accès à la liste des crédits. Consultez « Mes crédits ».", [], Response::HTTP_FORBIDDEN);
        }

        $query = Credit::query()
            ->leftJoin('membres', 'credits.membre_id', '=', 'membres.id')
            ->select('credits.*');
        
        // 🔎 Recherche : par nom ou prénom du membre, motif, ID ou matricule
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('membres.nom', 'like', "%$search%")
                    ->orWhere('membres.prenom', 'like', "%$search%")
                    ->orWhere('membres.matricule', 'like', "%$search%")
                    ->orWhere('credits.motif', 'like', "%$search%")
                    ->orWhere('credits.id', 'like', "%$search%");
            });
        }
        
        // 📌 Filtres
        if ($request->filled('statut')) {
            $query->where('credits.statut', $request->statut);
        }
        
        if ($request->filled('date_demande_start')) {
            $query->whereDate('credits.date_demande', '>=', $request->date_demande_start);
        }
        
        if ($request->filled('date_demande_end')) {
            $query->whereDate('credits.date_demande', '<=', $request->date_demande_end);
        }
        
        if ($request->filled('date_fin')) {
            $query->whereDate('credits.date_fin', '>=', $request->date_fin);
        }
        
        // 🔽 Tri dynamique
        $sortField = $request->input('sort_field', 'credits.created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        if ($sortField === 'membre.full_name') {
            $query->orderBy('membres.nom', $sortOrder)->orderBy('membres.prenom', $sortOrder);
        } else {
            // Ensure table prefix for common fields
            $actualSortField = in_array($sortField, ['id', 'created_at', 'statut', 'montant_demande', 'date_demande', 'date_fin']) 
                ? "credits.{$sortField}" 
                : $sortField;
            $query->orderBy($actualSortField, $sortOrder);
        }
        
        // 📄 Pagination dynamique
        $perPage = $request->input('per_page', 15);
        $credits = $query->with(['membre', 'remboursements'])->paginate($perPage);
        
        // Append calculated fields for the list
        $credits->getCollection()->transform(function ($credit) {
            $credit->montant_deja_paye = $credit->remboursements->sum('montant_paye');
            $credit->total_penalites = $credit->remboursements->sum('penalite');
            return $credit;
        });
        
        return sendResponse($credits, 'Credits retrieved successfully.');
    }
    
    public function show(Request $request, Credit $credit)
    {
        // Staff, or the member the credit belongs to.
        $user = Auth::user();
        if (!$user->hasRoles(['admin', 'gestionnaire', 'responsable']) && $credit->membre?->user_id !== $user->id) {
            return sendError("Vous n'avez pas accès à ce crédit.", [], Response::HTTP_FORBIDDEN);
        }

        $credit->load(['membre', 'remboursements']);
        
        $totalPaye = $credit->remboursements->sum('montant_paye');
        $totalPenalites = $credit->remboursements->sum('penalite');
        $echeancesRestantes = $credit->remboursements
        ->whereIn('statut', ['prevu', 'en_retard'])
        ->count();
        $echeancesEnRetard = $credit->remboursements
        ->where('statut', 'en_retard')
        ->count();
        
        $credit->montant_total_endette = $credit->montant_total_rembourser;
        $credit->montant_deja_paye = $totalPaye;
        $credit->echeances_restantes = $echeancesRestantes;
        $credit->echeances_en_retard = $echeancesEnRetard;
        $credit->total_penalites = $totalPenalites;
        
        return sendResponse($credit, 'Credit retrieved successfully.');
    }
    
    public function update(CreditUpdateRequest $request, Credit $credit)
    {
        if ($error = $this->denyUnlessEditable($credit, 'modifié')) {
            return $error;
        }

        // Le statut et les dates de décision ne changent que par approuver / refuser,
        // et le total / la mensualité sont toujours recalculés ici.
        $data = collect($request->validated())
            ->except(['statut', 'date_approbation', 'date_fin', 'montant_total_rembourser', 'montant_mensualite'])
            ->reject(fn ($value, $key) => $value === null && in_array($key, ['montant_accorde', 'taux_interet']))
            ->all();

        $credit->fill($data)->recalculer()->save();

        return sendResponse($credit, 'Credit updated successfully.');
    }
    
    public function destroy(Request $request, Credit $credit)
    {
        if ($error = $this->denyUnlessEditable($credit, 'supprimé')) {
            return $error;
        }

        $credit->delete();
        return response()->noContent();
    }

    /**
     * Edit / delete: staff only, and only while nothing has been decided ("en attente").
     */
    private function denyUnlessEditable(Credit $credit, string $verb)
    {
        if (!Auth::user()->hasRoles(['admin', 'gestionnaire', 'responsable'])) {
            return sendError("Vous n'avez pas la permission de modifier les crédits.", [], Response::HTTP_FORBIDDEN);
        }

        if (!$credit->isPending()) {
            return sendError(
                "Un crédit déjà traité ne peut plus être {$verb} (statut : {$credit->statut}).",
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return null;
    }
    
    /**
     * Paiement libre : distribue un montant sur les échéances non payées.
     */
    public function payer(Request $request, $id, RemboursementService $service)
    {
        $request->validate([
            'montant_paye' => 'required|numeric|min:1',
            'date_paiement' => 'nullable|date',
            'preuve_paiement' => 'required|file|mimes:jpeg,png,jpg,pdf|max:2048',
        ]);

        $credit = Credit::findOrFail($id);

        if (!Auth::user()->hasRoles(['admin', 'gestionnaire', 'responsable'])) {
            return sendError("Vous n'avez pas la permission d'enregistrer un paiement.", [], Response::HTTP_FORBIDDEN);
        }

        if (!$credit->isInRepayment()) {
            return sendError(
                "Ce crédit n'est pas en remboursement (statut : {$credit->statut}).",
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ((float) $request->montant_paye > (float) $credit->montant_restant + 0.009) {
            return sendError("Le montant dépasse le montant restant du crédit.", [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Répartition sur les échéances non payées, mise à jour du restant / statut,
        // et trace dans cotisation_mensuelles : tout est fait par RemboursementService.
        $credit = $service->payerCredit(
            $credit,
            (float) $request->montant_paye,
            $request->filled('date_paiement') ? Carbon::parse($request->date_paiement) : now(),
            RemboursementService::stockerPreuve($request->file('preuve_paiement')),
        );

        return sendResponse($credit, 'Paiement effectué et distribué avec succès.');
    }

    /**
    * Génère les échéances (remboursements prévus) pour un crédit donné.
    */
    protected function generateEcheances(Credit $credit): void
    {
        // On supprime d'abord d'éventuelles anciennes échéances pour éviter les doublons
        try{
            
            DB::beginTransaction();
            $credit->remboursements()->delete();
            $credit->remboursements()->delete();
            $duree = $credit->duree_mois ?? 12;
            $montantMensualite = $credit->montant_mensualite;
            $dateDepart = $credit->date_approbation ?? $credit->date_demande ?? now();
            
            // La dernière échéance absorbe l'écart d'arrondi : la somme des échéances = total à rembourser.
            $montantDerniere = $credit->montant_total_rembourser - $montantMensualite * ($duree - 1);

            for ($i = 1; $i <= $duree; $i++) {
                Remboursement::create([
                    'credit_id' => $credit->id,
                    'numero_echeance' => $i,
                    'montant_prevu' => $i === $duree ? $montantDerniere : $montantMensualite,
                    'montant_paye' => 0,
                    'date_echeance' => $dateDepart->copy()->addMonths($i),
                    'date_paiement' => null,
                    'statut' => 'prevu',
                    'penalite' => 0,
                ]);
            }
            
            // Mettre à jour le montant restant sur le crédit
            $credit->montant_restant = $credit->montant_total_rembourser - $credit->remboursements()->sum('montant_paye');
            $credit->save();
            DB::commit();
        }catch (\Throwable $th) {
            
            DB::rollBack();
            throw $th;
        }
        
    }
    
    
}
