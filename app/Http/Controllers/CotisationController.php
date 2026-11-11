<?php

namespace App\Http\Controllers;

use App\Http\Requests\CotisationStoreRequest;
use App\Http\Requests\CotisationUpdateRequest;
use App\Http\Resources\CotisationCollection;
use App\Http\Resources\CotisationResource;
use App\Models\Cotisation;
use App\Models\CotisationMensuelle;
use App\Models\Membre;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CotisationController extends Controller
{

    public function remboursementsMensuelles(Request $request)
    {

        $inputSearch = $request->search ?? null;
        $page = $request->page ?? 1; // default page
        $perPage = $request->per_page ?? 15; // default per page
        $query = CotisationMensuelle::query();

        if ($inputSearch) {
            $query->where(function ($q) use ($inputSearch) {
        
                $q->where('name', 'like', '%' . $inputSearch . '%')
                    ->orWhere('matricule', 'like', '%' . $inputSearch . '%')
                    ->orWhere('retenu', 'like', '%' . $inputSearch . '%')
                    ->orWhere('date_cotisation', 'like', '%' . $inputSearch . '%');
            });
        }

        $cotisations = $query->latest()->paginate($perPage, ['*'], 'page', $page);
        return sendResponse($cotisations, 'Cotisations mensuelles retrieved successfully.');
    }

    public function cotisationMensuelles(Request $request)
    {
        /* params%5Bpage%5D=2&params%5Bper_page%5D=50&params%5Bsearch%5D=fffffffff&params%5Bsort_field%5D=&params%5Bsort_order%5D=asc */
        $inputSearch = $request->search ?? null;
        
        $page = $request->page ?? 1; // default page
        $perPage = $request->per_page ?? 15; // default per page
        $query = CotisationMensuelle::query();

        if ($inputSearch) {
            $query->where(function ($q) use ($inputSearch) {
                $q->where('name', 'like', '%' . $inputSearch . '%')
                    ->orWhere('matricule', 'like', '%' . $inputSearch . '%')
                    ->orWhere('retenu', 'like', '%' . $inputSearch . '%')
                    ->orWhere('date_cotisation', 'like', '%' . $inputSearch . '%');
            });
        }

        $cotisations = $query->where('type', 'COTISATION')
                            ->orWhere('type', 'REMBOURSEMENT_MANUEL')
                             ->latest()
                             ->paginate($perPage, ['*'], 'page', $page);

        return sendResponse($cotisations, 'Cotisations mensuelles retrieved successfully.');
    }


    public function dashboard()
    {
        $totalCotisationsBif = Cotisation::where('devise', 'BIF')->count();
        $totalCotisationsUSD = Cotisation::where('devise', 'USD')->count();
        // $totalMembres = Cotisation::distinct('membre_id')->count('membre_id');
        $totalMembres = Cotisation::where('statut', 'paye')->count();
        $totalMontantCotisations = Cotisation::sum('montant');
        $cotisationsParMembre = Cotisation::selectRaw('membre_id, COUNT(*) as total, SUM(montant) as total_montant')
            ->groupBy('membre_id')
            ->with('membre')
            ->get();

        $data = [
            'total_cotisations_bif' => $totalCotisationsBif,
            'total_cotisations_usd' => $totalCotisationsUSD,
            'total_membres' => $totalMembres,
            'total_montant_cotisations' => $totalMontantCotisations,
            'cotisations_par_membre' => $cotisationsParMembre,
        ];

        return sendResponse($data, 'Données du tableau de bord des cotisations récupérées avec succès.');
    }

    public function cotisationAutomatic(Request $request){

        $request->validate([
            'montant' => 'required|numeric',
        ]);

        $cotisation = Cotisation::where('membre_id', $request->membre_id)->first();
//         if($cotisation){
//             return sendError('Cotisation deja existante');
//         }

//         $cotisation = Cotisation::create([
//             'membre_id' => $request->membre_id,
//             'periode_id' => $request->periode_id,
//             'montant' => $request->montant,
//             'statut' => 'paye',
//             'devise' => $request->devise,
//             'mode_paiement' => $request->mode_paiement,
//             'reference_paiement' => $request->reference_paiement,
//             'date_paiement' => $request->date_paiement,
//         ]);

$cotisation = Cotisation::where('membre_id', $request->membre_id)->first();

if($cotisation){
    return sendError('Cotisation deja existante');
}

$membres = Membre::where('statut', 'actif')->get();

for ($i=0; $i < count($membres); $i++) {
    Cotisation::create([
        'statut'=> 'en_attente',
        'membre_id' => $membres[$i]->id,
        'montant' => $request->montant,
        'devise' => $membres[$i]->devise,
        'mode_paiement' => 'espece',
        'reference_paiement' => null,
        'date_paiement' => null,
    ]);
}

        return sendResponse($cotisation, 'Cotisation mise a jour avec succes');
    }

    public function mesCotisations(Request $request)
    {
        $membre = Membre::connecte();
        if (!$membre) {
            return sendError('Membre non trouvé.', [], 404);
        }

        $query = Cotisation::duMembre($membre);

        // Filtre par mois (format YYYY-MM)
        if ($request->filled('date')) {
            $mois = Carbon::parse($request->date . '-01');
            $query->whereYear('date_paiement', $mois->year)
                ->whereMonth('date_paiement', $mois->month);
        }

        $cotisations = $query->orderByDesc('date_paiement')->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 15), 1000));

        return sendResponse($cotisations, 'Cotisations récupérées avec succès.');
    }
    public function index(Request $request)
    {
        $query = Cotisation::with(['membre']);

        // Recherche textuelle
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('reference_paiement', 'like', "%{$search}%")
                    ->orWhere('membre_id', 'like', "%{$search}%")
                    ->orWhereHas('membre', function ($query) use ($search) {
                        $query->where('matricule', 'like', "%{$search}%")
                            ->orWhere('nom', 'like', "%{$search}%")
                            ->orWhere('prenom', 'like', "%{$search}%")
                        ;
                    })
                ;
            });
        }

        // Filtres
        if ($request->has('statut')) {
            $query->where('statut', $request->get('statut'));
        }

        if ($request->has('devise')) {
            $query->where('devise', $request->get('devise'));
        }

        if ($request->has('mode_paiement')) {
            $query->where('mode_paiement', $request->get('mode_paiement'));
        }

        if ($request->has('membre_id')) {
            $query->where('membre_id', $request->get('membre_id'));
        }


        if ($request->has('montant_min')) {
            $query->where('montant', '>=', $request->get('montant_min'));
        }

        if ($request->has('montant_max')) {
            $query->where('montant', '<=', $request->get('montant_max'));
        }

        if ($request->has('date_debut')) {
            $query->whereDate('date_paiement', '>=', $request->get('date_debut'));
        }

        if ($request->has('date_fin')) {
            $query->whereDate('date_paiement', '<=', $request->get('date_fin'));
        }

        // Sorting
        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        if (in_array($sortField, ['id', 'reference_paiement', 'montant', 'created_at', 'statut'])) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->latest();
        }

        $perPage = $request->per_page ?? 15;
        $cotisations = $query->paginate($perPage);

        return sendResponse($cotisations, 'Cotisations retrieved successfully.');
    }

    public function store(CotisationStoreRequest $request)
    {
        try {
            DB::beginTransaction();
             $cotisation = Cotisation::create($this->avecMatricule($request->validated()));
        // Writte to cotisation_mensuelles
        $cotisationMensuelle = CotisationMensuelle::create([
            'name' => $cotisation->membre?->nom . ' ' . $cotisation->membre?->prenom,
            'matricule' => $cotisation->membre?->matricule,
            'nomero_dossier' => $cotisation->membre?->nomero_dossier,
            'global' => $cotisation->montant,
            'regle' => $cotisation->montant,
            'restant' => $cotisation->montant,
            'retenu' => $cotisation->montant,
            'date_cotisation' => $cotisation->date_paiement,
            'user_id' => $cotisation->user_id,
            'type' => 'REMBOURSEMENT_MANUEL'
        ]);
        DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return sendError('Erreur lors de la création de la cotisation.');
        }

        return sendResponse(new CotisationResource($cotisation), 'Cotisation créée avec succès.');
    }

    public function show(Request $request, Cotisation $cotisation)
    {
        return sendResponse($cotisation, 'Détails de la cotisation récupérés avec succès.');
    }

    public function update(CotisationUpdateRequest $request, Cotisation $cotisation)
    {
        $cotisation->update($this->avecMatricule($request->validated()));

        return sendResponse($cotisation, 'Cotisation mise à jour avec succès.');
    }

    /** Le membre est identifié par son matricule : on le copie sur la cotisation saisie. */
    private function avecMatricule(array $data): array
    {
        if (!empty($data['membre_id'])) {
            $data['matricule'] = Membre::whereKey($data['membre_id'])->value('matricule');
        }

        return $data;
    }

    public function destroy(Request $request, Cotisation $cotisation)
    {
        $cotisation->delete();
        return sendResponse(null, 'Cotisation supprimée avec succès.');
    }
}
