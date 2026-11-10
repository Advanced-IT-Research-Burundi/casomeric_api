<?php

namespace App\Http\Controllers;

use App\Services\CaisseService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class CaisseController extends Controller
{
    /** Fund balance and its breakdown (staff only). */
    public function show(CaisseService $caisse)
    {
        if (!Auth::user()->hasRoles(['admin', 'gestionnaire', 'responsable'])) {
            return sendError("Vous n'avez pas accès à la caisse.", [], Response::HTTP_FORBIDDEN);
        }

        return sendResponse($caisse->details(), 'Solde de la caisse.');
    }
}
