<?php

namespace Tests\Feature;

use App\Enums\AiExtractionStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeMethod;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\IntakeUploadType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateComplianceDocument;
use App\Mail\AdminIntakeSubmittedMail;
use App\Mail\AdminPaymentReceivedMail;
use App\Mail\ClientPaymentReceiptMail;
use App\Mail\WelcomeCredentialsMail;
use App\Models\DiscountCode;
use App\Models\GeneratedDocument;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use App\Support\Questionnaires;
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
    }

    /**
     * Http::fake() resolves stubs in registration order and stops at the first match, so a
     * blanket default in setUp() can never be overridden by a later, more specific fake in an
     * individual test — every test that reaches pay()'s charge step registers its own.
     */
    private function fakeSuccessfulCharge(): void
    {
        Http::fake([
            config('services.clover_mtbc.base_url') => Http::response([
                'status' => true,
                'message' => 'Payment Successful',
                'data' => ['id' => 'TEST_TXN_ID', 'amount' => 1, 'paid' => true, 'status' => 'succeeded'],
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

    public function test_guest_cannot_call_submit_intake_directly(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);

        Livewire::test('portal')->call('submitIntake');
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
            ->assertSee('Payment received');

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

        Mail::assertSent(WelcomeCredentialsMail::class, function ($mail) use (&$capturedPassword) {
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
            ->assertSee('Payment received');

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

        Mail::assertSent(AdminPaymentReceivedMail::class, fn ($mail) => $mail->hasTo($admin->email));
        Mail::assertSent(AdminPaymentReceivedMail::class, fn ($mail) => $mail->hasTo($otherAdmin->email));
        Mail::assertNotSent(AdminPaymentReceivedMail::class, fn ($mail) => $mail->hasTo($user->email));
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

        Mail::assertSent(ClientPaymentReceiptMail::class, fn ($mail) => $mail->hasTo($user->email));
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
            ->assertSee('Your Dashboard');
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
        Http::fake([
            config('services.clover_mtbc.base_url') => Http::response([
                'status' => true,
                'message' => 'Payment Successful',
                'data' => ['id' => 'TEST_TXN_ID'],
            ]),
        ]);

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

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['amount'] === 1299.0);
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

    public function test_applying_a_free_trial_code_at_checkout_is_not_available_yet(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        DiscountCode::factory()->freeTrial()->create(['code' => 'TRIAL30']);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('discountCodeInput', 'TRIAL30')
            ->call('applyDiscountCode')
            ->assertHasErrors(['discountCodeInput'])
            ->assertSee('Free trial codes');
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

        Http::assertSent(fn ($request) => $request['amount'] === 799.2);

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

    public function test_saving_profile_locks_practice_and_advances_to_step_3(): void
    {
        $user = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $user->id, 'is_profile_locked' => false]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('logoFile', UploadedFile::fake()->image('logo.png'))
            ->set('billableProviders', 3)
            ->set('intakeMethod', 'download')
            ->call('saveProfile')
            ->assertSet('step', 3);

        $this->assertDatabaseHas('practices', [
            'id' => $practice->id,
            'name' => 'Sunrise Family Medicine',
            'is_profile_locked' => true,
        ]);
    }

    public function test_saving_profile_with_a_logo_stores_it_on_the_practice(): void
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

        $logo = UploadedFile::fake()->image('logo.png');

        Livewire::actingAs($user)
            ->test('portal')
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('logoFile', $logo)
            ->set('intakeMethod', 'download')
            ->call('saveProfile')
            ->assertSet('step', 3);

        $practice = $user->fresh()->practice;
        $this->assertNotNull($practice->logo_path);
        Storage::disk('public')->assertExists($practice->logo_path);
    }

    public function test_save_profile_requires_practice_name(): void
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
            ->set('practiceName', '')
            ->call('saveProfile')
            ->assertHasErrors(['practiceName']);
    }

    public function test_practice_fields_validate_live_without_calling_save_profile(): void
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
            ->set('practiceName', '')
            ->assertHasErrors(['practiceName'])
            ->set('practiceName', 'Sunrise Family Medicine')
            ->assertHasNoErrors(['practiceName'])
            ->set('npiNumber', '123')
            ->assertHasErrors(['npiNumber'])
            ->set('npiNumber', '1234567890')
            ->assertHasNoErrors(['npiNumber']);
    }

    public function test_save_profile_requires_logo_address_npi_and_specialty_on_first_submission(): void
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
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('practiceAddress', '')
            ->set('npiNumber', '')
            ->set('specialty', '')
            ->call('saveProfile')
            ->assertHasErrors(['logoFile', 'practiceAddress', 'npiNumber', 'specialty']);
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

    public function test_save_profile_validates_npi_number_and_specialty_length(): void
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
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('npiNumber', '123456789012345')
            ->set('specialty', str_repeat('x', 101))
            ->call('saveProfile')
            ->assertHasErrors(['npiNumber', 'specialty']);
    }

    public function test_save_profile_rejects_non_digit_npi_number(): void
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
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('npiNumber', 'sdfsdfsdfsdf')
            ->call('saveProfile')
            ->assertHasErrors(['npiNumber']);
    }

    public function test_save_profile_accepts_a_valid_ten_digit_npi_number(): void
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
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('npiNumber', '1234567890')
            ->call('saveProfile')
            ->assertHasNoErrors(['npiNumber']);
    }

    // ── Step 2: Questionnaire downloads ─────────────────────────────────────

    public function test_every_tier_sees_all_four_questionnaires_in_step2(): void
    {
        foreach (['essential', 'professional', 'advanced', 'complete'] as $slug) {
            $user = User::factory()->create();
            Practice::factory()->create(['user_id' => $user->id]);
            $package = Package::factory()->create([
                'slug' => $slug,
                'annual_price' => $slug === 'complete' ? null : 999,
                'billing_type' => $slug === 'complete' ? 'custom' : 'annual',
                'is_active' => true,
            ]);
            Order::factory()->create([
                'user_id' => $user->id,
                'package_id' => $package->id,
                'payment_status' => PaymentStatus::SimulatedPaid,
                'status' => OrderStatus::Paid,
            ]);

            Livewire::actingAs($user)
                ->test('portal')
                ->call('goToStep', 2)
                ->set('intakeMethod', 'download')
                ->assertSee('Compliance & Ethics Questionnaire')
                ->assertSee('HIPAA Business Associate Questionnaire')
                ->assertSee('HIPAA Privacy Questionnaire')
                ->assertSee('HIPAA Security Questionnaire');
        }
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

    // ── Step 3: Intake Upload ───────────────────────────────────────────────

    public function test_submitting_intake_creates_submission_and_upload(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]]),
        ]);

        $user = User::factory()->create();
        $practice = Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        $file = UploadedFile::fake()->create('intake.pdf', 100, 'application/pdf');

        Livewire::actingAs($user)
            ->test('portal')
            ->set('questionnaireFiles.compliance_ethics_questionnaire', $file)
            ->call('submitIntake')
            ->assertSet('step', 4);

        $this->assertDatabaseHas('intake_submissions', [
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Submitted->value,
        ]);

        $this->assertDatabaseHas('intake_uploads', [
            'original_filename' => 'intake.pdf',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'event_type' => 'submission.submitted',
        ]);
    }

    public function test_submitting_intake_notifies_every_admin_by_email(): void
    {
        Mail::fake();
        Http::fake([
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]]),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        $file = UploadedFile::fake()->create('intake.pdf', 100, 'application/pdf');

        Livewire::actingAs($user)
            ->test('portal')
            ->set('questionnaireFiles.compliance_ethics_questionnaire', $file)
            ->call('submitIntake');

        Mail::assertSent(AdminIntakeSubmittedMail::class, fn ($mail) => $mail->hasTo($admin->email));
    }

    public function test_revisiting_step3_after_submission_shows_existing_upload_and_does_not_duplicate_it(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]]),
        ]);

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        $file = UploadedFile::fake()->create('intake.pdf', 100, 'application/pdf');

        Livewire::actingAs($user)
            ->test('portal')
            ->set('questionnaireFiles.compliance_ethics_questionnaire', $file)
            ->call('submitIntake')
            ->assertSet('step', 4);

        $this->assertDatabaseCount('intake_uploads', 1);

        // Simulate the user navigating back to Step 3 on a fresh page load.
        $component = Livewire::actingAs($user)->test('portal')->call('goToStep', 3);
        $component->assertSee('Already uploaded: intake.pdf');

        // Resubmitting without choosing a new file must not create a second row.
        $component->call('submitIntake')->assertHasNoErrors();
        $this->assertDatabaseCount('intake_uploads', 1);

        // Resubmitting with a replacement file updates the existing row instead of adding a new one.
        $replacement = UploadedFile::fake()->create('intake-v2.pdf', 100, 'application/pdf');
        $component->set('questionnaireFiles.compliance_ethics_questionnaire', $replacement)
            ->call('submitIntake')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('intake_uploads', 1);
        $this->assertDatabaseHas('intake_uploads', ['original_filename' => 'intake-v2.pdf']);
    }

    public function test_removing_a_just_picked_file_lets_the_client_choose_a_different_one(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        $wrongFile = UploadedFile::fake()->create('wrong.pdf', 100, 'application/pdf');

        $component = Livewire::actingAs($user)
            ->test('portal')
            ->call('goToStep', 3)
            ->set('questionnaireFiles.compliance_ethics_questionnaire', $wrongFile);

        $component->assertSee('wrong.pdf');

        $component->call('removeQuestionnaireFile', 'compliance_ethics_questionnaire');

        $component->assertDontSee('wrong.pdf');
        $this->assertNull($component->get('questionnaireFiles')['compliance_ethics_questionnaire'] ?? null);

        // The slot is empty again, so submitting without picking a replacement is rejected.
        $component->call('submitIntake')
            ->assertHasErrors(['questionnaireFiles.compliance_ethics_questionnaire']);

        $rightFile = UploadedFile::fake()->create('right.pdf', 100, 'application/pdf');
        $component->set('questionnaireFiles.compliance_ethics_questionnaire', $rightFile)
            ->call('submitIntake')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('intake_uploads', ['original_filename' => 'right.pdf']);
        $this->assertDatabaseMissing('intake_uploads', ['original_filename' => 'wrong.pdf']);
    }

    public function test_step3_shows_an_upload_box_for_every_questionnaire_shown_in_step2(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->call('goToStep', 3)
            ->assertSee('Compliance & Ethics Questionnaire')
            ->assertSee('HIPAA Business Associate Questionnaire')
            ->assertSee('HIPAA Privacy Questionnaire')
            ->assertSee('HIPAA Security Questionnaire');
    }

    public function test_submitting_intake_stores_an_optional_questionnaire_upload(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]]),
        ]);

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        $requiredFile = UploadedFile::fake()->create('compliance.pdf', 100, 'application/pdf');
        $optionalFile = UploadedFile::fake()->create('security.pdf', 100, 'application/pdf');

        Livewire::actingAs($user)
            ->test('portal')
            ->set('questionnaireFiles.compliance_ethics_questionnaire', $requiredFile)
            ->set('questionnaireFiles.hipaa_security_questionnaire', $optionalFile)
            ->call('submitIntake')
            ->assertSet('step', 4);

        $this->assertDatabaseHas('intake_uploads', [
            'original_filename' => 'compliance.pdf',
            'upload_type' => 'compliance_ethics_questionnaire',
        ]);
        $this->assertDatabaseHas('intake_uploads', [
            'original_filename' => 'security.pdf',
            'upload_type' => 'hipaa_security_questionnaire',
        ]);
    }

    public function test_submit_intake_requires_a_file(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->call('submitIntake')
            ->assertHasErrors(['questionnaireFiles.compliance_ethics_questionnaire']);
    }

    public function test_hiding_the_required_questionnaire_removes_it_from_step_2_and_promotes_another(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        Questionnaires::setVisibility(IntakeUploadType::ComplianceEthicsQuestionnaire, false);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 2)
            ->set('intakeMethod', 'download')
            ->assertDontSee('Compliance & Ethics Questionnaire')
            ->assertSee('HIPAA Business Associate Questionnaire');
    }

    public function test_submitting_intake_without_the_promoted_questionnaire_blocks_submission_the_way_the_original_required_one_did(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        Questionnaires::setVisibility(IntakeUploadType::ComplianceEthicsQuestionnaire, false);

        Livewire::actingAs($user)
            ->test('portal')
            ->call('submitIntake')
            ->assertHasErrors(['questionnaireFiles.hipaa_business_associate_questionnaire'])
            ->assertHasNoErrors(['questionnaireFiles.compliance_ethics_questionnaire']);
    }

    public function test_every_downloaded_questionnaire_becomes_mandatory_to_upload_and_stale_errors_clear_after_fixing(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]]),
        ]);

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        $complianceFile = UploadedFile::fake()->create('compliance.pdf', 100, 'application/pdf');

        $component = Livewire::actingAs($user)
            ->test('portal')
            ->set('downloadedQuestionnaireKeys', ['compliance_ethics_questionnaire', 'hipaa_business_associate_questionnaire'])
            ->set('questionnaireFiles.compliance_ethics_questionnaire', $complianceFile)
            ->call('submitIntake');

        // The optional HIPAA Business Associate questionnaire was downloaded, so it's now
        // mandatory too — even though only Compliance & Ethics is required by default.
        $component->assertHasErrors(['questionnaireFiles.hipaa_business_associate_questionnaire'])
            ->assertSet('step', 3);

        // Uploading the missing file and resubmitting must clear the stale error, not just
        // leave it stuck on screen from the previous failed attempt.
        $hipaaFile = UploadedFile::fake()->create('hipaa-ba.pdf', 100, 'application/pdf');

        $component->set('questionnaireFiles.hipaa_business_associate_questionnaire', $hipaaFile)
            ->call('submitIntake')
            ->assertHasNoErrors()
            ->assertSet('step', 4);

        $this->assertDatabaseHas('intake_uploads', ['original_filename' => 'compliance.pdf']);
        $this->assertDatabaseHas('intake_uploads', ['original_filename' => 'hipaa-ba.pdf']);
    }

    public function test_submitting_intake_once_creates_a_submission_for_every_order_in_the_batch(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '{"practice_name":"Test Practice"}']]],
            ]),
        ]);

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

        $file = UploadedFile::fake()->create('intake.pdf', 100, 'application/pdf');

        Livewire::actingAs($user)
            ->test('portal')
            ->set('questionnaireFiles.compliance_ethics_questionnaire', $file)
            ->call('submitIntake')
            ->assertSet('step', 4);

        $this->assertDatabaseHas('intake_submissions', ['order_id' => $orderA->id, 'status' => IntakeSubmissionStatus::Submitted->value]);
        $this->assertDatabaseHas('intake_submissions', ['order_id' => $orderB->id, 'status' => IntakeSubmissionStatus::Submitted->value]);

        $uploads = IntakeUpload::all();
        $this->assertCount(2, $uploads);

        // Every upload in the batch — not just the primary one — should end up completed
        // with the same extracted data...
        $this->assertTrue($uploads->every(fn ($u) => $u->ai_extraction_status === AiExtractionStatus::Completed));
        $this->assertSame(1, $uploads->pluck('ai_extracted_data')->map(fn ($d) => json_encode($d))->unique()->count());

        // ...but the shared document was only extracted (and verified) once, not once per
        // order in the batch — two calls total: the extraction, then the verification pass
        // that Compliance & Ethics questionnaires get since they have a structured schema.
        Http::assertSentCount(2);
    }

    // ── Step 2: Upload for review (alternate to questionnaire downloads) ────

    public function test_step2_shows_intake_method_radio_buttons_after_billable_providers(): void
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
            ->assertSee('Do you want to upload your documents')
            ->assertSee('Download our questionnaires')
            ->assertSee('Upload your existing documents');
    }

    public function test_selecting_upload_for_review_hides_questionnaire_downloads_and_shows_the_simple_uploader(): void
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

        $component = Livewire::actingAs($user)
            ->test('portal')
            ->call('goToStep', 2)
            ->assertDontSee('Download Form')
            ->assertDontSee('Upload document(s) for review');

        $component->set('intakeMethod', 'download')
            ->assertSee('Download Form')
            ->assertDontSee('Upload document(s) for review');

        $component->set('intakeMethod', 'upload_for_review')
            ->assertDontSee('Download Form')
            ->assertSee('Upload document(s) for review');
    }

    public function test_save_profile_requires_choosing_an_intake_method(): void
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
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('logoFile', UploadedFile::fake()->image('logo.png'))
            ->call('saveProfile')
            ->assertHasErrors(['intakeMethod']);
    }

    /**
     * The intake-method radios call setIntakeMethod() via wire:click rather than binding
     * with wire:model, specifically so the "download" option's wire:confirm can gate it —
     * wire:confirm only intercepts action calls, not property-binding updates.
     */
    public function test_choosing_an_intake_method_sets_it_via_the_dedicated_method(): void
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
            ->call('setIntakeMethod', 'download')
            ->assertSet('intakeMethod', 'download')
            ->call('setIntakeMethod', 'upload_for_review')
            ->assertSet('intakeMethod', 'upload_for_review');
    }

    public function test_submit_for_review_requires_at_least_one_file(): void
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
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('logoFile', UploadedFile::fake()->image('logo.png'))
            ->set('billableProviders', 3)
            ->set('intakeMethod', 'upload_for_review')
            ->call('submitForReview')
            ->assertHasErrors(['reviewDocumentFiles']);
    }

    public function test_submit_for_review_creates_submission_and_upload_and_advances_directly_to_step_4(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"html":"<p>Polished.</p>"}']]]]),
        ]);

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'is_profile_locked' => false]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);

        $file = UploadedFile::fake()->create('handbook.pdf', 100, 'application/pdf');

        Livewire::actingAs($user)
            ->test('portal')
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('logoFile', UploadedFile::fake()->image('logo.png'))
            ->set('billableProviders', 3)
            ->set('intakeMethod', 'upload_for_review')
            ->set('reviewDocumentFiles', [$file])
            ->call('submitForReview')
            ->assertSet('step', 4);

        $this->assertDatabaseHas('intake_submissions', [
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Submitted->value,
            'intake_method' => IntakeMethod::UploadForReview->value,
        ]);

        $this->assertDatabaseHas('intake_uploads', [
            'original_filename' => 'handbook.pdf',
            'upload_type' => IntakeUploadType::ClientDocumentForReview->value,
        ]);

        $this->assertDatabaseHas('practices', [
            'user_id' => $user->id,
            'is_profile_locked' => true,
        ]);

        $upload = IntakeUpload::first();
        $this->assertSame('<p>Polished.</p>', $upload->ai_extracted_data['html'] ?? null);

        $this->assertDatabaseHas('generated_documents', [
            'order_id' => $order->id,
            'document_type' => DocumentType::PolishedClientDocument->value,
            'intake_upload_id' => $upload->id,
        ]);
    }

    public function test_submit_for_review_with_multiple_files_creates_one_upload_row_per_file(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"html":"<p>Polished.</p>"}']]]]),
        ]);

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
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('logoFile', UploadedFile::fake()->image('logo.png'))
            ->set('billableProviders', 3)
            ->set('intakeMethod', 'upload_for_review')
            ->set('reviewDocumentFiles', [
                UploadedFile::fake()->create('handbook.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('safety-plan.pdf', 100, 'application/pdf'),
            ])
            ->call('submitForReview')
            ->assertSet('step', 4);

        $this->assertDatabaseCount('intake_uploads', 2);
        $this->assertDatabaseCount('generated_documents', 2);
        $this->assertDatabaseHas('intake_uploads', ['original_filename' => 'handbook.pdf']);
        $this->assertDatabaseHas('intake_uploads', ['original_filename' => 'safety-plan.pdf']);
    }

    public function test_submit_for_review_creates_a_submission_for_every_order_in_the_batch(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"html":"<p>Polished.</p>"}']]]]),
        ]);

        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'is_profile_locked' => false]);
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

        Livewire::actingAs($user)
            ->test('portal')
            ->set('practiceName', 'Sunrise Family Medicine')
            ->set('logoFile', UploadedFile::fake()->image('logo.png'))
            ->set('billableProviders', 3)
            ->set('intakeMethod', 'upload_for_review')
            ->set('reviewDocumentFiles', [UploadedFile::fake()->create('handbook.pdf', 100, 'application/pdf')])
            ->call('submitForReview')
            ->assertSet('step', 4);

        $this->assertDatabaseHas('intake_submissions', ['order_id' => $orderA->id, 'intake_method' => IntakeMethod::UploadForReview->value]);
        $this->assertDatabaseHas('intake_submissions', ['order_id' => $orderB->id, 'intake_method' => IntakeMethod::UploadForReview->value]);
        $this->assertDatabaseCount('intake_uploads', 2);
    }

    public function test_rejected_upload_for_review_submission_routes_back_to_step_2_on_reload(): void
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
        IntakeSubmission::factory()->uploadForReview()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Rejected,
            'reviewer_notes' => 'Please upload a clearer scan.',
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 2)
            ->assertSet('intakeMethod', 'upload_for_review')
            ->assertSee('Please upload a clearer scan.');
    }

    public function test_rejected_download_method_submission_still_routes_to_step_3_on_reload(): void
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
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 3);
    }

    public function test_navigating_back_to_step2_via_the_stepper_restores_the_chosen_intake_method(): void
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
        IntakeSubmission::factory()->uploadForReview()->submitted()->create(['order_id' => $order->id]);

        Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 4)
            ->assertSet('intakeMethod', '')
            ->call('goToStep', 2)
            ->assertSet('step', 2)
            ->assertSet('intakeMethod', 'upload_for_review')
            ->assertSee('Upload your existing documents');
    }

    public function test_reupload_button_routes_to_the_step_matching_how_the_order_was_submitted(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $reviewOrder = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        IntakeSubmission::factory()->uploadForReview()->create([
            'order_id' => $reviewOrder->id,
            'status' => IntakeSubmissionStatus::Rejected,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 4)
            ->call('reuploadForOrder', $reviewOrder->id)
            ->assertSet('step', 2)
            ->assertSet('intakeMethod', 'upload_for_review');
    }

    public function test_step3_is_unreachable_for_an_upload_for_review_submission(): void
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
        IntakeSubmission::factory()->uploadForReview()->submitted()->create(['order_id' => $order->id]);

        $component = Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 4);

        $this->assertFalse($component->instance()->canReach(3));

        // Neither the stepper icon nor any other action can land on Step 3.
        $component->call('goToStep', 3)->assertSet('step', 4);
    }

    public function test_resubmitting_for_review_after_rejection_replaces_the_prior_upload_and_document(): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"html":"<p>Polished.</p>"}']]]]),
        ]);

        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::SimulatedPaid,
            'status' => OrderStatus::Paid,
        ]);
        $submission = IntakeSubmission::factory()->uploadForReview()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Rejected,
        ]);
        $oldUpload = IntakeUpload::factory()->completed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'original_filename' => 'old-handbook.pdf',
        ]);
        GeneratedDocument::factory()->completed()->create([
            'order_id' => $order->id,
            'document_type' => DocumentType::PolishedClientDocument,
            'intake_upload_id' => $oldUpload->id,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('intakeMethod', 'upload_for_review')
            ->set('reviewDocumentFiles', [UploadedFile::fake()->create('new-handbook.pdf', 100, 'application/pdf')])
            ->call('submitForReview')
            ->assertSet('step', 4);

        $this->assertDatabaseMissing('intake_uploads', ['id' => $oldUpload->id]);
        $this->assertDatabaseHas('intake_uploads', ['original_filename' => 'new-handbook.pdf']);
        $this->assertDatabaseCount('intake_uploads', 1);
        $this->assertDatabaseCount('generated_documents', 1);
    }

    public function test_step2_shows_previously_uploaded_review_documents_after_returning_from_rejection(): void
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
        $submission = IntakeSubmission::factory()->uploadForReview()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Rejected,
        ]);
        IntakeUpload::factory()->completed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'original_filename' => 'employee-handbook.pdf',
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->assertSet('step', 2)
            ->assertSet('intakeMethod', 'upload_for_review')
            ->assertSee('employee-handbook.pdf');
    }

    public function test_resubmitting_for_review_without_new_files_keeps_the_existing_upload(): void
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
        $submission = IntakeSubmission::factory()->uploadForReview()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Rejected,
        ]);
        $existingUpload = IntakeUpload::factory()->completed()->create([
            'intake_submission_id' => $submission->id,
            'upload_type' => IntakeUploadType::ClientDocumentForReview,
            'original_filename' => 'employee-handbook.pdf',
        ]);
        $existingDoc = GeneratedDocument::factory()->completed()->create([
            'order_id' => $order->id,
            'document_type' => DocumentType::PolishedClientDocument,
            'intake_upload_id' => $existingUpload->id,
        ]);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('intakeMethod', 'upload_for_review')
            ->call('submitForReview')
            ->assertHasNoErrors()
            ->assertSet('step', 4);

        $this->assertDatabaseHas('intake_uploads', ['id' => $existingUpload->id]);
        $this->assertDatabaseHas('generated_documents', ['id' => $existingDoc->id]);
        $this->assertDatabaseCount('intake_uploads', 1);
        $this->assertSame(IntakeSubmissionStatus::Submitted, $submission->fresh()->status);
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
            ->assertSee('Under review');

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
            ->assertSee('Ready')
            ->assertSee('Download PDF')
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
            ->assertSee('Update Practice Info')
            ->assertSee('Compliance & Ethics Manual')
            ->assertSee('Generating');
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

    public function test_dashboard_can_switch_between_tabs(): void
    {
        $user = User::factory()->create();
        Practice::factory()->locked()->create(['user_id' => $user->id]);
        $this->makeApprovedOrder($user);

        Livewire::actingAs($user)
            ->test('portal')
            ->set('step', 5)
            ->set('dashboardTab', 'payments')
            ->assertSee('Purchase History')
            ->set('dashboardTab', 'history')
            ->assertSee('Account Activity');
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
