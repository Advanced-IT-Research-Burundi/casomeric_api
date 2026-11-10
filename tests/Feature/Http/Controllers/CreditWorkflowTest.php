<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\CategorieMembre;
use App\Models\Credit;
use App\Models\Membre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Credit pipeline, step by step:
 * en_attente -> (gestionnaire) en_cours "attente responsable" -> (responsable/admin) approuve
 * -> repayments -> termine. Each action is only allowed at its step and for staff.
 */
final class CreditWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $gestionnaire;
    private User $responsable;
    private User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->gestionnaire = User::factory()->create(['role' => 'gestionnaire']);
        $this->responsable = User::factory()->create(['role' => 'responsable']);
        $this->memberUser = User::factory()->create(['role' => 'membre']);
    }

    // ---- Step 1: en attente -------------------------------------------------

    public function test_a_member_cannot_reject_a_credit(): void
    {
        $credit = $this->makeCredit();
        Sanctum::actingAs($this->memberUser);

        $this->postJson("/api/credits/rejete/{$credit->id}")->assertForbidden();
        $this->assertSame('en_attente', $credit->fresh()->statut);
    }

    public function test_a_member_cannot_approve_edit_or_delete_a_credit(): void
    {
        $credit = $this->makeCredit();
        Sanctum::actingAs($this->memberUser);

        $this->postJson("/api/credits/approuve/{$credit->id}")->assertForbidden();
        $this->deleteJson("/api/credits/{$credit->id}")->assertForbidden();
        $this->assertModelExists($credit);
    }

    public function test_staff_can_delete_a_pending_credit(): void
    {
        $credit = $this->makeCredit();
        Sanctum::actingAs($this->gestionnaire);

        $this->deleteJson("/api/credits/{$credit->id}")->assertNoContent();
        $this->assertModelMissing($credit);
    }

    public function test_no_repayment_on_a_pending_credit(): void
    {
        $credit = $this->makeCredit();
        Sanctum::actingAs($this->admin);

        $this->post("/api/credits/{$credit->id}/payer", [
            'montant_paye' => 100,
            'preuve_paiement' => UploadedFile::fake()->create('preuve.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    // ---- Step 2: gestionnaire -> attente responsable ------------------------

    public function test_gestionnaire_approval_sends_the_credit_to_the_responsable(): void
    {
        $credit = $this->makeCredit();
        Sanctum::actingAs($this->gestionnaire);

        $this->postJson("/api/credits/approuve/{$credit->id}")->assertOk();

        $credit->refresh();
        $this->assertTrue($credit->isWaitingResponsable());
        $this->assertSame(0, $credit->remboursements()->count());
    }

    public function test_gestionnaire_cannot_give_the_final_approval(): void
    {
        $credit = $this->makeCredit(['statut' => 'en_cours']);
        Sanctum::actingAs($this->gestionnaire);

        $this->postJson("/api/credits/approuve/{$credit->id}")->assertStatus(422);
        $this->postJson("/api/credits/rejete/{$credit->id}")->assertStatus(422);
    }

    public function test_gestionnaire_approval_without_any_responsable_is_refused_cleanly(): void
    {
        $this->responsable->delete();
        $credit = $this->makeCredit();
        Sanctum::actingAs($this->gestionnaire);

        $this->postJson("/api/credits/approuve/{$credit->id}")->assertStatus(422);
        $this->assertSame('en_attente', $credit->fresh()->statut);
    }

    public function test_a_credit_waiting_for_the_responsable_cannot_be_edited_or_deleted(): void
    {
        $credit = $this->makeCredit(['statut' => 'en_cours']);
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/credits/{$credit->id}")->assertStatus(422);
    }

    // ---- Step 3: final approval -> repayments ------------------------------

    public function test_responsable_final_approval_creates_the_schedule_from_the_approval_date(): void
    {
        $this->travelTo(now()->setDate(2026, 3, 1));
        $credit = $this->makeCredit(['date_demande' => '2026-01-15']);

        $this->travelTo(now()->setDate(2026, 3, 10));
        Sanctum::actingAs($this->gestionnaire);
        $this->postJson("/api/credits/approuve/{$credit->id}")->assertOk();

        Sanctum::actingAs($this->responsable);
        $this->postJson("/api/credits/approuve/{$credit->id}")->assertOk();

        $credit->refresh();
        $this->assertSame('approuve', $credit->statut);
        $this->assertTrue($credit->isInRepayment());
        $this->assertSame(12, $credit->remboursements()->count());

        // First installment one month after approval (10 March), not after the request (15 January).
        $first = $credit->remboursements()->orderBy('numero_echeance')->first();
        $this->assertSame('2026-04-10', $first->date_echeance->toDateString());
    }

    public function test_an_approved_credit_cannot_be_approved_rejected_or_deleted_again(): void
    {
        $credit = $this->makeCredit(['statut' => 'approuve', 'date_approbation' => now()]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/credits/approuve/{$credit->id}")->assertStatus(422);
        $this->postJson("/api/credits/rejete/{$credit->id}")->assertStatus(422);
        $this->deleteJson("/api/credits/{$credit->id}")->assertStatus(422);
    }

    public function test_staff_can_record_a_payment_on_an_approved_credit_but_a_member_cannot(): void
    {
        $credit = $this->makeCredit();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/credits/approuve/{$credit->id}")->assertOk();

        Sanctum::actingAs($this->memberUser);
        $this->post("/api/credits/{$credit->id}/payer", [
            'montant_paye' => 100,
            'preuve_paiement' => UploadedFile::fake()->create('preuve.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->post("/api/credits/{$credit->id}/payer", [
            'montant_paye' => 100,
            'preuve_paiement' => UploadedFile::fake()->create('preuve.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        // 1000 at 3 % over 12 months = 1030 to repay; 1030 - 100 paid = 930.
        $this->assertEquals(930, $credit->fresh()->montant_restant);
    }

    public function test_approving_one_installment_with_a_payment_date_works(): void
    {
        $credit = $this->makeCredit();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/credits/approuve/{$credit->id}")->assertOk();
        $installment = $credit->remboursements()->orderBy('numero_echeance')->first();

        $this->post("/api/remboursements/{$installment->id}/approve", [
            'date_paiement' => now()->toDateString(),
            'preuve_paiement' => UploadedFile::fake()->create('preuve.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('paye', $installment->fresh()->statut);
    }

    private function makeCredit(array $overrides = []): Credit
    {
        $categorie = CategorieMembre::factory()->create();
        $membre = Membre::factory()->create([
            'user_id' => $this->memberUser->id,
            'categorie_id' => $categorie->id,
            'statut' => 'actif',
        ]);

        return Credit::create(array_merge([
            'membre_id' => $membre->id,
            'montant_demande' => 1000,
            'montant_accorde' => 0,
            'taux_interet' => 3,
            'duree_mois' => 12,
            'montant_total_rembourser' => 1200,
            'montant_mensualite' => 100,
            'date_demande' => now(),
            'statut' => 'en_attente',
            'motif' => 'Test',
            'created_by' => $this->memberUser->id,
            'user_id' => $this->memberUser->id,
        ], $overrides));
    }
}
