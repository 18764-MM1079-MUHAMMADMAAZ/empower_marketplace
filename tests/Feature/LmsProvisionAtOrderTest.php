<?php

namespace Tests\Feature;

use App\Jobs\ProvisionLmsAccount;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Gap 1 in lms-enrollment-plan.md: the LMS account is created as soon as the order is placed:
 *  card payment, free trial, or CCH/talkEHR order form (Casey, 2026-11-23). */
class LmsProvisionAtOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Bus::fake([ProvisionLmsAccount::class]);
        Http::fake([
            config('services.clover_mtbc.base_url') => Http::response([
                'status' => true,
                'message' => 'Payment Successful',
                'data' => ['id' => 'TEST_TXN_ID', 'amount' => 1, 'paid' => true, 'status' => 'succeeded'],
            ]),
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/tokenize' => Http::response([
                'status' => true,
                'data' => ['token' => '4111114281501111', 'firstSix' => '411111', 'lastFour' => '1111', 'referenceNumber' => 'REF123'],
            ]),
        ]);
    }

    private function checkout(string $slug): Testable
    {
        $package = Package::factory()->create(['slug' => $slug, 'annual_price' => 1299, 'is_active' => true]);

        return Livewire::test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billableProviders', 2)
            ->set('accountName', 'Jane Provider')
            ->set('accountEmail', 'jane@practice.com')
            ->set('billingAddress1', '7 Clyde Road')
            ->set('billingCity', 'Somerset')
            ->set('billingState', 'NJ')
            ->set('billingZip', '08873');
    }

    public function test_a_card_payment_for_an_lms_package_provisions_straight_away_with_the_billing_state(): void
    {
        $this->checkout('professional')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        Bus::assertDispatchedTimes(ProvisionLmsAccount::class, 1);
        $orderId = Order::where('user_id', User::where('email', 'jane@practice.com')->value('id'))->value('id');
        Bus::assertDispatched(ProvisionLmsAccount::class, fn (ProvisionLmsAccount $job) => $job->user->email === 'jane@practice.com'
            && $job->addressOrState === 'NJ' && $job->orderIds === [$orderId]);
    }

    public function test_an_essential_card_payment_does_not_provision(): void
    {
        $this->checkout('essential')
            ->call('pay', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        Bus::assertNotDispatched(ProvisionLmsAccount::class);
    }

    public function test_a_free_trial_of_an_lms_package_provisions_straight_away(): void
    {
        DiscountCode::factory()->freeTrial(30)->create(['code' => 'TRIAL30']);

        $this->checkout('advanced')
            ->set('discountCodeInput', 'TRIAL30')
            ->call('applyDiscountCode')
            ->call('payFreeTrial', 'Jane Provider', '4242 4242 4242 4242', '12/27', '123', true)
            ->assertHasNoErrors();

        Bus::assertDispatched(ProvisionLmsAccount::class, fn (ProvisionLmsAccount $job) => $job->user->email === 'jane@practice.com');
    }

    public function test_a_talkehr_order_form_counts_as_confirmed_and_provisions_with_the_practice_address(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'source_system' => 'talkehr', 'address' => '12 Main St, Austin, TX 78701']);
        $package = Package::factory()->create(['slug' => 'advanced', 'annual_price' => 1299, 'is_active' => true]);

        Livewire::actingAs($user)->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billableProviders', 3)
            ->call('continuePartnerCheckout')
            ->assertHasNoErrors();

        Bus::assertDispatched(ProvisionLmsAccount::class, fn (ProvisionLmsAccount $job) => $job->user->is($user) && $job->addressOrState === '12 Main St, Austin, TX 78701');
    }

    public function test_a_partner_essential_order_does_not_provision(): void
    {
        $user = User::factory()->create();
        Practice::factory()->create(['user_id' => $user->id, 'source_system' => 'cch']);
        $package = Package::factory()->create(['slug' => 'essential', 'annual_price' => 999, 'is_active' => true]);

        Livewire::actingAs($user)->test('portal')
            ->set('selectedPackageId', $package->id)
            ->set('billableProviders', 1)
            ->call('continuePartnerCheckout')
            ->assertHasNoErrors();

        Bus::assertNotDispatched(ProvisionLmsAccount::class);
    }
}
