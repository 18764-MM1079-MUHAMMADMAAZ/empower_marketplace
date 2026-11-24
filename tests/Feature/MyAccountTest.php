<?php

namespace Tests\Feature;

use App\Enums\CardBrand;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\PackageTier;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\GeneratedDocument;
use App\Models\IntakeSubmission;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentLog;
use App\Models\Practice;
use App\Models\ReviewerQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** My Account, v5 design (changes/empower-my-account-v5.html). */
class MyAccountTest extends TestCase
{
    use RefreshDatabase;

    /** A client with a paid annual subscription, a locked practice and an approved intake. */
    private function client(array $orderAttributes = [], array $practiceAttributes = []): array
    {
        $user = User::factory()->create(['name' => 'Jane Provider']);
        $practice = Practice::factory()->locked()->create([
            'user_id' => $user->id,
            'name' => 'Riverside Family Medicine',
            'billable_providers_count' => 3,
            'address' => '7 Clyde Road, Somerset, NJ 08873',
            ...$practiceAttributes,
        ]);
        $package = Package::firstWhere('slug', PackageTier::Advanced->value)
            ?? Package::factory()->create([
                'slug' => PackageTier::Advanced->value, 'name' => 'Advanced Compliance', 'annual_price' => 1299, 'monthly_price' => 129,
                'features' => ['Coding & Documentation Mini Audit² (10 encounters/provider)', 'Security Risk Assessment (SRA)'],
            ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::Paid,
            'billing_cycle' => 'annual',
            'next_bill_date' => now()->addYear()->startOfDay(),
            'card_last_four' => '4242',
            'card_expiry_month' => 12,
            'card_expiry_year' => 2028,
            ...$orderAttributes,
        ]);
        IntakeSubmission::factory()->approved()->create(['order_id' => $order->id, 'reviewed_at' => now()->subDay()]);

        return [$user, $order, $practice];
    }

    private function partnerClient(): array
    {
        return $this->client(
            ['payment_status' => PaymentStatus::ExternallyBilled, 'card_last_four' => null, 'billing_cycle' => 'monthly'],
            ['source_system' => 'talkehr', 'external_practice_id' => '1011163', 'phone' => '(646) 209-9461'],
        );
    }

    // --- Page and menu ---

    public function test_guests_are_sent_home(): void
    {
        $this->get(route('account'))->assertRedirect(route('home'));
    }

    public function test_the_account_menu_has_my_account_upgrade_and_log_out(): void
    {
        [$user] = $this->client();

        $this->actingAs($user)->withoutVite()->get(route('account'))
            ->assertSeeInOrder(['data-account-link>My Account</a>', 'Upgrade Your Package</a>', 'Log out'], false)
            ->assertDontSee('Back to Packages</a>', false);
    }

    public function test_the_admin_account_menu_has_no_upgrade_link(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->withoutVite()->get(route('account'))
            ->assertSeeInOrder(['data-account-link>My Account</a>', 'Admin Panel</a>', 'Log out'], false)
            ->assertDontSee('Upgrade Your Package', false)
            ->assertSee('data-back-to-dashboard', false);
    }

    public function test_the_old_change_password_url_redirects_to_the_security_section(): void
    {
        [$user] = $this->client();

        $this->actingAs($user)->get(route('password.edit'))->assertRedirect(route('account').'#security');
    }

    public function test_a_client_sees_the_v5_sections_in_order_and_none_of_the_old_ones(): void
    {
        [$user] = $this->client();

        $this->actingAs($user)->withoutVite()->get(route('account'))
            ->assertOk()
            ->assertSee('Welcome back, Jane')
            ->assertSee('data-back-to-portal', false)
            ->assertSee('href="'.route('portal').'"', false)
            ->assertSeeInOrder(['id="overview"', 'id="profile"', 'id="plan"', 'id="documents"', 'id="practice"', 'id="hotline"', 'id="security"', 'id="activity"'], false)
            ->assertDontSee('id="review"', false)
            ->assertDontSee('id="notifications"', false)
            ->assertDontSee('Cancel subscription')
            ->assertDontSee('Billing history')
            ->assertDontSee('Update payment method')
            ->assertDontSee('Edit practice info')
            ->assertDontSee('data-stale-banner', false);
    }

