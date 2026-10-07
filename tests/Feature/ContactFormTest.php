<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\LeadConfirmationMail;
use App\Mail\NewLeadNotificationMail;
use App\Models\Lead;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    private function fillValid($component)
    {
        return $component
            ->call('openWith', 'general')
            ->set('name', 'Jane Provider')
            ->set('email', 'jane@practice.com')
            ->set('practiceName', 'Provider Family Medicine')
            ->set('billableProviders', 3)
            ->set('message', 'Looking for compliance help for our practice.');
    }

    public function test_home_page_includes_the_contact_dialog_and_contact_buttons(): void
    {
        $this->withoutVite()
            ->get(route('home'))
            ->assertOk()
            ->assertSee('open-contact', false)
            ->assertSee('contact-dialog-title', false);
    }

    public function test_contact_page_redirects_to_the_home_page_popup(): void
    {
        $this->get(route('contact'))->assertRedirect(route('home', ['contact' => 'general']));
        $this->get(route('contact', ['package' => 'complete']))->assertRedirect(route('home', ['contact' => 'quote']));
        $this->get(route('contact', ['addon' => 'legal-review']))->assertRedirect(route('home', ['contact' => 'legal']));
        $this->get(route('contact', ['package' => 'advanced']))->assertRedirect(route('home', ['contact' => 'package']));
    }

    public function test_dialog_opens_from_the_query_string_with_the_requested_topic(): void
    {
        Livewire::withQueryParams(['contact' => 'quote'])
            ->test('contact-dialog')
            ->assertSet('open', true)
            ->assertSet('topic', 'quote')
            ->assertSee('Request a Complete tier quote');
    }

    public function test_dialog_stays_closed_without_the_query_string(): void
    {
        Livewire::test('contact-dialog')->assertSet('open', false);
    }

    public function test_opening_with_an_unknown_topic_falls_back_to_general(): void
    {
        Livewire::test('contact-dialog')
            ->call('openWith', 'bogus')
            ->assertSet('topic', 'general');
    }

    public function test_authenticated_users_details_are_prefilled(): void
    {
        $user = User::factory()->create(['name' => 'Jane Provider', 'email' => 'jane@practice.com']);
        Practice::factory()->create(['user_id' => $user->id, 'name' => 'Provider Family Medicine']);

        Livewire::actingAs($user)
            ->test('contact-dialog')
            ->call('openWith', 'general')
            ->assertSet('name', 'Jane Provider')
            ->assertSet('email', 'jane@practice.com')
            ->assertSet('practiceName', 'Provider Family Medicine');
    }

    public function test_guests_details_are_left_blank(): void
    {
        Livewire::test('contact-dialog')
            ->call('openWith', 'general')
            ->assertSet('name', '')
            ->assertSet('email', '')
            ->assertSet('billableProviders', 1);
    }

    public function test_submitting_a_valid_form_creates_a_lead(): void
    {
        $this->fillValid(Livewire::test('contact-dialog'))
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('leads', [
            'email' => 'jane@practice.com',
            'practice_name' => 'Provider Family Medicine',
            'billable_providers' => 3,
            'topic' => 'general',
            'source' => 'contact_form',
            'package_interest' => null,
        ]);
    }

    public function test_message_is_optional(): void
    {
        $this->fillValid(Livewire::test('contact-dialog'))
            ->set('message', '')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('leads', ['email' => 'jane@practice.com', 'message' => null]);
    }

    public function test_complete_quote_topic_records_the_complete_package_interest(): void
    {
        $this->fillValid(Livewire::test('contact-dialog'))
            ->set('topic', 'quote')
            ->call('submit');

        $this->assertDatabaseHas('leads', ['email' => 'jane@practice.com', 'topic' => 'quote', 'package_interest' => 'complete']);
    }

    public function test_submitting_without_required_fields_fails(): void
    {
        Livewire::test('contact-dialog')
            ->call('openWith', 'general')
            ->call('submit')
            ->assertHasErrors(['name' => 'required', 'email' => 'required'])
            ->assertHasNoErrors(['practiceName', 'billableProviders', 'message']);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_invalid_email_fails(): void
    {
        $this->fillValid(Livewire::test('contact-dialog'))
            ->set('email', 'not-an-email')
            ->call('submit')
            ->assertHasErrors(['email']);
    }

    public function test_name_rejects_numeric_characters(): void
    {
        $this->fillValid(Livewire::test('contact-dialog'))
            ->set('name', 'Jane123')
            ->call('submit')
            ->assertHasErrors(['name']);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_name_accepts_letters_spaces_hyphens_apostrophes_and_periods(): void
    {
        $this->fillValid(Livewire::test('contact-dialog'))
            ->set('name', "Dr. Mary-Jane O'Brien")
            ->call('submit')
            ->assertHasNoErrors(['name']);
    }

    public function test_billable_providers_must_be_a_positive_number(): void
    {
        $this->fillValid(Livewire::test('contact-dialog'))
            ->set('billableProviders', 0)
            ->call('submit')
            ->assertHasErrors(['billableProviders']);
    }

    public function test_submitting_sends_a_confirmation_email_to_the_requestor(): void
    {
        Mail::fake();

        $this->fillValid(Livewire::test('contact-dialog'))->call('submit');

        $lead = Lead::where('email', 'jane@practice.com')->first();

        Mail::assertSent(LeadConfirmationMail::class, function ($mail) use ($lead) {
            return $mail->hasTo('jane@practice.com') && $mail->lead->is($lead)
                && str_contains($mail->render(), 'Provider Family Medicine');
        });
    }

    public function test_submitting_notifies_every_admin_user(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin, 'email' => 'admin@empower.test']);
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin, 'email' => 'admin2@empower.test']);
        User::factory()->create(['role' => UserRole::Client, 'email' => 'client@empower.test']);

        $this->fillValid(Livewire::test('contact-dialog'))->call('submit');

        Mail::assertSent(NewLeadNotificationMail::class, fn ($mail) => $mail->hasTo($admin->email));
        Mail::assertSent(NewLeadNotificationMail::class, fn ($mail) => $mail->hasTo($otherAdmin->email));
        Mail::assertNotSent(NewLeadNotificationMail::class, fn ($mail) => $mail->hasTo('client@empower.test'));
    }

    public function test_submitting_does_not_error_when_no_admin_exists(): void
    {
        Mail::fake();

        $this->fillValid(Livewire::test('contact-dialog'))
            ->call('submit')
            ->assertSet('submitted', true);

        Mail::assertNotSent(NewLeadNotificationMail::class);
    }

    public function test_only_name_and_email_are_required(): void
    {
        Livewire::test('contact-dialog')
            ->call('openWith', 'general')
            ->set('name', 'Jane')
            ->set('email', 'jane@practice.com')
            ->set('practiceName', '')
            ->set('billableProviders', '')
            ->set('message', '')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('leads', ['email' => 'jane@practice.com', 'practice_name' => null, 'billable_providers' => null]);
    }

    public function test_html_is_rejected_in_free_text_fields(): void
    {
        $this->fillValid(Livewire::test('contact-dialog'))
            ->set('message', '<script>alert(1)</script>')
            ->set('practiceName', '<b>Evil</b> Clinic')
            ->call('submit')
            ->assertHasErrors(['message', 'practiceName']);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_html_in_the_name_is_rejected(): void
    {
        $this->fillValid(Livewire::test('contact-dialog'))
            ->set('name', '<img src=x onerror=alert(1)>')
            ->call('submit')
            ->assertHasErrors(['name']);
    }

    public function test_sql_injection_text_is_stored_literally_and_harmlessly(): void
    {
        $payload = "Robert'); DROP TABLE leads;--";

        $this->fillValid(Livewire::test('contact-dialog'))
            ->set('message', $payload)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('leads', ['email' => 'jane@practice.com', 'message' => $payload]);
    }

    public function test_the_topic_must_be_one_of_the_known_options(): void
    {
        $this->fillValid(Livewire::test('contact-dialog'))
            ->set('topic', 'anything-else')
            ->call('submit')
            ->assertHasErrors(['topic']);
    }

    public function test_submissions_are_rate_limited_per_ip(): void
    {
        Mail::fake();

        foreach (range(1, 5) as $_) {
            $this->fillValid(Livewire::test('contact-dialog'))->call('submit')->assertHasNoErrors();
        }

        $this->fillValid(Livewire::test('contact-dialog'))->call('submit')->assertHasErrors(['email']);

        $this->assertDatabaseCount('leads', 5);
    }
}
