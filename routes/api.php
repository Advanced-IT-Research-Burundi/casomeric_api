<?php

use App\Http\Controllers\AssistanceController;
use App\Http\Controllers\CategorieMembreController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\CotisationController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportationController;
use App\Http\Controllers\MembreController;
use App\Http\Controllers\RapportController;
use App\Http\Controllers\RemboursementController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TypeAssistanceController;
use App\Http\Controllers\CotisationMensuelleController;
use App\Http\Controllers\UpdateDBController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');




// Routes publiques
Route::post('/register', [UserController::class, 'register']);
Route::post('/login', [UserController::class, 'login']);
Route::post('/forgot-password', [UserController::class, 'sendPasswordResetLink']);
Route::post('/reset-password', [UserController::class, 'resetPassword']);

// Routes protégées
Route::middleware('auth:sanctum')->group(function () {
    // Authentification
    Route::post('/logout', [UserController::class, 'logout']);
    Route::post('/logout-all', [UserController::class, 'logoutAll']);
    Route::get('/me', [UserController::class, 'me']);
    Route::get('/profiles', [UserController::class, 'profiles']);
    Route::post('/refresh-token', [UserController::class, 'refreshToken']);
    Route::post('/check-token', [UserController::class, 'checkToken']);

    Route::get('/cotisations/mesCotisations', [CotisationController::class, 'mesCotisations']);

    // Profil
    Route::put('/profile', [UserController::class, 'updateProfile']);
    Route::post('/change-password', [UserController::class, 'changePassword']);

    // Gestion des tokens
    Route::get('/tokens', [UserController::class, 'tokens']);
    Route::delete('/tokens', [UserController::class, 'revokeToken']);

    // Accessibles au membre (les contrôleurs limitent aux données du membre ou au personnel)
    Route::apiResource('categorie-membres', CategorieMembreController::class)->only(['index', 'show']);
    Route::apiResource('categories', CategorieMembreController::class)->only(['index', 'show']);
    Route::apiResource('type-assistances', TypeAssistanceController::class)->only(['index', 'show']);
    Route::apiResource('configurations', ConfigurationController::class)->only(['index', 'show']);
    Route::apiResource('credits', CreditController::class);
    Route::apiResource('assistances', AssistanceController::class);

    Route::post("/credits/approuve/{id}", [CreditController::class, 'approuveCredit']);
    Route::post("/credits/rejete/{id}", [CreditController::class, 'refuserCredit']);
    Route::post("/credits/{id}/payer", [CreditController::class, 'payer']);
    Route::post('assistances/approuve/{id}', [AssistanceController::class, 'approuve']);
    Route::post('assistances/rejete/{id}', [AssistanceController::class, 'refuser']);
    Route::post('assistances/{id}/verser', [AssistanceController::class, 'verser']);
    Route::get('caisse', [\App\Http\Controllers\CaisseController::class, 'show']);

    // Espace du membre
    Route::get('mescredits', [CreditController::class, 'mesCredits']);
    Route::post('demande-credit', [CreditController::class, 'demandeCredit']);
    Route::get('mescotisations', [CotisationController::class, 'mesCotisations']);
    Route::get('mes-assistances', [AssistanceController::class, 'mesAssistances']);
    Route::post('demande-assistance', [AssistanceController::class, 'demandeAssistance']);
    Route::get('/dashboard-member', [DashboardController::class, 'dashboardMember']);

    Route::apiResource('notifications', NotificationController::class);
    Route::put('notifications/{notification}/mark-read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::get('notifications-unread-count', [NotificationController::class, 'unreadCount']);

    // Gestion : réservé au personnel de la caisse
    Route::middleware('role:admin,gestionnaire,responsable')->group(function () {
        Route::post('membres/search', [MembreController::class, 'search']);
        Route::apiResource('membres', MembreController::class);
        Route::apiResource('cotisations', CotisationController::class);
        Route::apiResource('remboursements', RemboursementController::class);
        Route::post('remboursements/{remboursement}/approve', [RemboursementController::class, 'approve']);
        Route::apiResource('transactions', TransactionController::class);
        Route::prefix('rapports')->group(function () {
            Route::get('credits', [RapportController::class, 'getCreditReport']);
            Route::get('assistances', [RapportController::class, 'getAssistanceReport']);
            Route::get('remboursements', [RapportController::class, 'getRemboursementReport']);
            Route::get('cotisations', [RapportController::class, 'getCotisationReport']);
            Route::get('etat-cotisations', [RapportController::class, 'getEtatCotisations']);
            Route::get('debiteurs', [RapportController::class, 'getDebiteurs']);
        });
        Route::get('membres/{membre}/situation', [DashboardController::class, 'situationMembre']);
        Route::apiResource('rapports', RapportController::class);
        Route::get('/dashboard', [DashboardController::class, 'getDashboardData']);
        Route::get('/dashboard-assistance', [AssistanceController::class, 'dashboard']);
        Route::get('/dashboard-cotisation', [CotisationController::class, 'dashboard']);
        Route::post("importation", [ImportationController::class, 'cotisation']);
        Route::apiResource("cotisation-mensuelles", CotisationMensuelleController::class);
        Route::get('cotisation_mensuelles', [CotisationController::class, 'cotisationMensuelles']);
        Route::get('remboursements_mensuelles', [CotisationController::class, 'remboursementsMensuelles']);
    });

    // Administrateur seul : comptes utilisateurs (rôles) et paramètres de la caisse
    // (montants des cotisations par catégorie, types et montants d'assistance, taux d'intérêt).
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('categorie-membres', CategorieMembreController::class)->except(['index', 'show']);
        Route::apiResource('categories', CategorieMembreController::class)->except(['index', 'show']);
        Route::apiResource('type-assistances', TypeAssistanceController::class)->except(['index', 'show']);
        Route::apiResource('configurations', ConfigurationController::class)->except(['index', 'show']);
        Route::apiResource('users', UserController::class);
        Route::put('users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::get('/update-database', [UpdateDBController::class, 'updateDatabase']);
    });
});