    public function test_a_client_without_a_package_is_pointed_to_the_packages(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->withoutVite()->get(route('account'))
            ->assertOk()
            ->assertSee('View packages')
            ->assertDontSee('id="plan"', false)
            ->assertDontSee('id="documents"', false)
            ->assertSee('id="practice"', false)
            ->assertSee('id="activity"', false);
    }

    public function test_admins_only_see_sign_in_and_security_and_notifications(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->withoutVite()->get(route('account'))
            ->assertOk()
            ->assertSee('id="security"', false)
            ->assertSee('id="notifications"', false)
            ->assertDontSee('id="overview"', false)
            ->assertDontSee('id="activity"', false);
    }

    // --- Alerts ---

    public function test_two_step_off_shows_the_alert_and_the_menu_badge(): void
    {
        [$user] = $this->client();

        $this->actingAs($user)->withoutVite()->get(route('account'))
            ->assertSee('data-alert-two-factor', false)
            ->assertSee('Two-step verification is off.')
            ->assertSee('data-security-attention', false);

        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($user)->withoutVite()->get(route('account'))
            ->assertDontSee('data-alert-two-factor', false)
            ->assertDontSee('data-security-attention', false);
    }

    public function test_open_reviewer_questions_show_the_answer_questions_alert(): void
    {
        [$user, $order] = $this->client();
        $order->intakeSubmission->update(['status' => IntakeSubmissionStatus::UnderReview]);
        ReviewerQuestion::factory()->count(2)->create(['intake_submission_id' => $order->intakeSubmission->id]);

        $this->actingAs($user)->withoutVite()->get(route('account'))
            ->assertSee('The compliance team has 2 questions for you.')
            ->assertSee('Answer questions');
    }

    // --- Overview ---

    public function test_the_overview_shows_the_four_tiles_and_the_next_policy_review(): void
    {
        [$user, $order] = $this->client();
        $this->actingAs($user);

        Livewire::test('account.overview')
            ->assertSeeHtml('data-account-status="Approved"')
            ->assertSee('by the Empower compliance team')
            ->assertSee($order->next_bill_date->format('M j, Y'))
            ->assertSee('Billed to card ending 4242')
            ->assertSee('Plan Subscription Type')
            ->assertSee('Plan: Advanced')
            ->assertSeeHtml('data-payment-type="Annually"')
            ->assertSee('Next annual policy review: ')
            ->assertSee($order->intakeSubmission->reviewed_at->copy()->addYear()->format('M j, Y'));
    }

    public function test_account_status_follows_the_review(): void
    {
        [$user, $order] = $this->client();
        $submission = $order->intakeSubmission;
        $this->actingAs($user);

        $submission->update(['status' => IntakeSubmissionStatus::Submitted, 'submitted_at' => now()]);
        Livewire::test('account.overview')->assertSeeHtml('data-account-status="Submitted"')->assertSee('awaiting the Empower compliance team');

        $submission->update(['status' => IntakeSubmissionStatus::UnderReview]);
        Livewire::test('account.overview')->assertSeeHtml('data-account-status="In progress"');

        ReviewerQuestion::factory()->create(['intake_submission_id' => $submission->id]);
        Livewire::test('account.overview')->assertSeeHtml('data-account-status="Action needed"')->assertSee('has 1 question for you');
    }

    public function test_a_partner_client_is_billed_with_their_talkehr_plan(): void
    {
        [$user] = $this->partnerClient();
        $this->actingAs($user);

        Livewire::test('account.overview')
            ->assertSee('Billed with your talkEHR Plan')
            ->assertSeeHtml('data-payment-type="Monthly"');
    }

    // --- My Profile ---

