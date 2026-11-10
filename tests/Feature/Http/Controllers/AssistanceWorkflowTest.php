<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Assistance;
use App\Models\CategorieMembre;
use App\Models\Configuration;
use App\Models\Cotisation;
use App\Models\Credit;
use App\Services\CaisseService;
use App\Models\Membre;
use App\Models\TypeAssistance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Assistance pipeline:
 * en_attente -> (gestionnaire) en_cours -> (responsable/admin) approuve -> (staff) verse ; or rejete.
 */
final class AssistanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $gestionnaire;
    private User $responsable;
    private User $memberUser;
    private User $otherMemberUser;
    private Membre $membre;
    private TypeAssistance $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->gestionnaire = User::factory()->create(['role' => 'gestionnaire']);
        $this->responsable = User::factory()->create(['role' => 'responsable']);
        $this->memberUser = User::factory()->create(['role' => 'membre']);
        $this->otherMemberUser = User::factory()->create(['role' => 'membre']);

        $categorie = CategorieMembre::factory()->create();
        $this->membre = Membre::factory()->create([
            'user_id' => $this->memberUser->id,
            'categorie_id' => $categorie->id,
            'statut' => 'actif',
        ]);
        Membre::factory()->create([
            'user_id' => $this->otherMemberUser->id,
            'categorie_id' => $categorie->id,
            'statut' => 'actif',
        ]);
        $this->type = TypeAssistance::factory()->create(['nom' => 'Décès', 'montant_standard' => 200000]);
    }

    // ---- Request ------------------------------------------------------------

    public function test_member_can_request_an_assistance_and_the_document_link_works(): void
    {
        Sanctum::actingAs($this->memberUser);

        $this->post('/api/demande-assistance', [
            'type_assistance_id' => $this->type->id,
            'montant' => 150000,
            'document_justificatif' => UploadedFile::fake()->create('acte.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $assistance = Assistance::firstOrFail();
        $this->assertSame('en_attente', $assistance->statut);
        $raw = $assistance->getRawOriginal('justificatif');
        $this->assertStringStartsWith('uploads/justificatifs/', $raw);
        $this->assertFileExists(public_path($raw));
        $this->assertSame(asset($raw), $assistance->justificatif);

        @unlink(public_path($raw));
    }

    public function test_amount_above_the_type_standard_is_refused(): void
    {
        Sanctum::actingAs($this->memberUser);

        $this->post('/api/demande-assistance', [
            'type_assistance_id' => $this->type->id,
            'montant' => 500000,
            'document_justificatif' => UploadedFile::fake()->create('acte.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post('/api/demande-assistance', [
            'type_assistance_id' => $this->type->id,
            'montant' => -10,
            'document_justificatif' => UploadedFile::fake()->create('acte.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, Assistance::count());
    }

    public function test_member_repaying_a_credit_cannot_request_an_assistance(): void
    {
        Credit::create([
            'membre_id' => $this->membre->id,
            'montant_demande' => 100000,
            'montant_accorde' => 100000,
            'taux_interet' => 0,
            'duree_mois' => 10,
            'montant_total_rembourser' => 100000,
            'montant_mensualite' => 10000,
            'date_demande' => now(),
            'date_approbation' => now(),
            'statut' => 'approuve',
            'created_by' => $this->admin->id,
            'user_id' => $this->admin->id,
        ])->forceFill(['montant_restant' => 50000])->save();

        Sanctum::actingAs($this->memberUser);
        $this->post('/api/demande-assistance', [
            'type_assistance_id' => $this->type->id,
            'montant' => 100000,
            'document_justificatif' => UploadedFile::fake()->create('acte.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_request_without_any_gestionnaire_does_not_crash(): void
    {
        $this->gestionnaire->delete();
        Sanctum::actingAs($this->memberUser);

        $response = $this->post('/api/demande-assistance', [
            'type_assistance_id' => $this->type->id,
            'montant' => 100000,
            'document_justificatif' => UploadedFile::fake()->create('acte.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        @unlink(public_path(Assistance::firstOrFail()->getRawOriginal('justificatif')));
    }

    // ---- Security -----------------------------------------------------------

    public function test_a_member_cannot_approve_reject_pay_or_change_the_status(): void
    {
        $assistance = $this->makeAssistance();
        Sanctum::actingAs($this->memberUser);

        $this->postJson("/api/assistances/approuve/{$assistance->id}")->assertForbidden();
        $this->postJson("/api/assistances/rejete/{$assistance->id}", ['comment' => 'x'])->assertForbidden();
        $this->post("/api/assistances/{$assistance->id}/verser", [
            'preuve_versement' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        // Owner may edit a pending request, but the status is ignored.
        $this->putJson("/api/assistances/{$assistance->id}", [
            'type_assistance_id' => $this->type->id,
            'montant' => 120000,
            'statut' => 'verse',
        ])->assertOk();

        $this->assertSame('en_attente', $assistance->fresh()->statut);
        $this->assertEquals(120000, $assistance->fresh()->montant);
    }

    public function test_another_member_cannot_see_edit_or_delete_it_and_cannot_list_all(): void
    {
        $assistance = $this->makeAssistance();
        Sanctum::actingAs($this->otherMemberUser);

        $this->getJson('/api/assistances')->assertForbidden();
        $this->getJson("/api/assistances/{$assistance->id}")->assertForbidden();
        $this->putJson("/api/assistances/{$assistance->id}", [
            'type_assistance_id' => $this->type->id,
            'montant' => 1,
        ])->assertForbidden();
        $this->deleteJson("/api/assistances/{$assistance->id}")->assertForbidden();
        $this->getJson('/api/credits')->assertForbidden();

        $this->assertModelExists($assistance);
    }

    public function test_owner_can_see_their_own_assistance(): void
    {
        $assistance = $this->makeAssistance();
        Sanctum::actingAs($this->memberUser);

        $this->getJson("/api/assistances/{$assistance->id}")->assertOk();
    }

    // ---- Steps --------------------------------------------------------------

    public function test_full_pipeline_gestionnaire_responsable_then_pay_out(): void
    {
        $assistance = $this->makeAssistance();

        Sanctum::actingAs($this->gestionnaire);
        $this->postJson("/api/assistances/approuve/{$assistance->id}")->assertOk();
        $this->assertSame('en_cours', $assistance->fresh()->statut);

        // Gestionnaire cannot give the final approval nor pay before approval.
        $this->postJson("/api/assistances/approuve/{$assistance->id}")->assertStatus(422);
        $this->post("/api/assistances/{$assistance->id}/verser", [
            'preuve_versement' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        Sanctum::actingAs($this->responsable);
        $this->postJson("/api/assistances/approuve/{$assistance->id}")->assertOk();
        $assistance->refresh();
        $this->assertSame('approuve', $assistance->statut);
        $this->assertSame($this->responsable->id, $assistance->approved_by);

        // Already approved: no second approval, no rejection, no edit.
        $this->postJson("/api/assistances/approuve/{$assistance->id}")->assertStatus(422);
        $this->postJson("/api/assistances/rejete/{$assistance->id}", ['comment' => 'x'])->assertStatus(422);
        $this->deleteJson("/api/assistances/{$assistance->id}")->assertStatus(422);

        // Pay out with proof: the fund holds 1 000 000 BIF.
        Configuration::create(['cle' => 'solde_initial_caisse', 'valeur' => '1000000']);
        $this->post("/api/assistances/{$assistance->id}/verser", [
            'date_versement' => '2026-11-12',
            'preuve_versement' => UploadedFile::fake()->create('cheque.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        $assistance->refresh();
        $this->assertSame('verse', $assistance->statut);
        $this->assertSame('2026-11-12', $assistance->date_versement->toDateString());
        $this->assertSame($this->responsable->id, $assistance->verse_by);
        $raw = $assistance->getRawOriginal('preuve_versement');
        $this->assertFileExists(public_path($raw));
        @unlink(public_path($raw));

        // The money left the fund, with a trace.
        $this->assertEquals(850000, app(CaisseService::class)->solde());
        $this->assertDatabaseHas('transactions', [
            'type_transaction' => 'assistance',
            'reference_transaction' => $assistance->id,
            'sens' => 'sortie',
            'montant' => 150000,
        ]);

        // Cannot be paid twice.
        $this->post("/api/assistances/{$assistance->id}/verser", [
            'preuve_versement' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_gestionnaire_approval_without_responsable_is_refused_cleanly(): void
    {
        $this->responsable->delete();
        $assistance = $this->makeAssistance();
        Sanctum::actingAs($this->gestionnaire);

        $this->postJson("/api/assistances/approuve/{$assistance->id}")->assertStatus(422);
        $this->assertSame('en_attente', $assistance->fresh()->statut);
    }

    public function test_rejection_needs_a_reason_and_is_final(): void
    {
        $assistance = $this->makeAssistance();
        Sanctum::actingAs($this->gestionnaire);

        $this->postJson("/api/assistances/rejete/{$assistance->id}")->assertStatus(422);
        $this->postJson("/api/assistances/rejete/{$assistance->id}", ['comment' => 'Dossier incomplet'])->assertOk();

        $assistance->refresh();
        $this->assertSame('rejete', $assistance->statut);
        $this->assertSame($this->gestionnaire->id, $assistance->rejected_by);

        $this->postJson("/api/assistances/approuve/{$assistance->id}")->assertStatus(422);
    }

    // ---- Caisse -------------------------------------------------------------

    public function test_pay_out_is_refused_when_the_fund_is_too_low(): void
    {
        // Fund: 100 000 initial + 30 000 contributions − 0 = 130 000 BIF.
        Configuration::create(['cle' => 'solde_initial_caisse', 'valeur' => '100000']);
        Cotisation::create([
            'membre_id' => $this->membre->id,
            'montant' => 30000,
            'devise' => 'FBU',
            'date_paiement' => now(),
            'statut' => 'paye',
            'mode_paiement' => 'Banque',
            'reference_paiement' => 'T-1',
        ]);
        $assistance = $this->makeAssistance(['statut' => 'approuve', 'montant' => 150000, 'date_approbation' => now()]);

        Sanctum::actingAs($this->admin);
        $this->assertEquals(130000, $this->getJson('/api/caisse')->assertOk()->json('data.solde'));

        $this->post("/api/assistances/{$assistance->id}/verser", [
            'preuve_versement' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame('approuve', $assistance->fresh()->statut);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_members_cannot_see_the_fund(): void
    {
        Sanctum::actingAs($this->memberUser);
        $this->getJson('/api/caisse')->assertForbidden();
    }

    public function test_member_can_send_a_photo_as_supporting_document(): void
    {
        Sanctum::actingAs($this->memberUser);

        $this->post('/api/demande-assistance', [
            'type_assistance_id' => $this->type->id,
            'montant' => 100000,
            'document_justificatif' => UploadedFile::fake()->image('acte.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertCreated();

        $raw = Assistance::firstOrFail()->getRawOriginal('justificatif');
        $this->assertStringEndsWith('.jpg', $raw);
        @unlink(public_path($raw));
    }

    // ---- Dashboard ----------------------------------------------------------

    public function test_dashboard_counts_only_granted_assistances(): void
    {
        $this->makeAssistance(['statut' => 'approuve', 'montant' => 100000, 'date_approbation' => now()]);
        $this->makeAssistance(['statut' => 'verse', 'montant' => 50000, 'date_approbation' => now()]);
        $this->makeAssistance(['statut' => 'rejete', 'montant' => 900000]);
        $this->makeAssistance(['statut' => 'en_attente', 'montant' => 70000]);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/dashboard-assistance')->assertOk()->json('data');

        $this->assertSame(2, $data['total_assistances']);
        $this->assertEquals(150000, $data['total_montant_assiste']);
        $this->assertEquals(50000, $data['total_montant_verse']);
        $this->assertSame(1, $data['en_attente']);
        $this->assertCount(2, $data['details_assistances']);
    }

    // ---- Helpers ------------------------------------------------------------

    private function makeAssistance(array $overrides = []): Assistance
    {
        return Assistance::create(array_merge([
            'membre_id' => $this->membre->id,
            'type_assistance_id' => $this->type->id,
            'montant' => 150000,
            'date_demande' => now(),
            'statut' => 'en_attente',
            'justificatif' => 'uploads/justificatifs/test.pdf',
        ], $overrides));
    }
}
