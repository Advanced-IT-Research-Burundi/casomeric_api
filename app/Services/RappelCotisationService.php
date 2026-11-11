<?php

namespace App\Services;

use App\Events\NotificationSent;
use App\Models\Cotisation;
use App\Models\Membre;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;

/**
 * Cotisations non payées et rappels.
 *
 * Un membre est identifié par son matricule (Cotisation::duMembre). Selon la fréquence
 * de sa catégorie, il doit une cotisation par mois ("mensuel") ou par semestre
 * ("semestriel" : janvier-juin, juillet-décembre).
 */
class RappelCotisationService
{
    public const TYPE = 'cotisation';

    /** Période (début, fin) que couvre le mois donné pour ce membre. */
    public function periode(Membre $membre, Carbon $mois): array
    {
        if ($this->estSemestriel($membre)) {
            $debut = $mois->copy()->month($mois->month <= 6 ? 1 : 7)->startOfMonth();

            return [$debut, $debut->copy()->addMonths(5)->endOfMonth()];
        }

        return [$mois->copy()->startOfMonth(), $mois->copy()->endOfMonth()];
    }

    public function libellePeriode(Membre $membre, Carbon $mois): string
    {
        [$debut] = $this->periode($membre, $mois);

        return $this->estSemestriel($membre)
            ? ($debut->month === 1 ? '1er' : '2e') . ' semestre ' . $debut->year
            : $debut->copy()->locale('fr')->translatedFormat('F Y');
    }

    /** Le membre doit-il une cotisation pour cette période ? (actif et adhérent avant la fin) */
    public function doitCotiser(Membre $membre, Carbon $mois): bool
    {
        [, $fin] = $this->periode($membre, $mois);

        return $membre->statut === 'actif'
            && (!$membre->date_adhesion || $membre->date_adhesion->lte($fin));
    }

    public function estAJour(Membre $membre, Carbon $mois): bool
    {
        [$debut, $fin] = $this->periode($membre, $mois);

        return Cotisation::duMembre($membre)
            ->whereIn('statut', ['paye', 'partiel'])
            ->whereBetween('date_paiement', [$debut->toDateString(), $fin->toDateString()])
            ->exists();
    }

    /**
     * Périodes non payées parmi les $nbMois derniers mois terminés (le mois en cours
     * n'est pas compté : la retenue sur salaire arrive après la fin du mois).
     *
     * @return array<int, array{periode: string, debut: string, fin: string}>
     */
    public function periodesNonPayees(Membre $membre, int $nbMois = 12): array
    {
        $resultat = [];
        for ($i = $nbMois; $i >= 1; $i--) {
            $mois = now()->startOfMonth()->subMonths($i);
            [$debut, $fin] = $this->periode($membre, $mois);

            // Un semestre n'est dû qu'une fois terminé : on le regarde sur son dernier mois.
            if ($fin->month !== $mois->month || isset($resultat[$debut->toDateString()])) {
                continue;
            }
            if ($this->doitCotiser($membre, $mois) && !$this->estAJour($membre, $mois)) {
                $resultat[$debut->toDateString()] = [
                    'periode' => $this->libellePeriode($membre, $mois),
                    'debut' => $debut->toDateString(),
                    'fin' => $fin->toDateString(),
                ];
            }
        }

        return array_values($resultat);
    }

    /**
     * Envoie un rappel à chaque membre qui n'a pas payé la période du mois donné,
     * puis un récapitulatif au personnel. Un membre n'est rappelé qu'une fois par période.
     *
     * Les membres semestriels ne sont rappelés que sur le dernier mois du semestre,
     * à l'approche du semestre suivant.
     *
     * @return array{rappels: int, membres: array<int, string>}
     */
    public function envoyerRappels(Carbon $mois, bool $simulation = false): array
    {
        $membres = Membre::with(['categorie', 'user'])
            ->where('statut', 'actif')
            ->whereNotNull('user_id')
            ->get();

        $enRetard = [];
        $rappels = 0;

        foreach ($membres as $membre) {
            [, $fin] = $this->periode($membre, $mois);
            if ($fin->month !== $mois->month || !$this->doitCotiser($membre, $mois) || $this->estAJour($membre, $mois)) {
                continue;
            }

            $periode = $this->libellePeriode($membre, $mois);
            $enRetard[] = "{$membre->matricule} - {$membre->full_name} ({$periode})";

            $titre = "Rappel de cotisation - {$periode}";
            $dejaEnvoye = Notification::where('assignee_id', $membre->user_id)
                ->where('type', self::TYPE)
                ->where('title', $titre)
                ->exists();
            if ($dejaEnvoye || $simulation) {
                continue;
            }

            $montant = $membre->categorie
                ? ' (' . number_format((float) $membre->categorie->montant_cotisation, 0, ',', ' ') . ' ' . $membre->categorie->devise . ')'
                : '';
            $this->notifier(
                $membre->user_id,
                $titre,
                "Aucune cotisation{$montant} n'a été enregistrée pour votre matricule {$membre->matricule} pour la période {$periode}. Merci de régulariser votre situation auprès de la caisse."
            );
            $rappels++;
        }

        if ($enRetard && !$simulation) {
            $this->notifierPersonnel($mois, $enRetard);
        }

        return ['rappels' => $rappels, 'membres' => $enRetard];
    }

    private function notifierPersonnel(Carbon $mois, array $enRetard): void
    {
        $titre = 'Cotisations non payées - ' . $mois->copy()->locale('fr')->translatedFormat('F Y');
        $message = count($enRetard) . " membre(s) n'ont pas de cotisation enregistrée : " . implode(', ', $enRetard);

        User::whereIn('role', ['admin', 'gestionnaire', 'responsable'])->pluck('id')
            ->each(function ($userId) use ($titre, $message) {
                $existe = Notification::where('assignee_id', $userId)->where('type', self::TYPE)->where('title', $titre)->exists();
                if (!$existe) {
                    $this->notifier($userId, $titre, $message);
                }
            });
    }

    private function notifier(int $userId, string $titre, string $message): void
    {
        $notification = Notification::create([
            'type' => self::TYPE,
            'title' => $titre,
            'message' => $message,
            'time' => now(),
            'read' => false,
            'user_id' => null, // envoyée par le système
            'assignee_id' => $userId,
        ]);

        // Affichage en temps réel ; la notification est déjà enregistrée si la diffusion échoue.
        try {
            NotificationSent::dispatch($notification->toArray(), $userId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function estSemestriel(Membre $membre): bool
    {
        return $membre->categorie?->frequence_paiement === 'semestriel';
    }
}
