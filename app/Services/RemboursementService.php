<?php

namespace App\Services;

use App\Models\CotisationMensuelle;
use App\Models\Credit;
use App\Models\Remboursement;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place where money paid on a credit is recorded.
 *
 * Every way of paying (one installment, a free amount, the manual form,
 * the monthly import) goes through here, so the schedule, the amount
 * still owed and the credit status always stay in step:
 *
 *  - the money pays the unpaid installments, oldest first;
 *  - an installment becomes "paye" only when fully paid (partial stays open);
 *  - money left over when there is no installment left (imported credits
 *    with no schedule) is kept as a paid line "hors échéancier";
 *  - montant_restant goes down by what was paid;
 *  - the credit stays "approuve" while being repaid and becomes "termine" at 0.
 */
class RemboursementService
{
    /**
     * Pay an amount on a credit, spread over its unpaid installments.
     *
     * @param  array  $ligneExtra  extra columns for a line created outside the schedule (import data)
     * @param  bool   $journaliser record the payment in cotisation_mensuelles (type CREDIT)
     */
    public function payerCredit(
        Credit $credit,
        float $montant,
        ?Carbon $date = null,
        ?string $preuve = null,
        array $ligneExtra = [],
        bool $journaliser = true,
    ): Credit {
        $date ??= now();

        return DB::transaction(function () use ($credit, $montant, $date, $preuve, $ligneExtra, $journaliser) {
            $credit = Credit::whereKey($credit->id)->lockForUpdate()->firstOrFail();
            $this->verifierMontant($credit, $montant);

            $reste = $montant;
            $echeances = $credit->remboursements()
                ->where('statut', '!=', 'paye')
                ->orderBy('numero_echeance')
                ->get();

            foreach ($echeances as $echeance) {
                if ($reste <= 0) {
                    break;
                }
                $reste -= $this->appliquer($echeance, $reste, $date, $preuve);
            }

            // No installment left to pay (credit without schedule): keep a paid line.
            if ($reste > 0.009) {
                Remboursement::create(array_merge([
                    'numero_echeance' => (int) $credit->remboursements()->max('numero_echeance') + 1,
                    'date_echeance' => $date->toDateString(),
                ], $ligneExtra, [
                    'credit_id' => $credit->id,
                    'montant_prevu' => $reste,
                    'montant_paye' => $reste,
                    'date_paiement' => $date->toDateString(),
                    'statut' => 'paye',
                    'penalite' => 0,
                    'preuve_paiement' => $preuve,
                ]));
            }

            return $this->cloturer($credit, $montant, $date, $journaliser);
        });
    }

    /**
     * Pay one installment (fully by default, or partially).
     */
    public function payerEcheance(
        Remboursement $echeance,
        ?float $montant = null,
        ?Carbon $date = null,
        ?string $preuve = null,
    ): Credit {
        $date ??= now();

        return DB::transaction(function () use ($echeance, $montant, $date, $preuve) {
            $echeance = Remboursement::whereKey($echeance->id)->lockForUpdate()->firstOrFail();
            $credit = Credit::whereKey($echeance->credit_id)->lockForUpdate()->firstOrFail();

            if ($echeance->statut === 'paye') {
                throw ValidationException::withMessages([
                    'montant_paye' => "L'échéance n° {$echeance->numero_echeance} est déjà payée.",
                ]);
            }

            $du = $this->resteDu($echeance);
            $montant ??= $du;

            if ($montant > $du + 0.009) {
                throw ValidationException::withMessages([
                    'montant_paye' => 'Le montant dépasse ce qui reste dû sur cette échéance ('
                        .number_format($du, 0, ',', ' ').' BIF). Utilisez le paiement global pour payer plus.',
                ]);
            }
            $this->verifierMontant($credit, $montant);

            $this->appliquer($echeance, $montant, $date, $preuve);

            return $this->cloturer($credit, $montant, $date, true);
        });
    }

    /** Save a proof of payment in public/uploads/preuves_paiements and return its path. */
    public static function stockerPreuve(?UploadedFile $fichier): ?string
    {
        if (!$fichier || !$fichier->isValid()) {
            return null;
        }

        $nom = time().'_'.preg_replace('/[^A-Za-z0-9._-]/', '_', $fichier->getClientOriginalName());
        $fichier->move(public_path('uploads/preuves_paiements'), $nom);

        return 'uploads/preuves_paiements/'.$nom;
    }

    /** What is still due on one installment. */
    public function resteDu(Remboursement $echeance): float
    {
        return max(0, (float) $echeance->montant_prevu - (float) ($echeance->montant_paye ?? 0));
    }

    /**
     * Put up to $disponible on one installment. Returns the amount used.
     */
    private function appliquer(Remboursement $echeance, float $disponible, Carbon $date, ?string $preuve): float
    {
        $du = $this->resteDu($echeance);
        $verse = min($du, $disponible);

        $echeance->montant_paye = (float) ($echeance->montant_paye ?? 0) + $verse;
        $echeance->date_paiement = $date->toDateString();
        if ($preuve) {
            $echeance->preuve_paiement = $preuve;
        }
        // Fully paid only when nothing is left; otherwise it keeps "prevu" / "en_retard".
        if ($verse >= $du - 0.009) {
            $echeance->statut = 'paye';
        }
        $echeance->save();

        return $verse;
    }

    private function verifierMontant(Credit $credit, float $montant): void
    {
        if ($montant <= 0) {
            throw ValidationException::withMessages([
                'montant_paye' => 'Le montant doit être supérieur à 0.',
            ]);
        }

        if ($montant > (float) $credit->montant_restant + 0.009) {
            throw ValidationException::withMessages([
                'montant_paye' => 'Le montant dépasse le montant restant du crédit ('
                    .number_format((float) $credit->montant_restant, 0, ',', ' ').' BIF).',
            ]);
        }
    }

    /**
     * Lower the amount owed, set the status and (for manual payments) log the payment.
     */
    private function cloturer(Credit $credit, float $montant, Carbon $date, bool $journaliser): Credit
    {
        $credit->montant_restant = max(0, round((float) $credit->montant_restant - $montant, 2));
        // Pendant le remboursement le statut reste "approuve" ; "termine" quand tout est payé.
        $credit->statut = $credit->montant_restant <= 0 ? 'termine' : 'approuve';
        $credit->save();

        if ($journaliser) {
            $membre = $credit->membre;
            CotisationMensuelle::create([
                'name' => trim(($membre?->nom ?? '').' '.($membre?->prenom ?? '')),
                'matricule' => $membre?->matricule,
                'nomero_dossier' => $membre?->nomero_dossier,
                'global' => $credit->montant_total_rembourser,
                'regle' => max(0, (float) $credit->montant_total_rembourser - (float) $credit->montant_restant),
                'restant' => $credit->montant_restant,
                'retenu' => $montant,
                'date_cotisation' => $date->toDateString(),
                'user_id' => Auth::id(),
                'type' => 'CREDIT',
            ]);
        }

        return $credit->fresh('remboursements');
    }
}
