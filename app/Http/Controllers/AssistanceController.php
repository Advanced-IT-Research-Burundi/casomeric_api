<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistanceStoreRequest;
use App\Http\Requests\AssistanceUpdateRequest;
use App\Http\Resources\AssistanceResource;
use App\Http\Resources\TypeAssistanceResource;
use App\Mail\AccepteAssistance;
use App\Mail\AssistanceCreate;
use App\Mail\DemandeApprobationAssistance;
use App\Mail\RefuserAssistance;
use App\Models\Assistance;
use App\Models\Cotisation;
use App\Models\Credit;
use App\Models\Membre;
use App\Models\Notification;
use App\Models\TypeAssistance;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CaisseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Assistance pipeline (same steps as credits):
 *   en_attente  -> gestionnaire approves -> en_cours ("attente responsable")
 *   en_cours    -> responsable / admin approves -> approuve
 *   approuve    -> staff pays it out with a proof -> verse
 *   en_attente / en_cours -> rejete
 * Staff = admin, gestionnaire, responsable. Members only see and manage their own requests.
 */
class AssistanceController extends Controller
{
    private const STAFF = ['admin', 'gestionnaire', 'responsable'];

    // ---------------------------------------------------------------- decisions

    public function approuve($id)
    {
        $user = Auth::user();
        if (!$user->hasRoles(self::STAFF)) {
            return sendError("Vous n'avez pas la permission d'approuver cette assistance.", [], Response::HTTP_FORBIDDEN);
        }

        $assistance = Assistance::findOrFail($id);
        if ($error = $this->denyUnlessDecidable($assistance, 'approuvée')) {
            return $error;
        }

        if ($user->hasRole('gestionnaire')) {
            $responsables = User::where('role', 'responsable')->get();
            if ($responsables->isEmpty()) {
                return sendError(
                    "Aucun responsable n'est enregistré pour donner l'approbation finale.",
                    [],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $assistance->update(['statut' => 'en_cours']);

            $this->safely(fn () => Mail::to($responsables->pluck('email')->all())
                ->queue(new DemandeApprobationAssistance($assistance->load(['membre', 'typeAssistance']))));

            foreach ($responsables as $responsable) {
                Notification::addNotification(
                    "Une demande d'approbation d'assistance a été faite par {$user->name} pour le membre "
                        .$assistance->membre->nom.' '.$assistance->membre->prenom
                        .' pour un montant de '.$assistance->montant.' BIF',
                    $responsable->id,
                    "Demande d'approbation d'assistance",
                    'assistance'
                );
            }

            return sendResponse(new AssistanceResource($assistance), 'Assistance validée. La validation du responsable est requise.');
        }

        $assistance->forceFill([
            'statut' => 'approuve',
            'date_approbation' => now(),
            'approved_by' => $user->id,
        ])->save();

        Notification::addNotification(
            "Votre demande d'assistance a été approuvée par {$user->name} pour un montant de {$assistance->montant} BIF",
            $assistance->membre->user_id,
            "Approbation d'assistance",
            'assistance'
        );

        $this->safely(fn () => Mail::to($assistance->membre->email)
            ->cc(EMAIL_COPIES)
            ->queue(new AccepteAssistance($assistance->load(['membre', 'typeAssistance']))));

        return sendResponse(new AssistanceResource($assistance), 'Assistance approuvée avec succès.');
    }

    public function refuser(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user->hasRoles(self::STAFF)) {
            return sendError("Vous n'avez pas la permission de refuser cette assistance.", [], Response::HTTP_FORBIDDEN);
        }

        $request->validate(['comment' => 'required|string|max:1000'], [
            'comment.required' => 'Le motif du rejet est obligatoire.',
        ]);

        $assistance = Assistance::findOrFail($id);
        if ($error = $this->denyUnlessDecidable($assistance, 'refusée')) {
            return $error;
        }

        $assistance->update([
            'statut' => 'rejete',
            'motif_rejet' => $request->comment,
        ]);
        $assistance->forceFill(['rejected_by' => $user->id])->save();

        Notification::create([
            'type' => 'assistance',
            'title' => 'Assistance rejetée',
            'message' => "Votre demande d'assistance a été rejetée par {$user->name}. Motif : {$request->comment}",
            'time' => now(),
            'read' => false,
            'user_id' => $user->id,
            'assignee_id' => $assistance->membre->user_id,
        ]);

        $this->safely(fn () => Mail::to($assistance->membre->email)
            ->cc(EMAIL_COPIES)
            ->queue(new RefuserAssistance($assistance->load(['membre', 'typeAssistance']))));

        return sendResponse(new AssistanceResource($assistance), 'Assistance refusée avec succès.');
    }

    /**
     * Last step: the approved amount is paid to the member (with a proof).
     * The money comes from the fund (caisse): refused if the balance is too low.
     */
    public function verser(Request $request, $id, CaisseService $caisse)
    {
        $user = Auth::user();
        if (!$user->hasRoles(self::STAFF)) {
            return sendError("Vous n'avez pas la permission d'enregistrer un versement.", [], Response::HTTP_FORBIDDEN);
        }

        $request->validate([
            'preuve_versement' => 'required|file|mimes:jpeg,png,jpg,webp,pdf|max:5120',
            'date_versement' => 'nullable|date',
        ], [
            'preuve_versement.required' => 'La preuve de versement (chèque, bordereau…) est obligatoire.',
        ]);

        $assistance = Assistance::findOrFail($id);
        if ($assistance->statut !== 'approuve') {
            return sendError(
                "Seule une assistance approuvée peut être versée (statut : {$assistance->statut}).",
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // L'argent sort de la caisse : il faut que le solde le permette.
        // One pay-out at a time, so two pay-outs cannot both use the same money.
        $lock = Cache::lock('caisse-sortie', 30);
        if (!$lock->block(10)) {
            return sendError('Un autre versement est en cours. Réessayez dans un instant.', [], Response::HTTP_CONFLICT);
        }

        try {
            $solde = $caisse->solde();
            if ((float) $assistance->montant > $solde) {
                return sendError(
                    'Fonds insuffisants dans la caisse : solde '
                        .number_format($solde, 0, ',', ' ').' BIF, versement demandé '
                        .number_format((float) $assistance->montant, 0, ',', ' ').' BIF.',
                    ['caisse' => ['solde' => $solde]],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $dateVersement = $request->filled('date_versement') ? Carbon::parse($request->date_versement) : now();

            DB::transaction(function () use ($assistance, $request, $user, $dateVersement) {
                $assistance->forceFill([
                    'statut' => 'verse',
                    'date_versement' => $dateVersement,
                    'preuve_versement' => $this->storeFile($request->file('preuve_versement'), 'preuves_versements'),
                    'verse_by' => $user->id,
                ])->save();

                // Trace of the money leaving the fund.
                Transaction::create([
                    'membre_id' => $assistance->membre_id,
                    'type_transaction' => 'assistance',
                    'reference_transaction' => $assistance->id,
                    'montant' => $assistance->montant,
                    'devise' => 'FBU',
                    'sens' => 'sortie',
                    'date_transaction' => $dateVersement->toDateString(),
                    'description' => "Versement de l'assistance n° {$assistance->id} ("
                        .($assistance->typeAssistance?->nom ?? 'assistance').') depuis la caisse',
                ]);
            });
        } finally {
            $lock->release();
        }

        Notification::addNotification(
            "L'assistance de {$assistance->montant} BIF vous a été versée.",
            $assistance->membre->user_id,
            "Versement d'assistance",
            'assistance'
        );

        return sendResponse(new AssistanceResource($assistance->fresh()), 'Versement enregistré avec succès.');
    }

    // ----------------------------------------------------------------- requests

    /** A member asks for an assistance. */
    public function demandeAssistance(Request $request)
    {
        $request->validate([
            'type_assistance_id' => 'required|exists:type_assistances,id',
            'montant' => 'required|numeric|min:1|max:99999999',
            'motif' => 'nullable|string|max:2000',
            'document_justificatif' => 'required|file|mimes:jpeg,png,jpg,webp,pdf|max:5120',
        ], [
            'document_justificatif.required' => 'Le document justificatif est obligatoire.',
        ]);

        $membre = Membre::where('user_id', Auth::id())->first();
        if (!$membre) {
            return sendError('Membre non trouvé.', [], Response::HTTP_NOT_FOUND);
        }

        if ($error = $this->checkEligibility($membre)) {
            return $error;
        }
        if ($error = $this->checkAmount((int) $request->type_assistance_id, (float) $request->montant)) {
            return $error;
        }
        if (Assistance::where('membre_id', $membre->id)->whereIn('statut', ['en_attente', 'en_cours'])->exists()) {
            return sendError("Vous avez déjà une demande d'assistance en cours de traitement.", [], Response::HTTP_FORBIDDEN);
        }

        $assistance = DB::transaction(fn () => Assistance::create([
            'montant' => $request->montant,
            'motif' => $request->motif,
            'date_demande' => now(),
            'statut' => 'en_attente',
            'justificatif' => $this->storeFile($request->file('document_justificatif'), 'justificatifs'),
            'type_assistance_id' => $request->type_assistance_id,
            'membre_id' => $membre->id,
        ]));

        $this->notifyNewRequest($assistance);

        return sendResponse($assistance, 'Demande enregistrée avec succès', Response::HTTP_CREATED);
    }

    /** Staff enters a request for a member. It always starts "en attente". */
    public function store(AssistanceStoreRequest $request)
    {
        if (!Auth::user()->hasRoles(self::STAFF)) {
            return sendError("Vous n'avez pas la permission d'enregistrer une assistance.", [], Response::HTTP_FORBIDDEN);
        }

        $membre = Membre::findOrFail($request->membre_id);
        if ($error = $this->checkEligibility($membre)) {
            return $error;
        }
        if ($error = $this->checkAmount((int) $request->type_assistance_id, (float) $request->montant)) {
            return $error;
        }

        $assistance = Assistance::create([
            'membre_id' => $membre->id,
            'type_assistance_id' => $request->type_assistance_id,
            'montant' => $request->montant,
            'motif' => $request->motif,
            'date_demande' => $request->date_demande ?? now(),
            'statut' => 'en_attente',
            'justificatif' => $this->storeFile($request->file('justificatif'), 'justificatifs'),
        ]);

        $this->notifyNewRequest($assistance);

        return sendResponse($assistance, 'Assistance créée avec succès', Response::HTTP_CREATED);
    }

    /** Change type, amount or document while nothing is decided yet. */
    public function update(AssistanceUpdateRequest $request, Assistance $assistance)
    {
        if ($error = $this->denyUnlessEditable($assistance, 'modifiée')) {
            return $error;
        }
        if ($error = $this->checkAmount((int) $request->type_assistance_id, (float) $request->montant)) {
            return $error;
        }

        // Le statut et les dates ne changent que par approuver / refuser / verser.
        $data = collect($request->validated())
            ->only(['type_assistance_id', 'montant', 'date_demande', 'motif'])
            ->all();

        if ($request->hasFile('justificatif')) {
            $data['justificatif'] = $this->storeFile($request->file('justificatif'), 'justificatifs');
        }

        $assistance->update($data);

        return sendResponse(new AssistanceResource($assistance->fresh()), 'Assistance mise à jour avec succès.');
    }

    public function destroy(Request $request, Assistance $assistance)
    {
        if ($error = $this->denyUnlessEditable($assistance, 'supprimée')) {
            return $error;
        }

        $assistance->delete();

        return response()->noContent();
    }

    // -------------------------------------------------------------------- reads

    public function index(Request $request)
    {
        if (!Auth::user()->hasRoles(self::STAFF)) {
            return sendError("Vous n'avez pas accès à la liste des assistances. Consultez « Mes assistances ».", [], Response::HTTP_FORBIDDEN);
        }

        $query = Assistance::query()
            ->leftJoin('membres', 'assistances.membre_id', '=', 'membres.id')
            ->leftJoin('type_assistances', 'assistances.type_assistance_id', '=', 'type_assistances.id')
            ->select('assistances.*');

        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('assistances.statut', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('assistances.montant', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('membres.nom', 'like', "%{$searchTerm}%")
                    ->orWhere('membres.prenom', 'like', "%{$searchTerm}%")
                    ->orWhere('membres.matricule', 'like', "%{$searchTerm}%")
                    ->orWhere('type_assistances.nom', 'like', "%{$searchTerm}%");
            });
        }

        // Only known columns can be filtered / sorted (no column name taken from the request as is).
        $likeFilters = ['statut', 'montant', 'date_demande', 'date_approbation', 'date_versement'];
        foreach ((array) $request->input('filter', []) as $field => $value) {
            if (!$value) {
                continue;
            }
            if (in_array($field, $likeFilters, true)) {
                $query->where("assistances.{$field}", 'LIKE', "%{$value}%");
            } elseif (in_array($field, ['membre_id', 'type_assistance_id'], true)) {
                $query->where("assistances.{$field}", $value);
            }
        }

        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortField = $request->input('sort_field', 'created_at');
        if ($sortField === 'membre.full_name') {
            $query->orderBy('membres.nom', $sortOrder)->orderBy('membres.prenom', $sortOrder);
        } elseif ($sortField === 'type_assistance.nom') {
            $query->orderBy('type_assistances.nom', $sortOrder);
        } else {
            $sortable = ['id', 'created_at', 'statut', 'montant', 'date_demande', 'date_approbation', 'date_versement'];
            $field = in_array(str_replace('assistances.', '', $sortField), $sortable, true)
                ? str_replace('assistances.', '', $sortField)
                : 'created_at';
            $query->orderBy("assistances.{$field}", $sortOrder);
        }

        $perPage = min(100, max(1, (int) ($request->per_page ?? 15)));

        return sendResponse(
            $query->with(['membre', 'typeAssistance'])->paginate($perPage),
            'Assistances récupérées avec succès.'
        );
    }

    public function show(Request $request, Assistance $assistance)
    {
        $user = Auth::user();
        $isOwner = $assistance->membre?->user_id === $user->id;
        if (!$isOwner && !$user->hasRoles(self::STAFF)) {
            return sendError("Vous n'avez pas accès à cette assistance.", [], Response::HTTP_FORBIDDEN);
        }

        return sendResponse($assistance, "Détails de l'assistance récupérés avec succès.");
    }

    public function mesAssistances()
    {
        $membre = Membre::where('user_id', Auth::id())->first();
        if (!$membre) {
            return sendError('Membre non trouvé.', [], Response::HTTP_NOT_FOUND);
        }

        return sendResponse(
            Assistance::where('membre_id', $membre->id)->latest()->paginate(),
            'Assistances retrieved successfully.'
        );
    }

    /**
     * Totals count only what was really granted (approuve) or paid (verse).
     */
    public function dashboard(Request $request)
    {
        $accordees = Assistance::whereIn('statut', ['approuve', 'verse']);

        $data = [
            'total_assistances' => (clone $accordees)->count(),
            'total_montant_assiste' => (float) (clone $accordees)->sum('montant'),
            'total_montant_verse' => (float) Assistance::where('statut', 'verse')->sum('montant'),
            'total_demandes' => Assistance::count(),
            'en_attente' => Assistance::whereIn('statut', ['en_attente', 'en_cours'])->count(),
            'types_assistance' => TypeAssistanceResource::collection(TypeAssistance::get()),
            // The 5 latest granted assistances (filter first, then take 5).
            'details_assistances' => AssistanceResource::collection(
                (clone $accordees)->latest('date_approbation')->latest('id')->take(5)->get()
            ),
        ];

        return sendResponse($data, 'Données du tableau de bord des assistances récupérées avec succès.');
    }

    public function dashboardAdmin(Request $request)
    {
        return $this->dashboard($request);
    }

    // ------------------------------------------------------------------ helpers

    /** Approve / reject: gestionnaire only on "en attente", responsable / admin also on "en cours". */
    private function denyUnlessDecidable(Assistance $assistance, string $verb)
    {
        $allowed = Auth::user()->hasRole('gestionnaire')
            ? $assistance->isPending()
            : ($assistance->isPending() || $assistance->isWaitingResponsable());

        if (!$allowed) {
            return sendError(
                "Cette assistance ne peut pas être {$verb} à cette étape (statut : {$assistance->statut}).",
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return null;
    }

    /** Edit / delete: staff or the member who asked, only while "en attente". */
    private function denyUnlessEditable(Assistance $assistance, string $verb)
    {
        $user = Auth::user();
        $isOwner = $assistance->membre?->user_id === $user->id;

        if (!$isOwner && !$user->hasRoles(self::STAFF)) {
            return sendError("Vous n'avez pas la permission de modifier cette assistance.", [], Response::HTTP_FORBIDDEN);
        }
        if (!$assistance->isPending()) {
            return sendError(
                "Une assistance déjà traitée ne peut plus être {$verb} (statut : {$assistance->statut}).",
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return null;
    }

    /** No assistance while contributions are irregular or a credit is still being repaid. */
    private function checkEligibility(Membre $membre)
    {
        $hasIrregularCotisations = Cotisation::where('membre_id', $membre->id)
            ->whereIn('statut', ['en_attente', 'en_retard'])
            ->exists();

        // A credit being repaid is "approuve" (or "en_cours" with an approval date in older data).
        $hasUnpaidCredits = Credit::where('membre_id', $membre->id)
            ->where('montant_restant', '>', 0)
            ->where(function ($q) {
                $q->where('statut', 'approuve')
                    ->orWhere(fn ($q) => $q->where('statut', 'en_cours')->whereNotNull('date_approbation'));
            })
            ->exists();

        if ($hasIrregularCotisations || $hasUnpaidCredits) {
            return sendError(
                "Impossible de demander une assistance tant qu'il y a des cotisations irrégulières ou un crédit en cours de remboursement.",
                [],
                Response::HTTP_FORBIDDEN
            );
        }

        return null;
    }

    /** The amount cannot be above the standard amount of the type (when one is set). */
    private function checkAmount(int $typeId, float $montant)
    {
        $type = TypeAssistance::find($typeId);
        $plafond = (float) ($type?->montant_standard ?? 0);

        if ($plafond > 0 && $montant > $plafond) {
            return sendError(
                "Le montant demandé dépasse le montant prévu pour « {$type->nom} » ("
                    .number_format($plafond, 0, ',', ' ').' BIF).',
                ['montant' => ['Montant supérieur au montant standard du type.']],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return null;
    }

    /** All assistance files go to public/uploads/<folder>. Returns the stored path. */
    private function storeFile(?UploadedFile $file, string $folder): ?string
    {
        if (!$file || !$file->isValid()) {
            return null;
        }

        // Name computed once, so the stored path always matches the file on disk.
        $name = time().'_'.uniqid().'.'.strtolower($file->getClientOriginalExtension());
        $file->move(public_path("uploads/{$folder}"), $name);

        return "uploads/{$folder}/{$name}";
    }

    /** Notify gestionnaires (and copy by email); never fails the request. */
    private function notifyNewRequest(Assistance $assistance): void
    {
        $membre = $assistance->membre;

        foreach (User::where('role', 'gestionnaire')->get() as $gestionnaire) {
            Notification::addNotification(
                "Une nouvelle demande d'assistance a été effectuée par {$membre->nom} {$membre->prenom} pour un montant de {$assistance->montant} BIF",
                $gestionnaire->id,
                "Nouvelle demande d'assistance",
                'assistance'
            );
        }

        $this->safely(fn () => Mail::to(EMAIL_COPIES)->queue(new AssistanceCreate($assistance)));
    }

    /** Emails must not turn a saved action into an error for the user. */
    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('Assistance email could not be sent.', ['exception' => $e->getMessage()]);
        }
    }
}
