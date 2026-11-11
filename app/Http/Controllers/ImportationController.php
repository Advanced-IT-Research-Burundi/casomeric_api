<?php

namespace App\Http\Controllers;

use App\Models\CotisationMensuelle;
use App\Services\ImportationService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ImportationController extends Controller
{
    protected $importationService;

    public function __construct(ImportationService $importationService)
    {
        $this->importationService = $importationService;
    }

    public function cotisation(Request $request)
    {
        $request->validate([
            'date_cotisation' => 'required|date',
            'cotisations' => 'required|array|min:1',
        ], [
            'date_cotisation.required' => 'La date (mois) de la cotisation est obligatoire.',
            'cotisations.required' => 'Le fichier importé ne contient aucune ligne.',
        ]);

        $date = Carbon::parse($request->date_cotisation)->format('Y-m');

        // check if date already exists
        $existingCotisation = CotisationMensuelle::where('date_cotisation', $date)->first();
        if ($existingCotisation) {
            return sendError('Cotisation for this date already exists', [], 409);
        }

        foreach ($request->cotisations as $cotisation) {
            // Check if is valid entry
            if (empty($cotisation['matricule']) || empty($cotisation['name']) || !is_numeric($cotisation['matricule'])) {
                continue;
            }

            // Staging table entry (existing logic)
            $type = "COTISATION";
            if (!empty($cotisation['global']) || !empty($cotisation['restant'])) {
                $type = "REMBOURSEMENT";
            }

            CotisationMensuelle::create([
                'name' => $cotisation['name'],
                'matricule' => $cotisation['matricule'],
                'nomero_dossier' => $cotisation['nomero_dossier'],
                'global' => $cotisation['global'],
                'regle' => $cotisation['regle'],
                'restant' => $cotisation['restant'],
                'retenu' => $cotisation['retenu'],
                'date_cotisation' => $date,
                'user_id' => auth()->id(),
                'type' => $type,
            ]);
        }

        // Process business logic via service
        $this->importationService->processImport($request->cotisations, $date);

        return sendResponse([], 'Cotisation created and processed successfully', 201);
    }
}
