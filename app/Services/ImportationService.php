<?php

namespace App\Services;

use App\Models\User;
use App\Models\Membre;
use App\Models\Credit;
use App\Models\Remboursement;
use App\Models\Cotisation;
use App\Models\CotisationMensuelle;
use App\Models\Configuration;
use App\Services\RemboursementService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ImportationService
{
    /**
     * Process the imported data from staging table (CotisationMensuelle).
     * Moves data from staging to permanent tables: Cotisation, Credit, Remboursement.
     * Sépare les entrées COTISATION et REMBOURSEMENT.
     *
     * @param array $stagingRecords Array of CotisationMensuelle staging records
     * @param string $date Date of the import (Y-m format)
     * @return void
     * @throws \Exception
     */
    public function processImport(array $stagingRecords, string $date)
    {
        foreach ($stagingRecords as $data) {
            if (empty($data['matricule']) || !is_numeric($data['matricule'])) {
                continue;
            }

            // Déterminer le type: REMBOURSEMENT si global ou restant est présent
            $type = "COTISATION";
            if (!empty($data['global']) || !empty($data['restant'])) {
                $type = "REMBOURSEMENT";
            }

            if ($type === "COTISATION") {
                // Pour les cotisations, on essaie de trouver le membre mais ce n'est pas obligatoire
                $membre = Membre::where('matricule', $data['matricule'])->first();
                $this->processCotisation($membre, $data, $date);
            } else {
                // Pour les remboursements, on doit avoir un membre
                $membre = $this->resolveMembre($data);
                $retenu = floatval($data['retenu'] ?? 0);
                if ($retenu > 0) {
                    $this->processRemboursement($membre, $data, $retenu, $date);
                }
            }
        }
    }

    /**
     * Resolve member from matricule, create user and member if not exists.
     */
    private function resolveMembre(array $data)
    {
        $matricule = $data['matricule'];
        $membre = Membre::where('matricule', $matricule)->first();

        if (!$membre) {
            // Create User
            $user = User::create([
                'name' => $data['name'] ?? 'Imported User',
                'nom' => $data['name'] ?? 'Imported',
                'prenom' => 'User',
                'email' => $matricule . '@cosmeric.com', // Placeholder email
                'password' => Hash::make($matricule), // Password is the matricule
                'role' => 'membre',
                'is_active' => false,
            ]);


            if ($data['retenu'] == 4000) {
                $category = 1;
            } else if ($data['retenu'] == 2000) {
                $category = 2;
            } else if ($data['retenu'] == 1000) {
                $category = 3;
            } else {
                $category = 3; // Default category
            }

            $nom = explode(" ", $data['name']);
            $prenom = $nom[0];
            $nom = $nom[1];


            // Create Membre
            $membre = Membre::create([
                'user_id' => $user->id,
                'matricule' => $matricule,
                'nom' => $nom,
                'email' => strtolower($nom.$prenom). '@cosmeric.com',
                'telephone' => "+257 00000000",
                'prenom' => $prenom,
                'categorie_id' => $category, // Default category
                'statut' => 'actif',
                'date_adhesion' => now(),
            ]);
        }

        return $membre;
    }

    /**
     * Crédit déjà accordé hors de l'application (il apparaît pour la première fois dans le fichier
     * de retenues). On le crée "approuve" avec ce qu'il reste à payer, pour pouvoir suivre la retenue.
     *
     * Colonnes du fichier : global = total du crédit, restant = reste après la retenue du mois,
     * retenu = retenue du mois. Le reste AVANT la retenue vaut donc restant + retenu.
     */
    private function createImportedCredit(Membre $membre, array $data, float $retenu, string $date): ?Credit
    {
        $global = floatval($data['global'] ?? 0);
        $restantApres = is_numeric($data['restant'] ?? null) ? floatval($data['restant']) : null;
        $restantAvant = $restantApres !== null ? $restantApres + $retenu : $global;
        $total = max($global, $restantAvant);

        if ($total <= 0) {
            return null;
        }

        $dateImport = Carbon::parse($date)->endOfMonth();

        $credit = new Credit([
            'membre_id' => $membre->id,
            'montant_demande' => $total,
            'montant_accorde' => $total,
            'taux_interet' => 0,                 // inconnu : le total vient déjà du fichier
            'duree_mois' => $retenu > 0 ? max(1, (int) ceil($restantAvant / $retenu)) : 12,
            'montant_total_rembourser' => $total,
            'montant_mensualite' => $retenu,
            'date_demande' => $dateImport,
            'date_approbation' => $dateImport,
            'statut' => 'approuve',
            'motif' => 'Crédit importé (fichier de retenues)',
            'created_by' => auth()->id() ?? $membre->user_id,
            'user_id' => auth()->id() ?? $membre->user_id,
        ]);
        // montant_restant n'est pas "fillable" sur Credit : on le fixe directement.
        $credit->montant_restant = $restantAvant;
        $credit->save();

        return $credit;
    }

    /**
     * Paie la retenue du mois sur le crédit : répartie sur les échéances non payées,
     * ou gardée comme ligne payée "hors échéancier" (avec les colonnes du fichier)
     * si le crédit n'a pas d'échéancier.
     */
    private function handleRemboursement(Credit $credit, array $data, float $amount, string $date)
    {
        // Ne jamais payer plus que ce qui reste dû.
        $paymentAmount = min($amount, (float) $credit->montant_restant);
        if ($paymentAmount <= 0) {
            return;
        }

        $nameParts = $this->extractNomPrenom($data['name'] ?? '');

        app(RemboursementService::class)->payerCredit(
            $credit,
            $paymentAmount,
            Carbon::parse($date)->endOfMonth(),
            null,
            [
                'matricule' => $data['matricule'] ?? null,
                'nom' => $nameParts['nom'],
                'prenom' => $nameParts['prenom'],
                'nomero_dossier' => $data['nomero_dossier'] ?? null,
                'global' => $data['global'] ?? null,
                'regle' => $data['regle'] ?? null,
                'restant' => $data['restant'] ?? null,
                'retenu' => $data['retenu'] ?? null,
                'is_import' => true,
            ],
            false, // déjà présent dans cotisation_mensuelles (table de l'import)
        );
    }

    /**
     * Extrait nom et prénom d'une chaîne "Prénom Nom".
     */
    private function extractNomPrenom(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);
        return [
            'prenom' => $parts[0] ?? '',
            'nom' => $parts[1] ?? '',
        ];
    }

    /**
     * Process cotisation: move data from staging to permanent Cotisation table.
     * Membre peut être null, dans ce cas on utilise juste le matricule.
     * Inclut toutes les données d'importation.
     */
    private function processCotisation(?Membre $membre, array $stagingData, string $date)
    {
        // Extraire nom et prénom du champ name
        $nameParts = $this->extractNomPrenom($stagingData['name'] ?? '');

        Cotisation::create([
            'membre_id' => $membre?->id,
            'matricule' => $stagingData['matricule'],
            'nom' => $nameParts['nom'],
            'prenom' => $nameParts['prenom'],
            'nomero_dossier' => $stagingData['nomero_dossier'] ?? null,
            'global' => $stagingData['global'] ?? null,
            'regle' => $stagingData['regle'] ?? null,
            'restant' => $stagingData['restant'] ?? null,
            'retenu' => $stagingData['retenu'] ?? null,
            'montant' => floatval($stagingData['retenu'] ?? 0),
            'date_paiement' => Carbon::parse($date)->endOfMonth(),
            'statut' => 'paye',
            'mode_paiement' => 'Banque',
            'reference_paiement' => 'Importation - ' . Carbon::now()->timestamp,
            'is_import' => true,
        ]);
    }

    /**
     * Process remboursement: trouve le crédit en cours de remboursement du membre
     * (ou le crée s'il n'existe pas encore dans l'application) et paie la retenue
     * du mois sur son échéancier via RemboursementService.
     */
    private function processRemboursement(Membre $membre, array $data, float $retenu, string $date)
    {
        // Un crédit en remboursement est "approuve", ou "en_cours" avec une date d'approbation
        // (anciennes données) : pas seulement "approuve" comme avant.
        $credit = Credit::where('membre_id', $membre->id)
            ->where('montant_restant', '>', 0)
            ->where(function ($q) {
                $q->where('statut', 'approuve')
                    ->orWhere(function ($q) {
                        $q->where('statut', 'en_cours')->whereNotNull('date_approbation');
                    });
            })
            ->orderBy('date_approbation')
            ->lockForUpdate()
            ->first();

        if (!$credit) {
            $credit = $this->createImportedCredit($membre, $data, $retenu, $date);
            if (!$credit) {
                return;
            }
        }

        $this->handleRemboursement($credit, $data, $retenu, $date);
    }
}