    public function test_my_profile_splits_the_name_and_shows_the_user_type(): void
    {
        [$user] = $this->partnerClient();
        $user->update(['name' => 'Wayne A Best', 'is_practice_admin' => true]);
        ActivityLog::record('sso.empower_sso_api_login', 'Signed in via talkEHR SSO', user: $user);
        $this->actingAs($user);

        Livewire::test('account.profile')
            ->assertSee('Your details come from talkEHR.')
            ->assertSeeHtml('data-synced-from="talkEHR"')
            ->assertSeeInOrder(['First name', 'Wayne', 'Middle name', 'A', 'Last name', 'Best'])
            ->assertSee('Role (talkEHR)')
            ->assertSee('Practice administrator')
            ->assertSee('Empower admins only')
            ->assertSeeHtml('data-user-type="actual"')
            ->assertSee('Actual user')
            ->assertSee('Last synced from talkEHR');
    }

    public function test_an_email_sign_up_has_no_partner_sync(): void
    {
        [$user] = $this->client();
        $user->update(['user_type' => 'dummy']);
        $this->actingAs($user);

        Livewire::test('account.profile')
            ->assertDontSeeHtml('data-synced-from')
            ->assertDontSee('Last synced')
            ->assertSee('Dummy user');
    }

    // --- Plan ---

    public function test_the_plan_lists_the_package_features_without_footnote_marks(): void
    {
        [$user] = $this->client();
        $this->actingAs($user);

        Livewire::test('account.plan')
            ->assertSee('Plan: Advanced')
            ->assertSee('3 billable providers')
            ->assertSee('Coding & Documentation Mini Audit (10 encounters/provider)')
            ->assertDontSee('Audit²')
            ->assertSee('Compare packages')
            ->assertSee('charged to your card on file');
    }

    public function test_a_partner_plan_says_payments_are_managed_through_talkehr(): void
    {
        [$user] = $this->partnerClient();
        $this->actingAs($user);

        Livewire::test('account.plan')
            ->assertSee('managed through your talkEHR Plan. No payment method is stored with Empower.');
    }

    // --- Documents ---

    public function test_documents_show_the_tiles_and_approved_in_review_and_reference_rows(): void
    {
        [$user, $order] = $this->client();
        $approved = GeneratedDocument::factory()->completed()->approved()->create(['order_id' => $order->id, 'document_type' => DocumentType::ComplianceEthicsManual]);
        GeneratedDocument::factory()->create(['order_id' => $order->id, 'document_type' => DocumentType::HipaaSecurityManual, 'status' => DocumentStatus::Completed]);
        $order->intakeSubmission->update(['submitted_at' => now(), 'certified_at' => now()]);
        $this->actingAs($user);

        Livewire::test('account.documents')
            ->assertSeeHtml('data-doc-tile="in-review"')
            ->assertSeeInOrder(['In Review', '1', 'Approved', '2', 'Reference', '1', 'All Documents', '4'])
            ->assertSeeHtml(route('documents.download', $approved))
            ->assertSee('Compliance Hotline Poster')
            ->assertSeeHtml('href="'.asset('compliance%20hotline%20poster_empower.pdf').'"')
            ->assertSeeHtml('download="compliance-hotline-poster.pdf"')
            ->assertSee('Not ready yet')
            ->assertSee('Available once the compliance team finishes review')
            ->assertSee('Intake answers')
            ->assertSee('Submitted and certified')
            ->assertDontSee('Version')
            ->assertDontSee('Regenerate');
    }

    /** Same rule as the portal dashboard: the poster is offered only once an intake is approved. */
    public function test_documents_hide_the_hotline_poster_until_the_intake_is_approved(): void
    {
        [$user, $order] = $this->client();
        $order->intakeSubmission->update(['status' => IntakeSubmissionStatus::UnderReview, 'submitted_at' => now()]);
        $this->actingAs($user);

        Livewire::test('account.documents')
            ->assertDontSee('Compliance Hotline Poster')
            ->assertDontSeeHtml('data-hotline-poster-row');
    }

