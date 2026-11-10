<?php

namespace App\Http\Controllers;

use App\Http\Requests\RemboursementStoreRequest;
use App\Http\Requests\RemboursementUpdateRequest;
use App\Models\Credit;
use App\Models\Remboursement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class RemboursementController extends Controller
{
    public function index(Request $request)
    {
        $query = Remboursement::with('credit.membre');
        // Search
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('montant_prevu', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('montant_paye', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('numero_echeance', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('statut', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('penalite', 'LIKE', "%{$searchTerm}%");
            })
                ->orWhereHas('credit.membre', function ($q) use ($searchTerm) {
                    $q->where('nom', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('prenom', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('matricule', 'LIKE', "%{$searchTerm}%");
                });
        }

        // Filters
        if ($request->has('filter')) {
            foreach ($request->filter as $field => $value) {
                if ($value) {
                    $query->where($field, 'LIKE', "%{$value}%");
                }
            }
        }

        // Sorting
        if ($request->has('sort_field') && $request->sort_field) {
            $query->orderBy(
                $request->sort_field,
                $request->sort_order ?? 'asc'
            );
        }

        // Pagination
        $perPage = $request->per_page ?? 10;
        $remboursements = $query->paginate($perPage);

        return sendResponse($remboursements, 'Remboursements récupérés avec succès');
    }

    public function store(RemboursementStoreRequest $request)
    {
        // Seach Credit ID 
        $credit = Credit::find($request->credit_id);
        if (!$credit) {
            return sendError("Crédit non trouvé.", [], Response::HTTP_NOT_FOUND);
        }
        if ($error = $this->denyUnlessCanRecordPayment($credit)) {
            return $error;
        }
        try {
            DB::beginTransaction();
            $remboursement = Remboursement::create($request->validated());

            if($request->montant_paye >  $credit->montant_restant){
               throw new \Exception("Le montant payé dépasse le montant restant du crédit.");
            }
            $credit->montant_restant -= $remboursement->montant_paye;
           
            if ($credit->montant_restant <= 0) {
                $credit->statut = 'termine';
                
            }
             $credit->save();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return sendError($e->getMessage());
        }

        return sendResponse($remboursement, 'Remboursement créé avec succès', 201);
    }

    public function show(Request $request, Remboursement $remboursement)
    {
        return sendResponse($remboursement, 'Remboursement récupéré avec succès');
    }

    public function update(RemboursementUpdateRequest $request, Remboursement $remboursement)
    {
        if ($error = $this->denyUnlessStaff()) {
            return $error;
        }

        $remboursement->update($request->validated());

        return sendResponse($remboursement, 'Remboursement mis à jour avec succès');
    }

    public function destroy(Request $request, Remboursement $remboursement)
    {
        if ($error = $this->denyUnlessStaff()) {
            return $error;
        }

        $remboursement->delete();

        return response()->noContent();
    }

    /**
     * Permet à un administrateur de marquer une échéance comme payée.
     */
    public function approve(Request $request, Remboursement $remboursement)
    {
        $request->validate([
            'montant_paye' => 'nullable|numeric|min:0',
            'date_paiement' => 'nullable|date',
            'preuve_paiement' => 'required|file|mimes:jpeg,png,jpg,pdf|max:2048',
        ]);

        if ($error = $this->denyUnlessCanRecordPayment($remboursement->credit)) {
            return $error;
        }

        $path = null;
        if ($request->hasFile('preuve_paiement') && $request->file('preuve_paiement')->isValid()) {
            $file = $request->file('preuve_paiement');
            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/preuves_paiements'), $filename);
            $path = 'uploads/preuves_paiements/' . $filename;
        }

        $montantPaye = $request->input('montant_paye', $remboursement->montant_prevu);
        // The request sends a date as text: turn it into a date before comparing.
        $datePaiement = $request->filled('date_paiement')
            ? Carbon::parse($request->input('date_paiement'))
            : now();

        // Mettre à jour le statut (en retard si paiement après la date d'échéance)
        $statut = 'paye';
        $penalite = $remboursement->penalite ?? 0;

        if ($datePaiement->greaterThan($remboursement->date_echeance)) {
            $statut = 'paye';
            // Ici vous pouvez appliquer une logique de calcul de pénalité si nécessaire
        }

        $remboursement->update([
            'montant_paye' => $montantPaye,
            'date_paiement' => $datePaiement,
            'statut' => $statut,
            'penalite' => $penalite,
            'preuve_paiement' => $path,
        ]);

        // Mettre à jour le crédit lié
        $credit = $remboursement->credit()->with('remboursements')->first();

        $totalPaye = $credit->remboursements->sum('montant_paye');
        $credit->montant_restant = max(0, $credit->montant_total_rembourser - $totalPaye);
        $credit->statut = $credit->montant_restant <= 0 ? 'termine' : 'en_cours';
        $credit->save();

        return sendResponse($remboursement->fresh(), 'Échéance approuvée (payée) avec succès');
    }

    /**
     * Only staff can record or change repayments.
     */
    private function denyUnlessStaff()
    {
        if (!Auth::user()?->hasRoles(['admin', 'gestionnaire', 'responsable'])) {
            return sendError("Vous n'avez pas la permission de gérer les remboursements.", [], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * A repayment can only be recorded by staff, on a credit that is granted and not finished.
     */
    private function denyUnlessCanRecordPayment(?Credit $credit)
    {
        if ($error = $this->denyUnlessStaff()) {
            return $error;
        }

        if (!$credit || !$credit->isInRepayment()) {
            return sendError(
                "Ce crédit n'est pas en remboursement (statut : " . ($credit->statut ?? 'inconnu') . ").",
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return null;
    }
}
