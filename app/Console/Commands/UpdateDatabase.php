<?php

namespace App\Console\Commands;

use App\Models\Periode;
use App\Models\TypeAssistance;
use Illuminate\Console\Command;

class UpdateDatabase extends Command
{
    /**
    * The name and signature of the console command.
    *
    * @var string
    */
    protected $signature = 'app:update-database';
    
    /**
    * The console command description.
    *
    * @var string
    */
    protected $description = 'Command description';
    
    /**
    * Execute the console command.
    */
    public function handle()
    {
        //Des Employés  Sous-Contrat, Sous-contrat ou Service Externe.
        
        //         Cadre Contractuels ou non	4 000 FBU
        // Collaborateur (Niveau A2):	2 000 FBU
        // Chauffeur et planton	1 000 FBU
        //Service Externe	10 USD ou 5 USD
        
        
        $categoriesMembres = [
           
            [
                'nom' => 'Cadre Contractuel ou non',
                'montant_cotisation' => 4000,
                'devise' => 'FBU',
                'frequence_paiement' => 'mensuel',
                'description' => 'Cadre Contractuel ou non'
            ],
            [
                'nom' => 'Collaborateur (Niveau A2)',
                'montant_cotisation' => 2000,
                'devise' => 'FBU',
                'frequence_paiement' => 'mensuel',
                'description' => 'Collaborateur (Niveau A2)'
            ],
            [
                'nom' => 'Chauffeur et planton',
                'montant_cotisation' => 1000,
                'devise' => 'FBU',
                'frequence_paiement' => 'mensuel',
                'description' => 'Chauffeur et planton'
            ],
            [
                'nom' => 'Service Externe (Cadres)',
                'montant_cotisation' => 10,
                'devise' => 'USD',
                'frequence_paiement' => 'semestriel',
                'description' => 'Service Externe (Cadres) — 10 USD/mois, encaissés semestriellement (60 USD).'
                
            ],
            [
                'nom' => 'Service Externe (Secrétaires d’ambassade)',
                'montant_cotisation' => 5,
                'devise' => 'USD',
                'frequence_paiement' => 'semestriel',
                'description' => 'Service Externe (Secrétaires d’ambassade) — 5 USD/mois, encaissés semestriellement (30 USD).'
            ],
        ];
        
        foreach ($categoriesMembres as $categorieMembre) {
            \App\Models\CategorieMembre::updateOrCreate(
                ['nom' => $categorieMembre['nom']],
                $categorieMembre
            );
        }

        $this->info('Catégories de membres mises à jour avec succès.');

        // Creer le Periode 
    Periode::firstOrCreate([
        'mois' => date('m'),
        'annee' => date('Y'),
        'statut' => 'ouvert',
        'date_debut' => \Carbon\Carbon::now()->startOfMonth()->toDateString(),
        'date_fin' => \Carbon\Carbon::now()->endOfMonth()->toDateString(),
    ]);

    $this->info('Periode mise à jour'); // 

    // Types assistance : [mariage, retraite, décès]

    $typesAssistances = [[ 'mariage', 500000], ['retraite', 400000], ['deces', 300000]];

    foreach ($typesAssistances as $type) {
        TypeAssistance::firstOrCreate([
            'nom' => $type[0],
            'montant_standard' => $type[1],
            'conditions' => 'Conditions pour ' . $type[0],
            'documents_requis' => 'Documents requis pour ' . $type[0],
        ]);
    }

    $this->info('Types d\'assistance mis à jour'); //

    }
}