    public function test_generating_a_document_again_bumps_its_version(): void
    {
        [, $order] = $this->client();
        $document = GeneratedDocument::factory()->create(['order_id' => $order->id]);
        $this->assertSame(1, $document->fresh()->version);

        $document->update(['generated_at' => now(), 'status' => DocumentStatus::Completed]);
        $this->assertSame(1, $document->fresh()->version);

        $document->update(['generated_at' => now()->addMinute()]);
        $this->assertSame(2, $document->fresh()->version);
    }

    // --- Practice Information ---

    public function test_practice_information_is_read_only_and_synced_for_partner_practices(): void
    {
        [$user] = $this->partnerClient();
        $this->actingAs($user);

        Livewire::test('account.practice-info')
            ->assertSee('Synced from your talkEHR account')
            ->assertSeeInOrder(['Practice details', 'Legal name', 'Riverside Family Medicine', 'Identifiers', 'talkEHR practice ID', '1011163', 'Contact', 'Main phone', '(646) 209-9461', 'Locations', 'Primary address', 'Account', 'Admin name', 'Jane Provider', 'talkEHR plan'])
            ->assertSee('3 billable providers')
            ->assertDontSee('Edit');
    }

    public function test_an_email_practice_has_no_partner_rows(): void
    {
        [$user] = $this->client();
        $this->actingAs($user);

        Livewire::test('account.practice-info')
            ->assertSee('Shown in your compliance manuals.')
            ->assertDontSee('practice ID')
            ->assertDontSeeHtml('data-synced-from');
    }

    // --- Compliance Hotline ---

