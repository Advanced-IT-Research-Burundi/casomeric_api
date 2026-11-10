<?php

namespace Tests\Feature\Http\Controllers;

use App\Jobs\SendForgotPasswordMailJob;
use App\Jobs\SendPasswordResetSuccessMailJob;
use App\Jobs\SendWelcomeMailJob;
use App\Mail\ForgotPasswordMail;
use App\Mail\PasswordResetSuccessMail;
use App\Mail\WelcomEmail;
use App\Models\CategorieMembre;
use App\Models\Membre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class MemberAccountCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_linked_member_account_and_send_a_password_link(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/membres', $this->memberData([
            'create_user' => true,
        ]));

        $response->assertCreated();

        $member = Membre::where('matricule', 'MEM-ACCOUNT-1')->firstOrFail();
        $user = User::where('email', $member->email)->firstOrFail();

        $this->assertSame($user->id, $member->user_id);
        $this->assertSame('Membre Admin', $user->name);
        $this->assertSame($member->nom, $user->nom);
        $this->assertSame($member->prenom, $user->prenom);
        $this->assertSame($member->telephone, $user->telephone);
        $this->assertSame('membre', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertFalse(Hash::check('MEM-ACCOUNT-1', $user->password));

        Mail::assertSent(WelcomEmail::class, function (WelcomEmail $mail) use ($user): bool {
            return $mail->hasTo($user->email)
                && str_starts_with($mail->setPasswordUrl, config('app.frontend_url').'/reset-password?token=')
                && str_contains($mail->setPasswordUrl, 'email=member%40example.test')
                && $mail->loginUrl === config('app.frontend_url').'/login';
        });
        Mail::assertNotSent(ForgotPasswordMail::class);
    }

    public function test_new_member_can_set_password_from_welcome_mail_and_log_in(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/membres', $this->memberData(['create_user' => true]))->assertCreated();

        // Take the token from the link that is inside the welcome email.
        $welcome = Mail::sent(WelcomEmail::class)->first();
        parse_str(parse_url($welcome->setPasswordUrl, PHP_URL_QUERY), $query);

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/reset-password', [
            'email' => $query['email'],
            'token' => $query['token'],
            'password' => 'Nouveau-mot-de-passe-1',
            'password_confirmation' => 'Nouveau-mot-de-passe-1',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => 'member@example.test',
            'password' => 'Nouveau-mot-de-passe-1',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_self_registration_welcome_mail_has_only_the_login_link(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $html = (new WelcomEmail($user))->render();

        $this->assertStringContainsString(config('app.frontend_url').'/login', $html);
        $this->assertStringNotContainsString('/reset-password', $html);
    }

    public function test_member_created_without_the_account_option_is_not_linked_to_the_admin(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/membres', $this->memberData())
            ->assertCreated();

        $member = Membre::where('matricule', 'MEM-ACCOUNT-1')->firstOrFail();

        $this->assertNull($member->user_id);
        $this->assertDatabaseMissing('users', ['email' => $member->email]);
        Mail::assertNotSent(ForgotPasswordMail::class);
    }

    public function test_only_an_admin_can_request_a_member_account(): void
    {
        $manager = User::factory()->create(['role' => 'gestionnaire']);
        Sanctum::actingAs($manager);

        $this->postJson('/api/membres', $this->memberData([
            'create_user' => true,
        ]))->assertForbidden();

        $this->assertDatabaseMissing('membres', ['matricule' => 'MEM-ACCOUNT-1']);
        $this->assertDatabaseMissing('users', ['email' => 'member@example.test']);
    }

    public function test_forgot_password_sends_the_reset_mail(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        Mail::assertSent(ForgotPasswordMail::class, function (ForgotPasswordMail $mail) use ($user): bool {
            return $mail->hasTo($user->email)
                && ! $mail->isNewAccount
                && str_contains($mail->resetUrl, '/reset-password?token=');
        });
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_with_unknown_email_sends_nothing_but_answers_the_same(): void
    {
        Mail::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.test'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_reset_link_token_sets_the_new_password(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'member@example.test',
            'password' => Hash::make('old-password'),
        ]);
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Mail::assertSent(PasswordResetSuccessMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_reset_with_a_bad_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => 'wrong-token',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertStatus(422);
    }

    public function test_password_mails_are_pushed_to_the_queue(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/membres', $this->memberData(['create_user' => true]))->assertCreated();
        Queue::assertPushed(SendWelcomeMailJob::class, fn ($job) => $job->token !== null
            && $job->user->email === 'member@example.test');
        Queue::assertNotPushed(SendForgotPasswordMailJob::class);

        $other = User::factory()->create(['email' => 'other@example.test']);
        $this->postJson('/api/forgot-password', ['email' => $other->email])->assertOk();
        Queue::assertPushed(SendForgotPasswordMailJob::class, fn ($job) => ! $job->isNewAccount
            && $job->user->is($other));

        $token = Password::createToken($other);
        $this->postJson('/api/reset-password', [
            'email' => $other->email,
            'token' => $token,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk();
        Queue::assertPushed(SendPasswordResetSuccessMailJob::class, fn ($job) => $job->user->is($other));
    }

    public function test_both_mails_render(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->assertStringContainsString(
            '/reset-password?token=abc',
            (new ForgotPasswordMail($user, 'abc', isNewAccount: true))->render()
        );
        $this->assertStringContainsString(
            'a été modifié',
            (new PasswordResetSuccessMail($user))->render()
        );
    }

    private function memberData(array $overrides = []): array
    {
        return array_merge([
            'matricule' => 'MEM-ACCOUNT-1',
            'nom' => 'Membre',
            'prenom' => 'Admin',
            'email' => 'member@example.test',
            'telephone' => '+257 00000000',
            'categorie_id' => CategorieMembre::factory()->create()->id,
            'statut' => 'actif',
            'date_adhesion' => now()->toDateString(),
        ], $overrides);
    }
}
