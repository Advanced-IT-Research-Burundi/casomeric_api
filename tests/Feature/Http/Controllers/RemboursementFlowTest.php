<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\CategorieMembre;
use App\Models\CotisationMensuelle;
use App\Models\Credit;
use App\Models\Membre;
use App\Models\User;
use App\Services\ImportationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Repayments: every way of paying goes through the schedule.
 * Example credit: 1 200 000 BIF at 0 % over 12 months -> 12 installments of 100 000.
 */
final class RemboursementFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Membre $membre;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $memberUser = User::factory()->create(['role' => 'membre']);
        $this->membre = Membre::factory()->create([
            'user_id' => $memberUser->id,
            'matricule' => '12345',
            'nom' => 'Ndayishimiye',
            'prenom' => 'Jean',
            'categorie_id' => CategorieMembre::factory()->create()->id,
            'statut' => 'actif',
        ]);
    }

    // 1. Manual repayment pays the schedule ------------------------------------

    public function test_manual_repayment_pays_the_schedule_instead_of_adding_a_row(): void
    {
        $credit = $this->approvedCredit();
        Sanctum::actingAs($this->admin);

        $this->post('/api/remboursements', [
            'credit_id' => $credit->id,
            'montant_paye' => 150000,
            'date_paiement' => '2026-04-05',
        ], ['Accept' => 'application/json'])->assertCreated();

        $credit->refresh();
        $echeances = $credit->remboursements()->orderBy('numero_echeance')->get();

        $this->assertCount(12, $echeances, 'no extra row next to the schedule');
        $this->assertSame('paye', $echeances[0]->statut);
        $this->assertEquals(100000, $echeances[0]->montant_paye);
        $this->assertSame('prevu', $echeances[1]->statut, 'half-paid installment stays open');
        $this->assertEquals(50000, $echeances[1]->montant_paye);
        $this->assertEquals(1050000, $credit->montant_restant);
        $this->assertSame('approuve', $credit->statut);
    }

    public function test_manual_repayment_cannot_exceed_what_is_owed(): void
    {
        $credit = $this->approvedCredit();
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/remboursements', [
            'credit_id' => $credit->id,
            'montant_paye' => 2000000,
        ])->assertStatus(422);

        $this->assertEquals(1200000, $credit->fresh()->montant_restant);
    }

    // 2. One installment: partial stays partial, no double payment ------------

    public function test_partial_payment_of_an_installment_keeps_it_open(): void
    {
        $credit = $this->approvedCredit();
        $first = $credit->remboursements()->orderBy('numero_echeance')->first();
        Sanctum::actingAs($this->admin);

        $this->payInstallment($first->id, 30000)->assertOk();
        $this->assertSame('prevu', $first->fresh()->statut);
        $this->assertEquals(30000, $first->fresh()->montant_paye);

        // No amount = pay what is still due (70 000).
        $this->payInstallment($first->id)->assertOk();
        $this->assertSame('paye', $first->fresh()->statut);
        $this->assertEquals(100000, $first->fresh()->montant_paye);
        $this->assertEquals(1100000, $credit->fresh()->montant_restant);

        // Paying it again is refused.
        $this->payInstallment($first->id)->assertStatus(422);
        $this->assertEquals(1100000, $credit->fresh()->montant_restant);
    }

    public function test_paying_more_than_due_on_one_installment_is_refused(): void
    {
        $credit = $this->approvedCredit();
        $first = $credit->remboursements()->orderBy('numero_echeance')->first();
        Sanctum::actingAs($this->admin);

        $this->payInstallment($first->id, 150000)->assertStatus(422);
        $this->assertSame('prevu', $first->fresh()->statut);
    }

    // 3. Payment record has real values ----------------------------------------

    public function test_global_payment_is_logged_with_real_amount_and_date(): void
    {
        $credit = $this->approvedCredit();
        Sanctum::actingAs($this->admin);

        $this->post("/api/credits/{$credit->id}/payer", [
            'montant_paye' => 250000,
            'date_paiement' => '2026-04-05',
            'preuve_paiement' => UploadedFile::fake()->create('preuve.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        $log = CotisationMensuelle::where('type', 'CREDIT')->firstOrFail();
        $this->assertEquals(250000, $log->retenu);
        $this->assertEquals(1200000, $log->global);
        $this->assertEquals(250000, $log->regle);
        $this->assertEquals(950000, $log->restant);
        $this->assertSame('2026-04-05', $log->date_cotisation);
        $this->assertSame('12345', $log->matricule);
    }

    // 4. Import ---------------------------------------------------------------

    public function test_import_pays_the_schedule_of_an_approved_credit(): void
    {
        $credit = $this->approvedCredit();

        app(ImportationService::class)->processImport([$this->importLine(100000, restant: 1100000)], '2026-04');

        $credit->refresh();
        $this->assertEquals(1100000, $credit->montant_restant);
        $this->assertSame('paye', $credit->remboursements()->where('numero_echeance', 1)->value('statut'));
        $this->assertCount(12, $credit->remboursements, 'import does not add a row next to the schedule');
        $this->assertSame(1, Credit::count(), 'no new credit created');
    }

    public function test_import_still_finds_a_credit_left_in_en_cours_by_old_data(): void
    {
        $credit = $this->approvedCredit();
        $credit->update(['statut' => 'en_cours']); // old behaviour after paying one installment

        app(ImportationService::class)->processImport([$this->importLine(100000, restant: 1100000)], '2026-04');

        $this->assertSame(1, Credit::count());
        $this->assertEquals(1100000, $credit->fresh()->montant_restant);
    }

    public function test_import_creates_an_unknown_credit_once_and_keeps_paying_it(): void
    {
        $service = app(ImportationService::class);

        // March: credit of 600 000, 100 000 deducted, 500 000 left after.
        $service->processImport([$this->importLine(100000, global: 600000, restant: 500000)], '2026-03');
        $credit = Credit::firstOrFail();
        $this->assertSame('approuve', $credit->statut);
        $this->assertEquals(600000, $credit->montant_total_rembourser);
        $this->assertEquals(500000, $credit->montant_restant);

        // April: same credit is found and paid, no new credit.
        $service->processImport([$this->importLine(100000, global: 600000, restant: 400000)], '2026-04');
        $this->assertSame(1, Credit::count());
        $this->assertEquals(400000, $credit->fresh()->montant_restant);
        $this->assertCount(2, $credit->fresh()->remboursements);
    }

    // 5. Status stays "approuve" until "termine" ------------------------------

    public function test_status_stays_approuve_then_becomes_termine(): void
    {
        $credit = $this->approvedCredit();
        Sanctum::actingAs($this->admin);

        $this->payInstallment($credit->remboursements()->orderBy('numero_echeance')->first()->id)->assertOk();
        $this->assertSame('approuve', $credit->fresh()->statut);

        $this->post('/api/remboursements', [
            'credit_id' => $credit->id,
            'montant_paye' => 1100000,
        ], ['Accept' => 'application/json'])->assertCreated();

        $credit->refresh();
        $this->assertSame('termine', $credit->statut);
        $this->assertEquals(0, $credit->montant_restant);
        $this->assertSame(0, $credit->remboursements()->where('statut', '!=', 'paye')->count());
    }

    // 6. Overdue installments -------------------------------------------------

    public function test_daily_command_marks_past_unpaid_installments_late(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 10));
        $credit = $this->approvedCredit(); // first due 10 Feb, second 10 Mar...

        $this->travelTo(now()->setDate(2026, 3, 15));
        $this->artisan('remboursements:retards')->assertSuccessful();

        $statuts = $credit->remboursements()->orderBy('numero_echeance')->pluck('statut')->all();
        $this->assertSame(['en_retard', 'en_retard', 'prevu'], array_slice($statuts, 0, 3));

        // A late installment can still be paid.
        Sanctum::actingAs($this->admin);
        $this->payInstallment($credit->remboursements()->orderBy('numero_echeance')->first()->id)->assertOk();
        $this->assertSame('paye', $credit->remboursements()->where('numero_echeance', 1)->value('statut'));
    }

    // Helpers -----------------------------------------------------------------

    private function approvedCredit(): Credit
    {
        $credit = Credit::create([
            'membre_id' => $this->membre->id,
            'montant_demande' => 1200000,
            'montant_accorde' => 0,
            'taux_interet' => 0,
            'duree_mois' => 12,
            'montant_total_rembourser' => 1200000,
            'montant_mensualite' => 100000,
            'date_demande' => now(),
            'statut' => 'en_attente',
            'created_by' => $this->admin->id,
            'user_id' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/credits/approuve/{$credit->id}")->assertOk();

        return $credit->fresh();
    }

    private function payInstallment(int $id, ?float $amount = null)
    {
        return $this->post("/api/remboursements/{$id}/approve", array_filter([
            'montant_paye' => $amount,
            'preuve_paiement' => UploadedFile::fake()->create('preuve.pdf', 10, 'application/pdf'),
        ]), ['Accept' => 'application/json']);
    }

    private function importLine(float $retenu, float $global = 1200000, ?float $restant = null): array
    {
        return [
            'matricule' => '12345',
            'name' => 'Jean Ndayishimiye',
            'global' => $global,
            'regle' => $restant !== null ? $global - $restant : null,
            'restant' => $restant,
            'retenu' => $retenu,
        ];
    }
}
