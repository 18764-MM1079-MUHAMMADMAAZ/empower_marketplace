<?php

namespace Tests\Feature;

use App\Enums\AiExtractionStatus;
use App\Enums\BillingCycle;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\IntakeUploadType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateComplianceDocument;
use App\Jobs\ProcessIntakeUpload;
use App\Mail\AdminIntakeSubmittedMail;
use App\Mail\AdminPaymentReceivedMail;
use App\Mail\ClientPaymentReceiptMail;
use App\Mail\ClientTrialCancelledMail;
use App\Mail\ClientTrialStartedMail;
use App\Mail\WelcomeCredentialsMail;
use App\Models\CompliancePolicy;
use App\Models\DiscountCode;
use App\Models\GeneratedDocument;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use App\Services\MtbcCardCipher;
use Database\Seeders\QuestionnaireSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(QuestionnaireSeeder::class);
    }

    /**
     * Http::fake() resolves stubs in registration order and stops at the first match, so a
     * blanket default in setUp() can never be overridden by a later, more specific fake in an
     * individual test — every test that reaches pay()'s charge step registers its own.
     */
    /**
     * Also fakes the Empower Payment API's auth/tokenize endpoints: pay() tokenizes the card
     * (for recurring billing) right after a successful charge, so every test reaching pay()'s
     * charge step must have both faked or it'll make a real network call to MTBC's UAT sandbox.
     */
    private function fakeSuccessfulCharge(): void
    {
        Http::fake([
            config('services.clover_mtbc.base_url') => Http::response([
                'status' => true,
                'message' => 'Payment Successful',
                'data' => ['id' => 'TEST_TXN_ID', 'amount' => 1, 'paid' => true, 'status' => 'succeeded'],
            ]),
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/tokenize' => Http::response([
                'status' => true,
                'message' => 'Card tokenized successfully',
                'data' => ['token' => '4111114281501111', 'firstSix' => '411111', 'lastFour' => '1111', 'referenceNumber' => 'REF123'],
            ]),
        ]);
    }

    private function fakeSuccessfulTokenize(): void
    {
        Http::fake([
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/tokenize' => Http::response([
                'status' => true,
                'message' => 'Card tokenized successfully',
                'data' => ['token' => '4111114281501111', 'firstSix' => '411111', 'lastFour' => '1111', 'referenceNumber' => 'REF123'],
            ]),
        ]);
    }

    private function fakeSuccessfulDetokenizeAndCharge(): void
    {
        $cipher = new MtbcCardCipher;

        Http::fake([
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/detokenize' => Http::response([
                'status' => true,
                'data' => ['value' => $cipher->encrypt('4111111111111111'), 'cvv' => $cipher->encrypt('123'), 'referenceNumber' => 'REF123'],
            ]),
            '*/api/payment/Create_Charge' => Http::response([
                'status' => true,
                'message' => 'Payment Successful',
                'data' => ['id' => 'TEST_RENEWAL_TXN_ID'],
            ]),
        ]);
    }

    // ── Guest access ────────────────────────────────────────────────────────

    public function test_guest_can_view_portal_and_sees_account_creation_fields(): void
    {
        $this->withoutVite()->get(route('portal'))
            ->assertOk()
            ->assertSee('Account Information')
            ->assertSee('Payment Details');
    }

    public function test_authenticated_user_sees_portal(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);

        $this->withoutVite()->actingAs($user)->get(route('portal'))->assertOk();
    }

    public function test_admin_visiting_portal_is_redirected_to_admin_dashboard(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('portal')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_visiting_portal_without_a_practice_creates_one(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->practice);

        Livewire::actingAs($user)->test('portal');

        $this->assertNotNull($user->fresh()->practice);
    }

    public function test_guest_cannot_call_save_profile_directly(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);

        Livewire::test('portal')->call('saveProfile');
    }

    public function test_guest_cannot_call_finalize_intake_directly(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);

        Livewire::test('portal')->call('finalizeIntake');
    }

    public function test_osha_location_can_be_added_even_if_practice_was_missing_on_load(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('portal');
        $practice = $user->fresh()->practice;

        Livewire::actingAs($user)->test('portal.osha-location-modal', ['practiceId' => $practice->id])
            ->dispatch('open-osha-modal')
            ->set('name', 'Main Office')
            ->call('save');

        $this->assertDatabaseHas('osha_locations', [
            'practice_id' => $practice->id,
            'name' => 'Main Office',
        ]);
    }

    // ── Step 1: Payment ─────────────────────────────────────────────────────

    public function test_guest_paying_creates_account_practice_and_order(): void
    {
        Mail::fake();
        $this->fakeSuccessfulCharge();

        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('accountName', 'Jane Provider')
            ->set('accountEmail', 'jane@practice.com')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertSee('Payment of $999 received')
            ->assertSeeText('Account created for Jane Provider')
            ->assertSeeText('jane@practice.com');

        $user = User::where('email', 'jane@practice.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->practice);
        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::Paid->value,
            'payment_reference' => 'TEST_TXN_ID',
        ]);

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('7 Clyde Road', $order->billing_address['address1']);

        $this->assertDatabaseHas('payment_logs', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'success' => true,
            'transaction_id' => 'TEST_TXN_ID',
        ]);

        $this->assertNotNull($order->terms_accepted_at);
        $this->assertNotNull($order->terms_accepted_ip);

        $this->assertDatabaseHas('activity_logs', [
            'event_type' => 'order.terms_accepted',
            'user_id' => $user->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_guest_paying_emails_the_generated_password_and_it_works_for_login(): void
    {
        Mail::fake();
        $this->fakeSuccessfulCharge();

        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('accountName', 'Jane Provider')
            ->set('accountEmail', 'jane@practice.com')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true);

        $capturedPassword = null;

        Mail::assertQueued(WelcomeCredentialsMail::class, function ($mail) use (&$capturedPassword) {
            $capturedPassword = $mail->password;

            return $mail->hasTo('jane@practice.com') && strlen($mail->password) >= 16;
        });

        $user = User::where('email', 'jane@practice.com')->first();

        Livewire::test('auth.login-form')
            ->set('email', $user->email)
            ->set('password', $capturedPassword)
            ->call('login')
            ->assertRedirect(route('portal'));
    }

    public function test_guest_pay_requires_account_fields(): void
    {
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123')
            ->assertHasErrors(['accountName', 'accountEmail']);
    }

    public function test_guest_pay_rejects_duplicate_email(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'taken@example.com']);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('accountName', 'Jane Provider')
            ->set('accountEmail', 'taken@example.com')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123')
            ->assertHasErrors(['accountEmail']);

        Mail::assertNothingSent();
    }

    public function test_authenticated_user_paying_does_not_require_account_fields(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();
    }

    public function test_paying_creates_order_and_shows_confirmation_on_step_1(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create([
            'slug' => 'essential',
            'annual_price' => 999,
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertSet('step', 1)
            ->assertSee('Payment of $999 received')
            ->assertDontSee('Account created for');

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::Paid->value,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'event_type' => 'order.paid',
        ]);
    }

    public function test_paying_charges_per_provider_and_persists_the_count(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'billable_providers_count' => 1]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billableProviders', 3)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertSee('Payment of $2,997 received')
            ->assertSeeText('3 providers');

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'package_id' => $package->id,
            'original_price' => 2997,
            'amount_paid' => 2997,
        ]);

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'billable_providers_count' => 3,
        ]);

        Http::assertSent(fn ($request) => ($request['amount'] ?? null) === 2997.0);
    }

    public function test_paying_notifies_every_admin_by_email(): void
    {
        Mail::fake();
        $this->fakeSuccessfulCharge();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true);

        Mail::assertQueued(AdminPaymentReceivedMail::class, fn ($mail) => $mail->hasTo($admin->email));
        Mail::assertQueued(AdminPaymentReceivedMail::class, fn ($mail) => $mail->hasTo($otherAdmin->email));
        Mail::assertNotQueued(AdminPaymentReceivedMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_paying_also_creates_an_in_app_notification_for_every_admin(): void
    {
        $this->fakeSuccessfulCharge();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true);

        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
        $this->assertSame('Payment received', $admin->fresh()->notifications()->first()->data['title']);
    }

    public function test_paying_emails_the_client_a_receipt_with_a_pdf_attached(): void
    {
        Mail::fake();
        $this->fakeSuccessfulCharge();

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true);

        Mail::assertQueued(ClientPaymentReceiptMail::class, fn ($mail) => $mail->hasTo($user->email));
    }

    public function test_continuing_after_payment_advances_to_step_2(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 1)
            ->call('goToStep', 2)
            ->assertSet('step', 2);
    }

    public function test_registering_with_a_package_preselects_it_on_the_payment_step(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        $this->withoutVite()->actingAs($user)->get('/portal?package=essential')
            ->assertOk()
            ->assertSee('Payment Details');
    }

    public function test_falls_back_to_session_intended_package_when_no_query_string(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        session(['intended_package' => 'essential']);

        $this->withoutVite()->actingAs($user)->get('/portal')
            ->assertOk()
            ->assertSee('Payment Details');

        $this->assertNull(session('intended_package'));
    }

    public function test_selecting_a_new_package_after_an_existing_purchase_starts_a_fresh_order(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $this->makeApprovedOrder($user);
        Package::factory()->create(['slug' => 'advanced', 'annual_price' => 2499, 'is_active' => true]);

        $this->withoutVite()->actingAs($user)->get('/portal?package=advanced')
            ->assertOk()
            ->assertSee('Payment Details')
            ->assertDontSee('Your Dashboard');
    }

    public function test_selecting_an_already_purchased_package_returns_to_its_existing_order(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $this->makeApprovedOrder($user);

        $this->withoutVite()->actingAs($user)->get('/portal?package=essential')
            ->assertOk()
            ->assertSee('Your history, payments and generated documents');
    }

    public function test_pay_requires_package_and_card_fields(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->call('pay')
            ->assertHasErrors(['selectedPackageId', 'cardName', 'cardNumber', 'cardExpiry', 'cardCvc']);
    }

    /**
     * Regression test: card field errors previously vanished the moment any other Livewire
     * request fired (e.g. live-validating an unrelated field), because Livewire only persists
     * error-bag entries for real bound properties across requests — and cardName/cardNumber/
     * etc. are deliberately not properties (see pay()'s docblock). See $cardErrors + boot().
     */
    public function test_card_field_errors_survive_editing_an_unrelated_live_validated_field(): void
    {
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay')
            ->assertHasErrors(['cardName', 'cardNumber', 'cardExpiry', 'cardCvc', 'accountName', 'accountEmail'])
            ->set('accountName', 'Jane Provider')
            ->assertHasNoErrors(['accountName'])
            ->assertHasErrors(['cardName', 'cardNumber', 'cardExpiry', 'cardCvc']);
    }

    /**
     * Card number/expiry/CVC are deliberately NOT bound Livewire properties (see pay()'s
     * docblock), so they can no longer validate live as the client types — only the billing
     * address fields (never cardholder data) can. This test replaces the old card-focused one.
     */
    public function test_billing_fields_validate_live_without_calling_pay(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '')
            ->assertHasErrors(['billingAddress1'])
            ->set('billingAddress1', '7 Clyde Road')
            ->assertHasNoErrors(['billingAddress1'])
            ->set('billingZip', str_repeat('1', 25))
            ->assertHasErrors(['billingZip']);
    }

    public function test_guest_account_fields_validate_live_without_calling_pay(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('accountName', '')
            ->assertHasErrors(['accountName'])
            ->set('accountName', 'Jane Provider')
            ->assertHasNoErrors(['accountName'])
            ->set('accountEmail', 'taken@example.com')
            ->assertHasErrors(['accountEmail'])
            ->set('accountEmail', 'jane@practice.com')
            ->assertHasNoErrors(['accountEmail']);
    }

    /** Regression test: Laravel's default "email" rule uses lenient RFC validation, which
     *  allows a domain with no TLD at all (e.g. "jane@gmail" passes). Requiring the "filter"
     *  driver too (PHP's filter_var) catches this. */
    public function test_account_email_requires_a_full_domain_with_a_tld(): void
    {
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('accountEmail', 'jane@gmail')
            ->assertHasErrors(['accountEmail'])
            ->set('accountEmail', 'jane@gmail.com')
            ->assertHasNoErrors(['accountEmail']);
    }

    public function test_pay_rejects_a_card_number_with_the_wrong_digit_count(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->call('pay', 'Jane Provider', '4242', '12/27', '123')
            ->assertHasErrors(['cardNumber']);
    }

    public function test_pay_rejects_a_card_number_that_is_not_exactly_16_digits(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->call('pay', 'Jane Provider', '424242424242424', '12/27', '123')
            ->assertHasErrors(['cardNumber']);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->call('pay', 'Jane Provider', '42424242424242424', '12/27', '123')
            ->assertHasErrors(['cardNumber']);
    }

    public function test_pay_accepts_a_spaced_out_card_number(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors(['cardNumber']);
    }

    public function test_pay_rejects_an_expiry_month_outside_01_to_12(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '13/27', '123')
            ->assertHasErrors(['cardExpiry']);
    }

    public function test_pay_rejects_an_expired_card(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '01/20', '123')
            ->assertHasErrors(['cardExpiry']);
    }

    /** Regression test: the expiry check used to compare only the year, so a card expiring
     *  earlier in the *current* year (e.g. May when it's now August) was never flagged. */
    public function test_pay_rejects_a_card_that_expired_earlier_this_year(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        $expiry = now()->subMonth()->format('m/y');

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', $expiry, '123')
            ->assertHasErrors(['cardExpiry']);
    }

    public function test_pay_accepts_a_card_expiring_this_month(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        $expiry = now()->format('m/y');

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', $expiry, '123', true)
            ->assertHasNoErrors(['cardExpiry']);
    }

    public function test_pay_rejects_a_cvc_that_is_too_short(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '12')
            ->assertHasErrors(['cardCvc']);
    }

    public function test_a_declined_charge_shows_an_error_and_creates_no_order(): void
    {
        Http::fake([
            config('services.clover_mtbc.base_url') => Http::response([
                'status' => false,
                'message' => 'Your card was declined.',
                'data' => null,
            ], 400),
        ]);

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasErrors(['payment']);

        $this->assertDatabaseMissing('orders', ['user_id' => $user->id]);

        $this->assertDatabaseHas('payment_logs', [
            'user_id' => $user->id,
            'order_id' => null,
            'success' => false,
            'message' => 'Your card was declined.',
        ]);
    }

    public function test_a_declined_charge_shows_both_a_field_reason_and_a_reassurance_banner(): void
    {
        Http::fake([
            config('services.clover_mtbc.base_url') => Http::response([
                'status' => false,
                'message' => 'Insufficient funds.',
                'data' => null,
            ], 400),
        ]);

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        $component = Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true);

        $this->assertSame('Insufficient funds.', $component->errors()->first('cardNumber'));
        $this->assertSame("Payment didn't go through. You haven't been charged.", $component->errors()->first('payment'));
    }

    public function test_a_declined_charge_for_a_guest_creates_no_account(): void
    {
        Http::fake([
            config('services.clover_mtbc.base_url') => Http::response(['status' => false, 'message' => 'Card declined.', 'data' => null], 400),
        ]);

        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('accountName', 'Jane Provider')
            ->set('accountEmail', 'jane@practice.com')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasErrors(['payment']);

        $this->assertDatabaseMissing('users', ['email' => 'jane@practice.com']);
        $this->assertGuest();

        $this->assertDatabaseHas('payment_logs', [
            'user_id' => null,
            'guest_email' => 'jane@practice.com',
            'success' => false,
            'message' => 'Card declined.',
        ]);
    }

    public function test_paying_charges_exactly_once_for_the_selected_packages_price(): void
    {
        $this->fakeSuccessfulCharge();

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        // The charge itself must still fire exactly once (not once per package); the other two
        // requests are the tokenize call afterward for recurring billing (an access-token fetch,
        // then the tokenize call itself).
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => ($request['amount'] ?? null) === 1299.0);
    }

    public function test_paying_prefills_step_2_practice_address_with_the_full_billing_address(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'address' => null]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertSet('practiceAddress', '7 Clyde Road, Somerset, NJ, 08873');
    }

    public function test_pay_requires_terms_to_be_accepted(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123')
            ->assertHasErrors(['termsAccepted']);

        $this->assertDatabaseMissing('orders', ['user_id' => $user->id]);
    }

    public function test_pay_succeeds_when_terms_are_accepted(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();
    }

    public function test_accepting_terms_is_recorded_on_the_order(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true);

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertNotNull($order->terms_accepted_at);
        $this->assertSame('127.0.0.1', $order->terms_accepted_ip);
    }

    public function test_validate_payment_passes_and_charges_nothing_when_fields_are_valid(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('validatePayment', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123')
            ->assertHasNoErrors();

        Http::assertNothingSent();
        $this->assertDatabaseMissing('orders', ['user_id' => $user->id]);
    }

    public function test_validate_payment_reports_an_invalid_card_number_without_opening_the_terms_step(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('validatePayment', 'Jane Provider', '4242', '12/27', '123')
            ->assertHasErrors(['cardNumber']);
    }

    // ── Discount codes ──────────────────────────────────────────────────────

    public function test_applying_a_valid_discount_code_reduces_the_total(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $discountCode = DiscountCode::factory()->create(['code' => 'SAVE20', 'percentage' => 20]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('discountCodeInput', 'save20')
            ->call('applyDiscountCode')
            ->assertHasNoErrors()
            ->assertSet('appliedDiscountCodeId', $discountCode->id)
            ->assertSee('-$199.80')
            ->assertSee('$799.20');
    }

    public function test_removing_a_discount_code_restores_the_full_total(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $discountCode = DiscountCode::factory()->create(['code' => 'SAVE20', 'percentage' => 20]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('discountCodeInput', 'SAVE20')
            ->call('applyDiscountCode')
            ->assertSet('appliedDiscountCodeId', $discountCode->id)
            ->call('removeDiscountCode')
            ->assertSet('appliedDiscountCodeId', null)
            ->assertSee('$999');
    }

    public function test_applying_an_invalid_discount_code_shows_an_error(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('discountCodeInput', 'NOPE123')
            ->call('applyDiscountCode')
            ->assertHasErrors(['discountCodeInput'])
            ->assertSee('This discount code is invalid.');
    }

    public function test_applying_an_expired_discount_code_shows_an_error(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        DiscountCode::factory()->create(['code' => 'OLD10', 'percentage' => 10, 'expires_at' => now()->subDay()]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('discountCodeInput', 'OLD10')
            ->call('applyDiscountCode')
            ->assertHasErrors(['discountCodeInput'])
            ->assertSee('This discount code has expired.');
    }

    public function test_applying_an_inactive_discount_code_shows_an_error(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        DiscountCode::factory()->create(['code' => 'OFF10', 'percentage' => 10, 'is_active' => false]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('discountCodeInput', 'OFF10')
            ->call('applyDiscountCode')
            ->assertHasErrors(['discountCodeInput'])
            ->assertSee('This discount code is inactive.');
    }

    public function test_applying_a_discount_code_that_reached_its_usage_limit_shows_an_error(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        DiscountCode::factory()->create(['code' => 'LIMIT1', 'percentage' => 10, 'max_uses' => 1, 'used_count' => 1]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('discountCodeInput', 'LIMIT1')
            ->call('applyDiscountCode')
            ->assertHasErrors(['discountCodeInput'])
            ->assertSee('This discount code has reached its usage limit.');
    }

    public function test_applying_a_discount_code_that_is_not_yet_active_shows_an_error(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        DiscountCode::factory()->create(['code' => 'FUTURE10', 'percentage' => 10, 'starts_at' => now()->addWeek()]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('discountCodeInput', 'FUTURE10')
            ->call('applyDiscountCode')
            ->assertHasErrors(['discountCodeInput'])
            ->assertSee('This discount code is not yet active.');
    }

    public function test_paying_with_a_valid_discount_code_charges_the_discounted_amount_and_records_it(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $discountCode = DiscountCode::factory()->create(['code' => 'SAVE20', 'percentage' => 20]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->set('discountCodeInput', 'SAVE20')
            ->call('applyDiscountCode')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        Http::assertSent(fn ($request) => ($request['amount'] ?? null) === 799.2);

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('799.20', $order->amount_paid);
        $this->assertSame('999.00', $order->original_price);
        $this->assertSame('199.80', $order->discount_amount);
        $this->assertSame('SAVE20', $order->discount_code);
        $this->assertSame(20, $order->discount_percentage);
        $this->assertSame($discountCode->id, $order->discount_code_id);

        $this->assertSame(1, $discountCode->fresh()->used_count);
    }

    public function test_paying_rejects_a_discount_code_that_was_deactivated_after_being_applied(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $discountCode = DiscountCode::factory()->create(['code' => 'SAVE20', 'percentage' => 20]);

        $component = Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->set('discountCodeInput', 'SAVE20')
            ->call('applyDiscountCode')
            ->assertHasNoErrors();

        // Simulates an admin deactivating the code in the time between the client applying it
        // and actually submitting payment — pay() must re-check, not trust the earlier apply.
        $discountCode->update(['is_active' => false]);

        $component->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasErrors(['discountCodeInput']);

        $this->assertDatabaseMissing('orders', ['user_id' => $user->id]);
        Http::assertNothingSent();
    }

    public function test_revisiting_portal_before_saving_profile_prefills_practice_address_from_checkout(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'address' => null]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::Paid,
            'status' => OrderStatus::Paid,
            'billing_address' => ['name' => 'Jane Provider', 'address1' => '7 Clyde Road', 'city' => 'Somerset', 'state' => 'NJ', 'zip' => '08873'],
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('practiceAddress', '7 Clyde Road, Somerset, NJ, 08873');
    }

    // ── Billing cycle ────────────────────────────────────────────────────────

    /**
     * The Annually/Monthly toggle was removed from Step 1 — the cycle is now chosen on the
     * pricing page and carried into checkout via the billing_cycle query param instead.
     */
    public function test_billing_cycle_toggle_no_longer_renders_in_step_1(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'monthly_price' => 100, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->assertSet('billingCycle', 'annual')
            ->assertDontSee('Please choose your billing cycle');
    }

    public function test_billing_cycle_is_set_from_the_query_string_carried_over_from_the_pricing_page(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'monthly_price' => 100, 'is_active' => true]);

        $this->withoutVite()->actingAs($user)->get('/portal?package=essential&billing_cycle=monthly')
            ->assertOk()
            ->assertSee('$100')
            ->assertSee('/ month', false);
    }

    public function test_an_invalid_billing_cycle_query_value_falls_back_to_the_annual_default(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'monthly_price' => 100, 'is_active' => true]);

        $this->withoutVite()->actingAs($user)->get('/portal?package=essential&billing_cycle=garbage')
            ->assertOk()
            ->assertSee('$999')
            ->assertSee('/ year', false);
    }

    public function test_selecting_monthly_billing_updates_the_displayed_price_and_discount(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'monthly_price' => 100, 'is_active' => true]);
        DiscountCode::factory()->create(['code' => 'SAVE20', 'percentage' => 20]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('discountCodeInput', 'SAVE20')
            ->call('applyDiscountCode')
            ->set('billingCycle', 'monthly')
            ->assertSee('-$20.00')
            ->assertSee('$80.00');
    }

    public function test_switching_to_a_package_without_monthly_pricing_resets_billing_cycle_to_annual(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $monthlyPackage = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'monthly_price' => 100, 'is_active' => true]);
        $annualOnlyPackage = Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'monthly_price' => null, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $monthlyPackage->id)
            ->set('billingCycle', 'monthly')
            ->assertSet('billingCycle', 'monthly')
            ->set('selectedPackageId', $annualOnlyPackage->id)
            ->assertSet('billingCycle', 'annual');
    }

    public function test_paying_monthly_charges_the_flat_monthly_price_and_persists_the_cycle(): void
    {
        $this->fakeSuccessfulCharge();

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'monthly_price' => 129, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingCycle', 'monthly')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        Http::assertSent(fn ($request) => ($request['amount'] ?? null) === 129.0);

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(BillingCycle::Monthly, $order->billing_cycle);
        $this->assertEquals(129.0, (float) $order->original_price);
        $this->assertEquals(129.0, (float) $order->amount_paid);

        // Recurring billing: a direct-pay order now also tokenizes its card and schedules the
        // next renewal exactly like the free-trial-converted path does.
        $this->assertSame('4111114281501111', $order->clover_card_token);
        $this->assertSame('1111', $order->card_last_four);
        $this->assertSame('REF123', $order->mtbc_reference_number);
        $this->assertNotNull($order->next_bill_date);
        $this->assertTrue($order->next_bill_date->isSameDay(now()->addMonth()));
    }

    public function test_a_forged_monthly_billing_cycle_is_clamped_back_to_annual_when_the_package_has_no_monthly_price(): void
    {
        $this->fakeSuccessfulCharge();
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'monthly_price' => null, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            // Never reachable via the UI (the toggle doesn't render for this package) — simulates
            // a forged Livewire request bypassing the client-side hide.
            ->set('billingCycle', 'monthly')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(BillingCycle::Annual, $order->billing_cycle);
        $this->assertEquals(999.0, (float) $order->original_price);
    }

    public function test_paying_annually_stores_a_reusable_card_token_and_schedules_the_next_renewal(): void
    {
        $this->fakeSuccessfulCharge();

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('4111114281501111', $order->clover_card_token);
        $this->assertSame(12, $order->card_expiry_month);
        $this->assertSame(2027, $order->card_expiry_year);
        $this->assertSame('1111', $order->card_last_four);
        $this->assertSame('REF123', $order->mtbc_reference_number);
        $this->assertNotNull($order->next_bill_date);
        $this->assertTrue($order->next_bill_date->isSameDay(now()->addYear()));
    }

    public function test_pay_still_completes_checkout_when_tokenizing_the_card_for_renewal_fails(): void
    {
        Http::fake([
            config('services.clover_mtbc.base_url') => Http::response([
                'status' => true,
                'message' => 'Payment Successful',
                'data' => ['id' => 'TEST_TXN_ID'],
            ]),
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/tokenize' => Http::response(['status' => false, 'message' => 'Invalid card number', 'data' => null]),
        ]);

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);

        // The charge already succeeded above, so a tokenize failure must not surface as a
        // checkout error — the client already paid and expects a completed order.
        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNull($order->clover_card_token);
        $this->assertNotNull($order->next_bill_date);
    }

    public function test_free_trial_checkout_on_monthly_billing_freezes_the_monthly_price_and_persists_the_cycle(): void
    {
        Mail::fake();
        $this->fakeSuccessfulTokenize();

        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'monthly_price' => 100, 'is_active' => true]);
        DiscountCode::factory()->freeTrial(30)->create(['code' => 'TRIAL30']);

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingCycle', 'monthly')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->set('discountCodeInput', 'TRIAL30')
            ->call('applyDiscountCode')
            ->call('payFreeTrial', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(BillingCycle::Monthly, $order->billing_cycle);
        $this->assertEquals(100.0, (float) $order->original_price);
        $this->assertEquals(0, (float) $order->amount_paid);
    }

    public function test_proceed_with_payment_on_a_monthly_trial_advances_next_bill_date_by_one_month(): void
    {
        Mail::fake();
        $this->fakeSuccessfulDetokenizeAndCharge();

        $package = Package::factory()->create(['annual_price' => 999, 'monthly_price' => 100, 'is_active' => true]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->trialing()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'billing_cycle' => BillingCycle::Monthly,
            'original_price' => 100,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('dashboardOrderId', $order->id)
            ->call('convertTrialToPaid', $order->id)
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertEquals(100.0, (float) $order->amount_paid);
        $this->assertTrue($order->next_bill_date->isSameDay(now()->addMonth()));
    }

    // ── Free trial checkout ─────────────────────────────────────────────────

    public function test_applying_a_free_trial_code_succeeds(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        DiscountCode::factory()->freeTrial(30)->create(['code' => 'TRIAL30']);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('discountCodeInput', 'TRIAL30')
            ->call('applyDiscountCode')
            ->assertHasNoErrors()
            ->assertSet('appliedDiscountCodeId', fn ($id) => $id !== null)
            ->assertSee('Free Trial (TRIAL30)')
            ->assertSee('Due Today')
            ->assertSee('$0.00')
            ->assertSee('Start Free Trial');
    }

    public function test_free_trial_checkout_tokenizes_card_and_creates_trialing_order(): void
    {
        Mail::fake();
        $this->fakeSuccessfulTokenize();

        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        DiscountCode::factory()->freeTrial(30)->create(['code' => 'TRIAL30']);

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->set('discountCodeInput', 'TRIAL30')
            ->call('applyDiscountCode')
            ->call('payFreeTrial', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        $order = Order::where('user_id', $user->id)->firstOrFail();

        $this->assertSame(PaymentStatus::Trialing, $order->payment_status);
        $this->assertEquals(0, (float) $order->amount_paid);
        $this->assertSame('4111114281501111', $order->clover_card_token);
        $this->assertSame(12, $order->card_expiry_month);
        $this->assertSame(2027, $order->card_expiry_year);
        $this->assertSame('1111', $order->card_last_four);
        $this->assertSame('REF123', $order->mtbc_reference_number);
        $this->assertNotNull($order->trial_ends_at);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $order->trial_ends_at->timestamp, 5);

        Mail::assertQueued(ClientTrialStartedMail::class);
    }

    public function test_free_trial_checkout_freezes_the_per_provider_price_and_persists_the_count(): void
    {
        Mail::fake();
        $this->fakeSuccessfulTokenize();

        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        DiscountCode::factory()->freeTrial(30)->create(['code' => 'TRIAL30']);

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'billable_providers_count' => 1]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billableProviders', 4)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->set('discountCodeInput', 'TRIAL30')
            ->call('applyDiscountCode')
            ->call('payFreeTrial', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        $order = Order::where('user_id', $user->id)->firstOrFail();

        $this->assertEquals(0, (float) $order->amount_paid);
        $this->assertEquals(3996, (float) $order->original_price);
        $this->assertEquals(3996, (float) $order->discount_amount);

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'billable_providers_count' => 4,
        ]);
    }

    public function test_free_trial_checkout_fails_gracefully_when_tokenize_fails(): void
    {
        Http::fake([
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/tokenize' => Http::response(['status' => false, 'message' => 'Invalid card number', 'data' => null]),
        ]);

        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        DiscountCode::factory()->freeTrial(30)->create(['code' => 'TRIAL30']);

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->set('discountCodeInput', 'TRIAL30')
            ->call('applyDiscountCode')
            ->call('payFreeTrial', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasErrors(['payment']);

        $this->assertDatabaseMissing('orders', ['user_id' => $user->id]);
    }

    public function test_guest_can_start_free_trial_and_account_is_created(): void
    {
        Mail::fake();
        $this->fakeSuccessfulTokenize();

        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        DiscountCode::factory()->freeTrial(30)->create(['code' => 'TRIAL30']);

        Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('accountName', 'Jane Provider')
            ->set('accountEmail', 'jane@practice.com')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873')
            ->set('discountCodeInput', 'TRIAL30')
            ->call('applyDiscountCode')
            ->call('payFreeTrial', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        $user = User::where('email', 'jane@practice.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->practice);
        $this->assertAuthenticatedAs($user);
    }

    public function test_proceed_with_payment_converts_trial_to_paid(): void
    {
        Mail::fake();
        $this->fakeSuccessfulDetokenizeAndCharge();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = Package::factory()->create(['annual_price' => 999, 'is_active' => true]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->trialing()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'original_price' => 999,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('dashboardOrderId', $order->id)
            ->call('convertTrialToPaid', $order->id)
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNotNull($order->trial_confirmed_at);
        $this->assertNotNull($order->next_bill_date);
        $this->assertEquals(999.0, (float) $order->amount_paid);

        Mail::assertQueued(ClientPaymentReceiptMail::class);
        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
    }

    public function test_proceed_with_payment_shows_decline_message_on_failed_conversion(): void
    {
        $cipher = new MtbcCardCipher;

        Http::fake([
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/detokenize' => Http::response([
                'status' => true,
                'data' => ['value' => $cipher->encrypt('4111111111111111'), 'cvv' => $cipher->encrypt('123'), 'referenceNumber' => 'REF123'],
            ]),
            '*/api/payment/Create_Charge' => Http::response(['status' => false, 'message' => 'Card declined', 'data' => null], 400),
        ]);

        $package = Package::factory()->create(['annual_price' => 999, 'is_active' => true]);
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->trialing()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'original_price' => 999,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('dashboardOrderId', $order->id)
            ->call('convertTrialToPaid', $order->id)
            ->assertHasErrors(['payment']);

        $order->refresh();
        $this->assertSame(PaymentStatus::Trialing, $order->payment_status);
    }

    public function test_client_can_update_the_stored_card_on_a_trial_order(): void
    {
        $this->fakeSuccessfulTokenize();

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->trialing()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->set('dashboardOrderId', $order->id)
            ->call('updateTrialCard', $order->id, '4242 4242 4242 4242', '11/28', '321')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame('4111114281501111', $order->clover_card_token);
        $this->assertSame(11, $order->card_expiry_month);
        $this->assertSame(2028, $order->card_expiry_year);
        $this->assertSame('1111', $order->card_last_four);

        $this->assertDatabaseHas('activity_logs', [
            'event_type' => 'order.card_updated',
            'order_id' => $order->id,
        ]);
    }

    public function test_updating_the_stored_card_rejects_an_invalid_card_number(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->trialing()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->set('dashboardOrderId', $order->id)
            ->call('updateTrialCard', $order->id, '123', '11/28', '321')
            ->assertHasErrors(['cardNumber']);

        Http::assertNothingSent();
    }

    public function test_dashboard_shows_an_active_trial_banner_with_proceed_and_cancel_actions(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->trialing()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->set('dashboardOrderId', $order->id)
            ->assertSee('Free trial active')
            ->assertSee('Proceed with Payment')
            ->assertSeeHtml('confirmCancel('.$order->id.',');
    }

    public function test_client_can_cancel_trial_subscription(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->trialing()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('dashboardOrderId', $order->id)
            ->call('cancelSubscription', $order->id);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);

        Mail::assertQueued(ClientTrialCancelledMail::class);
    }

    public function test_dashboard_shows_a_past_due_banner_with_the_last_renewal_error(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->pastDue()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->set('dashboardOrderId', $order->id)
            ->assertSee('couldn')
            ->assertSee($order->last_renewal_error);
    }

    public function test_dashboard_shows_a_renewal_date_and_cancel_link_for_a_healthy_subscription(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->convertedFromTrial()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->set('dashboardOrderId', $order->id)
            ->assertSee('Renews')
            ->assertSee($order->next_bill_date->format('M j, Y'));
    }

    public function test_a_client_with_a_cancelled_trial_cannot_submit_intake(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        Order::factory()->trialCancelled()->create(['user_id' => $user->id]);
        Bus::fake();

        Livewire::actingAs($user)
            ->test('portal')
            ->call('finalizeIntake')
            ->assertHasErrors(['payment']);

        Bus::assertNotDispatched(GenerateComplianceDocument::class);
    }

    public function test_a_client_with_a_cancelled_trial_cannot_regenerate_a_document(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = Order::factory()->trialCancelled()->create(['user_id' => $user->id]);
        $document = GeneratedDocument::factory()->completed()->create(['order_id' => $order->id]);
        Bus::fake();

        Livewire::actingAs($user)
            ->test('portal')
            ->set('dashboardOrderId', $order->id)
            ->call('regenerateDocument', $document->id)
            ->assertHasErrors(['payment']);

        Bus::assertNotDispatched(GenerateComplianceDocument::class);
    }

    // ── Step 2: Practice Profile ────────────────────────────────────────────

    public function test_back_button_returns_to_step_1(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'is_profile_locked' => false]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->call('goToStep', 2)
            ->call('goToStep', 1)
            ->assertSet('step', 1);
    }

    public function test_step2_delegates_to_the_practice_intake_wizard_component(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'is_profile_locked' => false]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->call('goToStep', 2)
            ->assertSee('portal.practice-intake-wizard', false);
    }

    public function test_save_profile_does_not_require_a_new_logo_once_profile_is_locked(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->call('editProfile')
            ->call('saveProfile')
            ->assertHasNoErrors(['logoFile']);
    }

    // ── Step 2: OSHA Modal ──────────────────────────────────────────────────

    public function test_osha_modal_opens_and_creates_location(): void
    {
        $user = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $user->id]);

        $component = Livewire::actingAs($user)->test('portal.osha-location-modal', [
            'practiceId' => $practice->id,
        ]);

        $component->dispatch('open-osha-modal')
            ->assertSet('open', true);

        $component->set('name', 'Main Office')
            ->set('address', '123 Elm St')
            ->call('save')
            ->assertSet('open', false);

        $this->assertDatabaseHas('osha_locations', [
            'practice_id' => $practice->id,
            'name' => 'Main Office',
            'address' => '123 Elm St',
        ]);
    }

    // ── Step 2/3: Practice Intake wizard routing ─────────────────────────────

    public function test_draft_submission_mid_wizard_routes_to_step_2_on_reload(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'basics',
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 2);
    }

    public function test_step_3_answers_review_reflects_gate_decisions_and_excluded_questions(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id, 'committee_none' => true]);
        $package = Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        $section = IntakeSection::create(['key' => 'compliance_program', 'label' => 'Compliance program', 'sort_order' => 1]);
        $policy = CompliancePolicy::create(['manual' => 'compliance_ethics_manual', 'code' => 'CMP-01', 'title' => 'Oversight', 'requirements' => []]);
        $committeeQuestion = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 1, 'title' => 'Compliance Committee', 'requires_compliance_committee' => true]);
        $committeeQuestion->policies()->sync([$policy->id]);
        $keptQuestion = IntakeQuestion::create(['intake_section_id' => $section->id, 'sort_order' => 2, 'title' => 'Owner & board oversight']);
        $keptQuestion->policies()->sync([$policy->id]);

        // A second section whose gate hasn't been resolved yet (e.g. skipped for now) — its
        // question must count as "Open", not get folded into the raw IntakeQuestion total.
        $sectionTwo = IntakeSection::create(['key' => 'patient_privacy', 'label' => 'Patient privacy', 'sort_order' => 2]);
        $openQuestion = IntakeQuestion::create(['intake_section_id' => $sectionTwo->id, 'sort_order' => 1, 'title' => 'Notice of Privacy Practices']);
        $openQuestion->policies()->sync([$policy->id]);

        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'done',
            'wizard_selected_services' => [],
            'wizard_section_gates' => [(string) $section->id => ['mode' => 'none', 'picked' => []]],
        ]);

        $component = Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 3)
            ->assertSee('Documented procedures (section gate)')
            ->assertSee('No documented procedures · policy defaults for all 1')
            ->assertSee('Skipped by your answers (section removed)')
            ->assertSee('No Compliance Committee (from your intake)');

        $this->assertSame(
            ['answered' => 0, 'policyDefault' => 1, 'open' => 1],
            $component->instance()->workflowAnswerCounts
        );
    }

    public function test_draft_submission_with_wizard_done_routes_to_step_3_on_reload(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'done',
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 3);
    }

    public function test_rejected_submission_still_routes_to_step_4_on_reload(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Rejected,
            'reviewer_notes' => 'Please clarify your HIPAA privacy officer contact.',
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 4)
            ->assertSee('Please clarify your HIPAA privacy officer contact.');
    }

    public function test_reupload_button_reopens_the_submission_as_draft_and_routes_to_step_2(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        $submission = IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Rejected,
            'wizard_screen' => 'done',
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 4)
            ->call('reuploadForOrder', $order->id)
            ->assertSet('step', 2);

        $this->assertSame(IntakeSubmissionStatus::Draft, $submission->fresh()->status);
    }

    // ── Step 3: Upload & Confirm ─────────────────────────────────────────────

    public function test_step3_shows_a_summary_and_requires_certification_fields(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id, 'name' => 'Sunrise Family Medicine']);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        $submission = IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'done',
        ]);
        IntakeUpload::factory()->completed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'original_filename' => 'employee-handbook.pdf',
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 3)
            ->assertSet('certifiedByName', $user->name)
            ->assertSet('certifiedByTitle', 'Account Holder')
            ->assertSee('Sunrise Family Medicine')
            ->assertSee('employee-handbook.pdf')
            ->call('finalizeIntake')
            ->assertHasErrors(['certifiedSignature', 'certifyChecked'])
            ->assertHasNoErrors(['certifiedByName', 'certifiedByTitle']);
    }

    /**
     * A client can type a custom name/title over the defaults, step back into the practice
     * intake wizard (e.g. to fix an answer), and return to Step 3 without losing what they typed
     * — autosaved via updatedCertifiedByName()/updatedCertifiedByTitle() onto the submission
     * record itself, not just the component's in-memory state.
     */
    public function test_custom_certification_fields_survive_a_trip_back_into_the_wizard_and_forward(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id, 'name' => 'Sunrise Family Medicine']);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'done',
        ]);

        $component = Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 3)
            ->set('certifiedByName', 'Dr. Jane Custom')
            ->set('certifiedByTitle', 'Practice Manager');

        $this->assertDatabaseHas('intake_submissions', [
            'order_id' => $order->id,
            'certified_by_name' => 'Dr. Jane Custom',
            'certified_by_title' => 'Practice Manager',
        ]);

        $component->call('goToStep', 2)->assertSet('step', 2);
        $component->call('onIntakeWizardComplete')
            ->assertSet('step', 3)
            ->assertSet('certifiedByName', 'Dr. Jane Custom')
            ->assertSet('certifiedByTitle', 'Practice Manager');
    }

    public function test_finalize_intake_certifies_submits_and_notifies_admins(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'done',
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('certifiedByName', 'Jane Provider')
            ->set('certifiedByTitle', 'Owner')
            ->set('certifiedSignature', 'Jane Provider')
            ->set('certifyChecked', true)
            ->call('finalizeIntake')
            ->assertHasNoErrors()
            // Lands straight on step 4 — its own "sending/uploading/queuing" transition is
            // purely cosmetic and clears itself client-side once its timer finishes.
            ->assertSet('step', 4)
            ->assertSet('justSubmitted', true);

        $this->assertDatabaseHas('intake_submissions', [
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Submitted->value,
            'certified_by_name' => 'Jane Provider',
            'certified_by_title' => 'Owner',
            'certified_signature' => 'Jane Provider',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'event_type' => 'submission.submitted',
        ]);

        Mail::assertSent(AdminIntakeSubmittedMail::class, fn ($mail) => $mail->hasTo($admin->email));

        $this->assertSame(1, $admin->fresh()->unreadNotifications()->count());
        $this->assertSame('Intake submitted for review', $admin->fresh()->notifications()->first()->data['title']);
    }

    public function test_finalize_intake_creates_a_submission_for_every_order_in_the_batch(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $essential = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $professional = Package::factory()->create(['slug' => 'professional', 'annual_price' => 1299, 'is_active' => true]);

        $batchId = (string) Str::ulid();
        $orderA = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $essential->id,
            'checkout_batch_id' => $batchId,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        $orderB = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $professional->id,
            'checkout_batch_id' => $batchId,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        $primarySubmission = IntakeSubmission::factory()->create([
            'order_id' => $orderA->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'done',
        ]);
        IntakeUpload::factory()->completed()->create([
            'intake_submission_id' => $primarySubmission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'original_filename' => 'handbook.pdf',
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('certifiedByName', 'Jane Provider')
            ->set('certifiedByTitle', 'Owner')
            ->set('certifiedSignature', 'Jane Provider')
            ->set('certifyChecked', true)
            ->call('finalizeIntake')
            ->assertSet('step', 4);

        $this->assertDatabaseHas('intake_submissions', ['order_id' => $orderA->id, 'status' => IntakeSubmissionStatus::Submitted->value]);
        $this->assertDatabaseHas('intake_submissions', ['order_id' => $orderB->id, 'status' => IntakeSubmissionStatus::Submitted->value]);

        $this->assertDatabaseCount('intake_uploads', 2);
        $this->assertDatabaseHas('intake_uploads', ['original_filename' => 'handbook.pdf']);
    }

    public function test_finalize_intake_dispatches_ai_review_only_for_untouched_document_uploads(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        $submission = IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'done',
        ]);
        // Still sitting at the wizard's reference-only default — this is the one finalizing
        // should pick up and send for AI review.
        $draftUpload = IntakeUpload::factory()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'ai_extraction_status' => AiExtractionStatus::NotApplicable,
        ]);
        // Already ran through AI review by some other means (e.g. admin test data) — finalizing
        // must leave it alone rather than re-queuing it.
        $alreadyProcessedUpload = IntakeUpload::factory()->completed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('certifiedByName', 'Jane Provider')
            ->set('certifiedByTitle', 'Owner')
            ->set('certifiedSignature', 'Jane Provider')
            ->set('certifyChecked', true)
            ->call('finalizeIntake')
            ->assertHasNoErrors();

        $this->assertEquals(AiExtractionStatus::Pending, $draftUpload->fresh()->ai_extraction_status);
        $this->assertEquals(AiExtractionStatus::Completed, $alreadyProcessedUpload->fresh()->ai_extraction_status);

        Bus::assertDispatched(ProcessIntakeUpload::class, fn ($job) => $job->upload->id === $draftUpload->id);
        Bus::assertNotDispatched(ProcessIntakeUpload::class, fn ($job) => $job->upload->id === $alreadyProcessedUpload->id);
    }

    // ── Step 4: Review Status ───────────────────────────────────────────────

    public function test_check_approval_advances_to_step_5_when_approved(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        IntakeSubmission::factory()->approved()->create(['order_id' => $order->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 4)
            ->call('checkApproval')
            ->assertSet('step', 5);
    }

    public function test_step_4_shows_every_order_in_the_batch_and_waits_for_all_to_be_approved(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $essential = Package::factory()->create(['slug' => 'essential', 'name' => 'Essential Compliance', 'annual_price' => 999, 'is_active' => true]);
        $professional = Package::factory()->create(['slug' => 'professional', 'name' => 'Professional Compliance', 'annual_price' => 1299, 'is_active' => true]);

        $batchId = (string) Str::ulid();
        $orderA = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $essential->id,
            'checkout_batch_id' => $batchId,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        $orderB = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $professional->id,
            'checkout_batch_id' => $batchId,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        IntakeSubmission::factory()->approved()->create(['order_id' => $orderA->id]);
        IntakeSubmission::factory()->create(['order_id' => $orderB->id, 'status' => IntakeSubmissionStatus::UnderReview]);

        $component = Livewire::actingAs($user)
            ->test('portal')
            ->set('orderIds', [$orderA->id, $orderB->id])
            ->set('step', 4)
            ->assertSee('Essential Compliance')
            ->assertSee('Professional Compliance')
            ->assertSee('In review');

        // Not every order is approved yet — stays on step 4.
        $component->call('checkApproval')->assertSet('step', 4);

        IntakeSubmission::where('order_id', $orderB->id)->update(['status' => IntakeSubmissionStatus::Approved]);

        $component->call('checkApproval')->assertSet('step', 5);
    }

    // ── Step 5: Dashboard ───────────────────────────────────────────────────

    private function makeApprovedOrder(User $user): Order
    {
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Approved,
        ]);
        IntakeSubmission::factory()->approved()->create(['order_id' => $order->id]);

        return $order;
    }

    public function test_completed_but_unapproved_document_shows_pending_review_with_no_download_link(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = $this->makeApprovedOrder($user);
        IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $order->id,
            'document_type' => DocumentType::ComplianceEthicsManual,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('Pending')
            ->assertDontSee('Download PDF')
            ->assertDontSee('Ready');

        $this->assertFalse($document->isReady());
    }

    public function test_approved_document_shows_ready_with_a_working_download_link(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = $this->makeApprovedOrder($user);
        IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);
        GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $order->id,
            'document_type' => DocumentType::ComplianceEthicsManual,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('Current')
            ->assertSee('Download')
            ->assertDontSee('Pending Review');
    }

    public function test_a_revoked_document_shows_an_updated_badge_instead_of_ready_or_pending_review(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = $this->makeApprovedOrder($user);
        IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);
        $document = GeneratedDocument::factory()->completed()->create([
            'order_id' => $order->id,
            'document_type' => DocumentType::ComplianceEthicsManual,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'revoked_at' => now(),
        ]);

        $this->assertTrue($document->wasRevoked());

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('Updated')
            ->assertDontSee('Ready')
            ->assertDontSee('Download PDF')
            ->assertDontSee('Pending Review');
    }

    public function test_dashboard_shows_no_expected_documents_when_nothing_was_uploaded(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertDontSee('Compliance & Ethics Manual')
            ->assertDontSee('HIPAA Business Associate Manual');
    }

    public function test_dashboard_shows_only_the_manual_matching_the_uploaded_questionnaire(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = $this->makeApprovedOrder($user);

        IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('Compliance & Ethics Manual')
            ->assertDontSee('HIPAA Business Associate Manual');
    }

    public function test_dashboard_labels_a_polished_employee_manual_upload_distinctly(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = $this->makeApprovedOrder($user);

        $upload = IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'document_category' => 'employee_manual',
            'original_filename' => 'staff-handbook.pdf',
        ]);
        GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $order->id,
            'document_type' => DocumentType::PolishedClientDocument,
            'intake_upload_id' => $upload->id,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('Employee manual (reviewed)')
            ->assertDontSee('Reviewed & Polished Document')
            ->assertDontSee('staff-handbook.pdf');
    }

    public function test_dashboard_labels_essentials_reviewed_uploads_distinctly(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = $this->makeApprovedOrder($user);

        foreach (['compliance_ethics', 'hipaa_privacy', 'hipaa_security', 'training_materials'] as $category) {
            $upload = IntakeUpload::factory()->create([
                'intake_submission_id' => $order->intakeSubmission->id,
                'upload_type' => IntakeUploadType::ClientDocumentForReview,
                'document_category' => $category,
                'original_filename' => "{$category}.pdf",
            ]);
            GeneratedDocument::factory()->completed()->approved()->create([
                'order_id' => $order->id,
                'document_type' => DocumentType::PolishedClientDocument,
                'intake_upload_id' => $upload->id,
            ]);
        }

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('Compliance & Ethics Program (reviewed)')
            ->assertSee('HIPAA Privacy Policies (reviewed)')
            ->assertSee('HIPAA Security Policies (reviewed)')
            ->assertSee('Training Review Memo')
            ->assertDontSee('Reviewed & Polished Document');
    }

    public function test_dashboard_shows_the_exclusions_screening_report_for_every_tier(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create([
            'slug' => 'essential',
            'is_active' => true,
            'included_document_types' => [DocumentType::ExclusionsScreeningReport->value],
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Approved,
        ]);
        IntakeSubmission::factory()->approved()->create(['order_id' => $order->id]);
        GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $order->id,
            'document_type' => DocumentType::ExclusionsScreeningReport,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('Exclusions Screening Report')
            ->assertSee('Current');
    }

    /** Regression: the new practice intake wizard generates Professional/Advanced's manuals
     *  directly from typed answers — no "questionnaire upload" ever exists to drive the legacy
     *  per-upload-type matching above, so expectedDocuments() must also list the package's
     *  included_document_types directly. */
    public function test_dashboard_shows_tier_driven_manuals_with_no_matching_questionnaire_upload(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create([
            'slug' => 'professional',
            'annual_price' => 1299,
            'is_active' => true,
            'included_document_types' => [
                DocumentType::ComplianceEthicsManual->value,
                DocumentType::HipaaPrivacyPolicy->value,
                DocumentType::HipaaSecurityManual->value,
            ],
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Approved,
        ]);
        IntakeSubmission::factory()->approved()->create(['order_id' => $order->id]);
        GeneratedDocument::factory()->completed()->approved()->create([
            'order_id' => $order->id,
            'document_type' => DocumentType::ComplianceEthicsManual,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('Compliance & Ethics Manual')
            ->assertSee('HIPAA Privacy Policy')
            ->assertSee('HIPAA Security Manual')
            ->assertSee('Current');
    }

    public function test_dashboard_shows_practice_info_bar_and_defaults_to_documents_tab(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id, 'name' => 'Sunrise Family Medicine']);
        $order = $this->makeApprovedOrder($user);

        IntakeUpload::factory()->create([
            'intake_submission_id' => $order->intakeSubmission->id,
            'upload_type' => IntakeUploadType::ComplianceEthicsQuestionnaire,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('Sunrise Family Medicine')
            ->assertSee('Compliance & Ethics Manual')
            ->assertSee('Generating')
            ->set('dashboardTab', 'profile')
            ->assertSee('Edit intake answers');
    }

    public function test_documents_tab_shows_a_contact_us_link(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('For any queries')
            ->assertSee(route('contact'), false);
    }

    public function test_client_can_upload_an_additional_document_for_ai_review(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = $this->makeApprovedOrder($user);

        $file = UploadedFile::fake()->create('extra-policy.pdf', 100, 'application/pdf');

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->set('dashboardOrderId', $order->id)
            ->set('additionalDocumentFile', $file)
            ->set('additionalDocumentCategory', 'training_materials')
            ->call('uploadAdditionalDocument')
            ->assertHasNoErrors()
            ->assertSee('Uploaded — this will appear below once our team has reviewed it.');

        $this->assertDatabaseHas('intake_uploads', [
            'intake_submission_id' => $order->intakeSubmission->id,
            'original_filename' => 'extra-policy.pdf',
            'document_category' => 'training_materials',
            'upload_type' => IntakeUploadType::ClientDocumentForReview->value,
            'ai_extraction_status' => AiExtractionStatus::Pending->value,
        ]);

        Bus::assertDispatched(ProcessIntakeUpload::class);

        $this->assertDatabaseHas('activity_logs', [
            'order_id' => $order->id,
            'event_type' => 'upload.additional_document_submitted',
        ]);
    }

    public function test_uploading_an_additional_document_requires_a_file(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->set('dashboardOrderId', $order->id)
            ->call('uploadAdditionalDocument')
            ->assertHasErrors(['additionalDocumentFile']);
    }

    public function test_a_client_with_a_cancelled_trial_cannot_upload_an_additional_document(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = Order::factory()->trialCancelled()->create(['user_id' => $user->id]);
        IntakeSubmission::factory()->approved()->create(['order_id' => $order->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->set('dashboardOrderId', $order->id)
            ->call('uploadAdditionalDocument')
            ->assertHasErrors(['additionalDocumentFile']);

        Bus::assertNotDispatched(ProcessIntakeUpload::class);
    }

    public function test_dashboard_can_switch_between_tabs(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->set('dashboardTab', 'payments')
            ->assertSee('Initial payment')
            ->set('dashboardTab', 'history')
            ->assertSee('No activity yet.');
    }

    public function test_update_practice_info_button_enters_edit_mode_and_returns_to_dashboard(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id, 'address' => 'old address']);
        $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->call('editProfile')
            ->assertSet('step', 2)
            ->assertSet('editingProfile', true)
            ->set('practiceAddress', 'new address')
            ->call('saveProfile')
            ->assertSet('step', 5)
            ->assertSet('editingProfile', false);

        $this->assertDatabaseHas('practices', ['user_id' => $user->id, 'address' => 'new address']);
    }

    public function test_regenerating_a_stale_document_dispatches_job_and_logs_activity(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $order = $this->makeApprovedOrder($user);
        $document = GeneratedDocument::factory()->completed()->stale()->create(['order_id' => $order->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->call('regenerateDocument', $document->id);

        Bus::assertDispatched(GenerateComplianceDocument::class);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'document.regenerate_requested']);
    }

    public function test_cannot_regenerate_another_users_document(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $this->makeApprovedOrder($user);

        $otherPackage = Package::factory()->create(['slug' => 'professional']);
        $otherOrder = Order::factory()->create(['package_id' => $otherPackage->id]);
        $otherDocument = GeneratedDocument::factory()->completed()->create(['order_id' => $otherOrder->id]);

        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->call('regenerateDocument', $otherDocument->id);
    }

    public function test_dashboard_can_switch_between_multiple_purchased_orders_documents(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $orderA = $this->makeApprovedOrder($user);

        $professional = Package::factory()->create(['slug' => 'professional', 'name' => 'Professional Compliance', 'annual_price' => 1299, 'is_active' => true]);
        $orderB = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $professional->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Approved,
        ]);
        IntakeSubmission::factory()->approved()->create(['order_id' => $orderB->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('Professional Compliance')
            ->call('switchOrder', $orderB->id)
            ->assertSet('dashboardOrderId', $orderB->id);
    }

    public function test_cannot_switch_to_another_users_order(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $this->makeApprovedOrder($user);

        $otherPackage = Package::factory()->create(['slug' => 'advanced']);
        $otherOrder = Order::factory()->create(['package_id' => $otherPackage->id]);

        $this->withoutExceptionHandling();
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->call('switchOrder', $otherOrder->id);
    }

    // ── Step 5: "What's next" checklist ────────────────────────────────────

    public function test_whats_next_shows_on_the_dashboard_with_zero_of_five_done(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee("What's next", false)
            ->assertSee('0 of 5 done')
            ->assertSee('Download your documents')
            ->assertSee('Schedule your annual policy review');
    }

    public function test_toggling_a_next_step_marks_it_done_and_persists_to_the_practice(): void
    {
        $user = User::factory()->create();
        $practice = Practice::factory()->locked()->create(['user_id' => $user->id]);
        $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->call('toggleNextStep', 'sign')
            ->assertSee('1 of 5 done');

        $this->assertSame(['sign'], $practice->fresh()->dashboard_next_steps);
    }

    public function test_toggling_an_already_done_next_step_marks_it_not_done(): void
    {
        $user = User::factory()->create();
        $practice = Practice::factory()->locked()->create(['user_id' => $user->id, 'dashboard_next_steps' => ['sign']]);
        $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->call('toggleNextStep', 'sign')
            ->assertSee('0 of 5 done');

        $this->assertSame([], $practice->fresh()->dashboard_next_steps);
    }

    public function test_mark_next_step_done_is_idempotent(): void
    {
        $user = User::factory()->create();
        $practice = Practice::factory()->locked()->create(['user_id' => $user->id, 'dashboard_next_steps' => ['dl']]);
        $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->call('markNextStepDone', 'dl')
            ->assertSee('1 of 5 done');

        $this->assertSame(['dl'], $practice->fresh()->dashboard_next_steps);
    }

    public function test_whats_next_collapses_to_an_all_done_message_once_every_step_is_marked(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create([
            'user_id' => $user->id,
            'dashboard_next_steps' => ['dl', 'share', 'sign', 'train', 'review'],
        ]);
        $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->assertSee('5 of 5 done')
            ->assertSee("You're all set for this year.", false)
            ->assertDontSee('Download sign-off form');
    }

    // ── Step navigation ─────────────────────────────────────────────────────

    public function test_cannot_navigate_to_unreachable_step(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 1)
            ->call('goToStep', 3)
            ->assertSet('step', 1); // step 3 not reachable without payment + profile
    }
}
