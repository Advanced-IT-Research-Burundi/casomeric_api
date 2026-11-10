<?php

namespace Database\Seeders;

use App\Models\Assistance;
use App\Models\CategorieMembre;
use App\Models\Configuration;
use App\Models\Cotisation;
use App\Models\Credit;
use App\Models\Membre;
use App\Models\TypeAssistance;
use App\Models\User;
use Database\Factories\AssistanceFactory;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'Admin User',
                'nom' => 'Jean',
                'prenom' => 'Lionel',
                'email' => 'nijeanlionel@gmail.com',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ],
            [
                'name' => 'Admin User',
                'nom' => 'Jean',
                'prenom' => 'Lionel',
                'email' => 'admin@gmail.com',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ],
            [
                'name' => 'Manager User',
                'nom' => 'Jean',
                'prenom' => 'Lionel',
                'email' => 'manager@gmail.com',
                'password' => Hash::make('password'),
                'role' => 'gestionnaire',
                'is_active' => true,
            ],
            [
                'name' => 'Membre User',
                'nom' => 'Jean',
                'prenom' => 'Lionel',
                'email' => 'membre@gmail.com',
                'password' => Hash::make('password'),
                'role' => 'membre',
                'is_active' => true,
            ],
            [
                'name' => 'Responsable User',
                'nom' => 'Jean',
                'prenom' => 'Lionel',
                'email' => 'responsable@gmail.com',
                'password' => Hash::make('password'),
                'role' => 'responsable',
                'is_active' => true,
            ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(['email' => $user['email']], $user);
        }

        // ==========================
        // Catégories membres
        // ==========================

        $categorieMembres = [
            [
                'nom' => 'cadre_contractuel',
                'montant_cotisation' => 4000,
                'devise' => "FBU",
                'frequence_paiement' => "mensuel",
                'description' => 'Cadre Contractuel ou non',
            ],
            [
                'nom' => 'collaborateur_a2',
                'montant_cotisation' => 2000,
                'devise' => "FBU",
                'frequence_paiement' => "mensuel",
                'description' => 'Collaborateur (Niveau A2)',
            ],
            [
                'nom' => 'chauffeur_planton',
                'montant_cotisation' => 1000,
                'devise' => "FBU",
                'frequence_paiement' => "mensuel",
                'description' => 'Chauffeur / Planton',
            ],
            [
                'nom' => 'service_externe_10',
                'montant_cotisation' => 10,
                'devise' => "USD",
                'frequence_paiement' => "mensuel",
                'description' => 'Service Externe (Cadres)',
            ],
            [
                'nom' => 'service_externe_5',
                'montant_cotisation' => 5,
                'devise' => "USD",
                'frequence_paiement' => "mensuel",
                'description' => 'Service Externe (Secrétaires d’ambassade)',
            ],
        ];

        foreach ($categorieMembres as $categorie) {
            CategorieMembre::firstOrCreate(['nom' => $categorie['nom']], $categorie);
        }

        // ==========================
        // Membres
        // ==========================

        // Membre::factory()->count(20)->create();

        // ==========================
        // Types d’assistance
        // ==========================

        $typesAssistances = [
            ['mariage', 500000],
            ['retraite', 400000],
            ['deces', 300000]
        ];

        foreach ($typesAssistances as $type) {
            TypeAssistance::firstOrCreate([
                'nom' => $type[0],
            ], [
                'montant_standard' => $type[1],
                'conditions' => 'Conditions pour ' . $type[0],
                'documents_requis' => 'Documents requis pour ' . $type[0],
            ]);
        }


        // ==========================
        // Configurations
        // ==========================
        $settings = [
            [
                'cle' => 'taux_interet_credit',
                'valeur' => '3',
                'description' => 'Taux d’intérêt par défaut pour les crédits (en %)'
            ],
            [
                'cle' => 'solde_initial_caisse',
                'valeur' => '0',
                'description' => 'Argent déjà présent dans la caisse avant l’utilisation de l’application (en BIF)'
            ],
            [
                'cle' => 'nom_organisation',
                'valeur' => 'CASOMERIC',
                'description' => 'Nom de l’organisation'
            ]
        ];

        foreach ($settings as $setting) {
            Configuration::firstOrCreate(['cle' => $setting['cle']], $setting);
        }

        // Assistance::factory()->count(10)->create();
        // Cotisation::factory()->count(10)->create();
        // Credit::factory()->count(10)->create();

    }
}
