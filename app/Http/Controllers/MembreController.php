<?php

namespace App\Http\Controllers;

use App\Http\Requests\MembreStoreRequest;
use App\Http\Requests\MembreUpdateRequest;

use App\Http\Resources\MembreCollection;
use App\Http\Resources\MembreResource;
use App\Jobs\SendWelcomeMailJob;
use App\Models\Membre;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class MembreController extends Controller
{
    public function index(Request $request)
    {
        $query = Membre::with('categorie');

        // dd($query);


        if ($request->filled('search')) {
            $searchTerm = $request->search;

            $query->where(function ($q) use ($searchTerm) {
                $q->where('matricule', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('nom', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('prenom', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%");
            });
        }


        if ($request->has('filter')) {
            foreach ($request->filter as $field => $value) {
                if ($value) {
                    $query->where($field, 'LIKE', "%{$value}%");
                }
            }
        }

        if ($request->has('sort_field') && $request->sort_field) {
            if ($request->sort_field === 'full_name') {
                $query->orderByRaw("CONCAT(nom, ' ', prenom) " . ($request->sort_order ?? 'asc'));
            } else if ($request->sort_field === 'categorie.description') {
                $query->join('categorie_membres', 'membres.categorie_id', '=', 'categorie_membres.id')
                    ->orderBy('categorie_membres.description', $request->sort_order ?? 'asc');
            } else {
                $query->orderBy($request->sort_field, $request->sort_order ?? 'asc');
            }
        }

        $perPage = $request->per_page ?? 10;
        $membres = $query->paginate($perPage);

        return sendResponse(new MembreCollection($membres), 'Membres récupérés avec succès');
    }

    public function search(Request $request)
    {
    $search = $request->get('q'); 


    $membres = Membre::when($search, function ($query) use ($search) {
            $query->where('matricule', 'like', "%{$search}%")
                  ->orWhere('nom', 'like', "%{$search}%")
                  ->orWhere('prenom', 'like', "%{$search}%")
                  ->orWhere('telephone', 'like', "%{$search}%");
        })
        ->limit(10)
        ->get();

    if ($membres->isEmpty()) {
        return sendError('Aucun membre trouvé', [], 404);
    }

    return sendResponse($membres, 'Membres récupérés avec succès');
}

    public function store(MembreStoreRequest $request)
    {
        $data = $request->validated();
        $createUser = (bool) ($data['create_user'] ?? false);
        unset($data['create_user']);

        if ($createUser && !$request->user()?->isAdmin()) {
            return sendError(
                'Seul un administrateur peut créer un compte utilisateur pour un membre.',
                [],
                403
            );
        }

        $user = null;
        $membre = DB::transaction(function () use (&$user, &$data, $createUser) {
            if ($createUser) {
                $user = User::create([
                    'name' => $data['nom'] . ' ' . $data['prenom'],
                    'nom' => $data['nom'],
                    'prenom' => $data['prenom'],
                    'email' => $data['email'],
                    'telephone' => $data['telephone'],
                    'password' => Hash::make(Str::random(64)),
                    'role' => 'membre',
                    'is_active' => true,
                ]);

                $data['user_id'] = $user->id;
            }

            return Membre::create($data);
        });

        if (!$createUser) {
            return sendResponse($membre, 'Membre créé avec succès', 201);
        }

        try {
            $status = Password::sendResetLink(
                ['email' => $user->email],
                function (User $user, string $token): void {
                    // Welcome email with the "define your password" link, sent by the queue worker.
                    SendWelcomeMailJob::dispatch($user, $token);
                }
            );
        } catch (\Throwable $exception) {
            Log::error('Password link email could not be queued for newly created member account.', [
                'user_id' => $user->id,
                'exception' => $exception->getMessage(),
            ]);

            return sendError(
                'Le membre et son compte ont été créés, mais l’envoi du lien de définition du mot de passe n’a pas pu être programmé. Utilisez « Mot de passe oublié » pour réessayer.',
                [],
                502
            );
        }

        if ($status !== Password::RESET_LINK_SENT) {
            Log::warning('Password reset link was not sent for newly created member account.', [
                'user_id' => $user->id,
                'status' => $status,
            ]);

            return sendError(
                'Le membre et son compte ont été créés, mais le lien de définition du mot de passe n’a pas pu être envoyé.',
                ['password_reset' => [$status]],
                $status === Password::RESET_THROTTLED ? 429 : 502
            );
        }

        return sendResponse(
            $membre->load('user'),
            'Membre et compte utilisateur créés. Un e-mail de bienvenue avec le lien pour définir le mot de passe va être envoyé.',
            201
        );
    }

    public function show(Request $request, Membre $membre)
    {
        $membre->load('user');
        $membre->load('categorie');
        $membre->load('cotisations');
        $membre->load('credits');
        $membre->load('assistances');

        $data = $membre;

        return sendResponse(new MembreResource($data), 'Membre récupéré avec succès');
    }

    public function update(MembreUpdateRequest $request, Membre $membre)
    {
        $membre->update($request->validated());

        return sendResponse($membre, 'Membre mis à jour avec succès');
    }

    public function destroy(Request $request, Membre $membre)
    {
        $membre->delete();

        return sendResponse(null, 'Membre supprimé avec succès');
    }
}