    public function test_adding_a_hotline_number_formats_it_and_flags_the_documents(): void
    {
        [$user, $order, $practice] = $this->client();
        $document = GeneratedDocument::factory()->completed()->approved()->create(['order_id' => $order->id]);
        $this->actingAs($user);

        Livewire::test('account.hotline')
            ->assertSee('No number added')
            ->assertSee('Not added yet')
            ->call('edit')
            ->set('draft', '1 555 555 0100')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('(555) 555-0100')
            ->assertSee('Hotline saved. Your compliance manuals will be regenerated with the new details.');

        $this->assertSame('(555) 555-0100', $practice->fresh()->compliance_hotline_number);
        $this->assertTrue($document->fresh()->is_stale);
        $this->assertSame('hotline_updated', $document->fresh()->stale_reason);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'practice.hotline_updated', 'user_id' => $user->id]);
    }

    public function test_a_hotline_number_must_be_10_us_digits(): void
    {
        [$user, , $practice] = $this->client();
        $this->actingAs($user);

        Livewire::test('account.hotline')
            ->call('edit')
            ->set('draft', '555 0100')
            ->call('save')
            ->assertHasErrors(['draft']);

        $this->assertNull($practice->fresh()->compliance_hotline_number);
    }

    public function test_saving_the_same_hotline_number_leaves_documents_alone(): void
    {
        [$user, $order] = $this->client(practiceAttributes: ['compliance_hotline_number' => '(555) 555-0100']);
        $document = GeneratedDocument::factory()->completed()->approved()->create(['order_id' => $order->id]);
        $this->actingAs($user);

        Livewire::test('account.hotline')
            ->assertSee('Edit')
            ->call('edit')
            ->assertSet('draft', '(555) 555-0100')
            ->call('save')
            ->assertDontSee('Hotline saved.');

        $this->assertFalse($document->fresh()->is_stale);
    }

    /** "Use the Empower compliance hotline" in the questionnaire's Team section stores no number of
     *  the practice's own — the card shows the Empower hotline instead of "No number added". */
    public function test_hotline_shows_the_empower_hotline_chosen_in_the_questionnaire(): void
    {
        [$user] = $this->client(practiceAttributes: ['uses_ehcp_hotline' => true, 'compliance_hotline_number' => null]);
        $this->actingAs($user);

        Livewire::test('account.hotline')
            ->assertSee('Empower Compliance Hotline')
            ->assertSee(Practice::EMPOWER_HOTLINE_PHONE)
            ->assertSee(Practice::EMPOWER_HOTLINE_EMAIL)
            ->assertSee('Use our own number')
            ->assertDontSee('No number added')
            ->assertDontSee('Not added yet');
    }

    /** "We have our own hotline" stores whatever was typed (a number and/or an email), shown as is. */
    public function test_hotline_shows_the_practices_own_hotline_from_the_questionnaire(): void
    {
        [$user] = $this->client(practiceAttributes: ['uses_ehcp_hotline' => false, 'compliance_hotline_number' => '800-555-0199 / ethics@practice.com']);
        $this->actingAs($user);

        Livewire::test('account.hotline')
            ->assertSee('800-555-0199 / ethics@practice.com')
            ->assertDontSee(Practice::EMPOWER_HOTLINE_PHONE)
            ->assertDontSee('No number added');
    }

    public function test_switching_from_the_empower_hotline_to_an_own_number_and_back(): void
    {
        [$user, $order, $practice] = $this->client(practiceAttributes: ['uses_ehcp_hotline' => true, 'compliance_hotline_number' => null]);
        $document = GeneratedDocument::factory()->completed()->approved()->create(['order_id' => $order->id]);
        $this->actingAs($user);

        $component = Livewire::test('account.hotline')
            ->call('edit')
            ->assertSet('draft', '')
            ->set('draft', '555 555 0100')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('(555) 555-0100');

        $practice->refresh();
        $this->assertFalse($practice->uses_ehcp_hotline);
        $this->assertSame('(555) 555-0100', $practice->compliance_hotline_number);
        $this->assertTrue($document->fresh()->is_stale);

        $document->update(['is_stale' => false, 'stale_reason' => null]);

        $component->call('edit')
            ->call('useEmpowerHotline')
            ->assertSee(Practice::EMPOWER_HOTLINE_PHONE);

        $practice->refresh();
        $this->assertTrue($practice->uses_ehcp_hotline);
        $this->assertNull($practice->compliance_hotline_number);
        $this->assertTrue($document->fresh()->is_stale);
    }

    // --- Sign-in & Security ---

    public function test_sign_in_and_security_matches_the_v5_layout(): void
    {
        [$user] = $this->partnerClient();
        $this->actingAs($user);

        Livewire::test('account.security')
            ->assertSee('Member since')
            ->assertSee('Email and password, or talkEHR sign-in')
            ->assertSeeHtml('data-password-toggle')
            ->assertSee('Change password')
            ->assertSeeHtml("Where you're signed in");
    }

    // --- Payment receipts (still linked from emails and the portal) ---

    public function test_a_client_can_view_the_receipt_for_their_payment(): void
    {
        [$user, $order] = $this->client();
        $payment = PaymentLog::factory()->create(['user_id' => $user->id, 'order_id' => $order->id, 'package_id' => $order->package_id, 'amount' => 3507.30, 'transaction_id' => 'TXN123']);

        $this->actingAs($user)->withoutVite()->get(route('payments.receipt', $payment))
            ->assertOk()
            ->assertSee('$3,507.30')
            ->assertSee('TXN123');
    }

    public function test_receipts_are_private_and_only_for_successful_payments(): void
    {
        [$user, $order] = $this->client();
        $someoneElses = PaymentLog::factory()->create();
        $declined = PaymentLog::factory()->declined()->create(['user_id' => $user->id, 'package_id' => $order->package_id]);

        $this->actingAs($user)->get(route('payments.receipt', $someoneElses))->assertForbidden();
        $this->actingAs($user)->get(route('payments.receipt', $declined))->assertNotFound();
    }

    public function test_card_brands_are_recognised_from_the_leading_digits(): void
    {
        $this->assertSame(CardBrand::Visa, CardBrand::fromNumber('4242424242424242'));
        $this->assertSame(CardBrand::Mastercard, CardBrand::fromNumber('5555 5555 5555 4444'));
        $this->assertSame(CardBrand::Amex, CardBrand::fromNumber('378282'));
        $this->assertSame(CardBrand::Discover, CardBrand::fromNumber('6011111111111117'));
        $this->assertNull(CardBrand::fromNumber('123456'));
    }
}
