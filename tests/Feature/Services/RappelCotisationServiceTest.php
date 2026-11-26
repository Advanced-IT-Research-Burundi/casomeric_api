<?php

namespace Tests\Feature\Services;

use App\Models\CategorieMembre;
use App\Models\Cotisation;
use App\Models\Membre;
use App\Models\User;
use App\Services\RappelCotisationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RappelCotisationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_member_owes_six_monthly_contributions_per_semester(): void
    {
        $membre = $this->externalMember(10);
        $service = app(RappelCotisationService::class);

        $this->assertSame(60.0, $service->montantAttendu($membre));
        $this->assertFalse($service->estAJour($membre, Carbon::create(2026, 6, 1)));
    }

    public function test_partial_external_payments_are_accumulated_until_the_semester_is_paid(): void
    {
        $membre = $this->externalMember(5);
        $service = app(RappelCotisationService::class);

        $this->recordPayment($membre, 10, '2026-06-01');
        $this->assertFalse($service->estAJour($membre, Carbon::create(2026, 6, 1)));

        $this->recordPayment($membre, 20, '2026-06-30');
        $this->assertTrue($service->estAJour($membre, Carbon::create(2026, 6, 1)));
    }

    private function externalMember(float $monthlyAmount): Membre
    {
        $category = CategorieMembre::create([
            'nom' => "service_externe_{$monthlyAmount}",
            'montant_cotisation' => $monthlyAmount,
            'devise' => 'USD',
            'frequence_paiement' => 'semestriel',
            'description' => 'Catégorie de test externe',
        ]);
        $user = User::factory()->create(['role' => 'membre']);

        return Membre::create([
            'user_id' => $user->id,
            'matricule' => 'EXT-' . $monthlyAmount . '-' . fake()->unique()->numerify('####'),
            'nom' => 'Test',
            'prenom' => 'Externe',
            'email' => fake()->unique()->safeEmail(),
            'telephone' => '+25779000000',
            'categorie_id' => $category->id,
            'statut' => 'actif',
            'date_adhesion' => '2026-01-01',
        ])->load('categorie');
    }

    private function recordPayment(Membre $membre, float $amount, string $date): void
    {
        Cotisation::create([
            'membre_id' => $membre->id,
            'matricule' => $membre->matricule,
            'montant' => $amount,
            'devise' => 'USD',
            'date_paiement' => $date,
            'statut' => 'partiel',
            'mode_paiement' => 'banque',
            'reference_paiement' => 'TEST-' . uniqid(),
        ]);
    }
}
