<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\CategorieMembre;
use App\Models\Configuration;
use App\Models\Credit;
use App\Models\Membre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The server, not the browser, decides how much a credit costs.
 */
final class CreditCalculationTest extends TestCase
{
    use RefreshDatabase;

    private User $memberUser;
    private Membre $membre;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->memberUser = User::factory()->create(['role' => 'membre']);
        $this->membre = Membre::factory()->create([
            'user_id' => $this->memberUser->id,
            'categorie_id' => CategorieMembre::factory()->create()->id,
            'statut' => 'actif',
        ]);
        User::factory()->create(['role' => 'admin']);
    }

    public function test_formula_matches_the_ui(): void
    {
        // 1 000 000 BIF, 3 % per year, 12 months -> 30 000 interest.
        $this->assertSame([
            'montant_interet' => 30000.0,
            'montant_total_rembourser' => 1030000.0,
            'montant_mensualite' => 85833.0,
        ], Credit::calculerRemboursement(1000000, 3, 12));
    }

    public function test_member_request_ignores_totals_and_rate_sent_by_the_browser(): void
    {
        Configuration::create(['cle' => 'taux_interet_credit', 'valeur' => '5']);
        Sanctum::actingAs($this->memberUser);

        $this->postJson('/api/demande-credit', [
            'montant_demande' => 1000000,
            'duree_mois' => 12,
            'taux_interet' => 0,                 // tries a 0 % rate
            'montant_total_rembourser' => 1,     // tries to repay 1 BIF
            'montant_mensualite' => 1,
            'motif' => 'Test',
        ])->assertOk();

        $credit = Credit::firstOrFail();
        $this->assertEquals(5, $credit->taux_interet);
        $this->assertEquals(1050000, $credit->montant_total_rembourser);
        $this->assertEquals(87500, $credit->montant_mensualite);
    }

    public function test_member_request_without_configured_rate_uses_3_percent(): void
    {
        Sanctum::actingAs($this->memberUser);

        $this->postJson('/api/demande-credit', [
            'montant_demande' => 1000000,
            'duree_mois' => 12,
        ])->assertOk();

        $this->assertEquals(1030000, Credit::firstOrFail()->montant_total_rembourser);
    }

    public function test_invalid_amount_or_duration_is_refused(): void
    {
        Sanctum::actingAs($this->memberUser);

        $this->postJson('/api/demande-credit', ['montant_demande' => -500, 'duree_mois' => 12])
            ->assertStatus(422)->assertJsonValidationErrors('montant_demande');
        $this->postJson('/api/demande-credit', ['montant_demande' => 1000, 'duree_mois' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('duree_mois');
    }

    public function test_schedule_adds_up_exactly_to_the_total(): void
    {
        Sanctum::actingAs($this->memberUser);
        $this->postJson('/api/demande-credit', [
            'montant_demande' => 1000000,
            'duree_mois' => 12,
        ])->assertOk();
        $credit = Credit::firstOrFail();

        Sanctum::actingAs(User::where('role', 'admin')->first());
        $this->postJson("/api/credits/approuve/{$credit->id}")->assertOk();

        $credit->refresh();
        $echeances = $credit->remboursements()->orderBy('numero_echeance')->get();

        $this->assertCount(12, $echeances);
        $this->assertEquals(85833, $echeances->first()->montant_prevu);
        // 11 × 85 833 = 944 163, so the last one is 1 030 000 − 944 163 = 85 837.
        $this->assertEquals(85837, $echeances->last()->montant_prevu);
        $this->assertEquals(1030000, $echeances->sum('montant_prevu'));
        $this->assertEquals(1030000, $credit->montant_restant);
    }

    public function test_approval_recalculates_on_the_granted_amount(): void
    {
        $credit = Credit::create([
            'membre_id' => $this->membre->id,
            'montant_demande' => 1000000,
            'montant_accorde' => 500000,
            'taux_interet' => 3,
            'duree_mois' => 12,
            'montant_total_rembourser' => 1030000,
            'montant_mensualite' => 85833,
            'date_demande' => now(),
            'statut' => 'en_attente',
            'created_by' => $this->memberUser->id,
            'user_id' => $this->memberUser->id,
        ]);

        Sanctum::actingAs(User::where('role', 'admin')->first());
        $this->postJson("/api/credits/approuve/{$credit->id}")->assertOk();

        $credit->refresh();
        $this->assertEquals(515000, $credit->montant_total_rembourser);
        $this->assertEquals(515000, $credit->remboursements()->sum('montant_prevu'));
    }

    public function test_editing_a_pending_credit_recalculates_and_cannot_change_its_status(): void
    {
        $credit = Credit::create([
            'membre_id' => $this->membre->id,
            'montant_demande' => 1000000,
            'montant_accorde' => 0,
            'taux_interet' => 3,
            'duree_mois' => 12,
            'montant_total_rembourser' => 1030000,
            'montant_mensualite' => 85833,
            'date_demande' => now(),
            'statut' => 'en_attente',
            'created_by' => $this->memberUser->id,
            'user_id' => $this->memberUser->id,
        ]);

        Sanctum::actingAs(User::where('role', 'admin')->first());
        $this->putJson("/api/credits/{$credit->id}", [
            'membre_id' => $this->membre->id,
            'montant_demande' => 2000000,
            'taux_interet' => 3,
            'duree_mois' => 24,
            'montant_total_rembourser' => 1,
            'montant_mensualite' => 1,
            'date_demande' => now()->toDateString(),
            'statut' => 'approuve',           // tries to skip the approval
            'motif' => 'Test',
        ])->assertOk();

        $credit->refresh();
        $this->assertSame('en_attente', $credit->statut);
        $this->assertEquals(2120000, $credit->montant_total_rembourser); // 2 000 000 + 6 % (3 % × 2 years)
        $this->assertEquals(88333, $credit->montant_mensualite);
    }
}
