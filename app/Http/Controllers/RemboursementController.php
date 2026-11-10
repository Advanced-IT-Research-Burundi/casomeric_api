<?php

namespace App\Http\Controllers;

use App\Http\Requests\RemboursementStoreRequest;
use App\Http\Requests\RemboursementUpdateRequest;
use App\Models\Credit;
use App\Models\Remboursement;
use App\Services\RemboursementService;
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

    /**
     * Remboursement manuel : un montant payé sur un crédit, réparti sur les échéances
     * non payées (comme le paiement global). Plus de ligne ajoutée à côté de l'échéancier.
     */
    public function store(RemboursementStoreRequest $request, RemboursementService $service)
    {
        $credit = Credit::find($request->credit_id);
        if (!$credit) {
            return sendError("Crédit non trouvé.", [], Response::HTTP_NOT_FOUND);
        }
        if ($error = $this->denyUnlessCanRecordPayment($credit)) {
            return $error;
        }

        $credit = $service->payerCredit(
            $credit,
            (float) $request->montant_paye,
            $request->filled('date_paiement') ? Carbon::parse($request->date_paiement) : now(),
            RemboursementService::stockerPreuve($request->file('preuve_paiement')),
        );

        return sendResponse($credit, 'Remboursement enregistré avec succès', 201);
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
    public function approve(Request $request, Remboursement $remboursement, RemboursementService $service)
    {
        $request->validate([
            'montant_paye' => 'nullable|numeric|min:1',
            'date_paiement' => 'nullable|date',
            'preuve_paiement' => 'required|file|mimes:jpeg,png,jpg,pdf|max:2048',
        ]);

        if ($error = $this->denyUnlessCanRecordPayment($remboursement->credit)) {
            return $error;
        }

        if ($remboursement->statut === 'paye') {
            return sendError("L'échéance n° {$remboursement->numero_echeance} est déjà payée.", [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Sans montant : on paie tout ce qui reste dû sur l'échéance.
        // Un montant plus petit laisse l'échéance ouverte (paiement partiel).
        $service->payerEcheance(
            $remboursement,
            $request->filled('montant_paye') ? (float) $request->montant_paye : null,
            $request->filled('date_paiement') ? Carbon::parse($request->input('date_paiement')) : now(),
            RemboursementService::stockerPreuve($request->file('preuve_paiement')),
        );

        $remboursement->refresh();
        $message = $remboursement->statut === 'paye'
            ? 'Échéance payée avec succès'
            : 'Paiement partiel enregistré : il reste '.number_format($service->resteDu($remboursement), 0, ',', ' ').' BIF sur cette échéance';

        return sendResponse($remboursement, $message);
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
