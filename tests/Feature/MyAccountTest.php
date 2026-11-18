<?php

namespace Tests\Feature;

use App\Enums\CardBrand;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\OrderStatus;
use App\Enums\PackageTier;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateComplianceDocument;
use App\Models\GeneratedDocument;
use App\Models\IntakeSubmission;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentLog;
use App\Models\Practice;
use App\Models\ReviewerQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class MyAccountTest extends TestCase
{
    use RefreshDatabase;

    /** A client with a paid annual subscription, a locked practice and an approved intake. */
    private function client(array $orderAttributes = []): array
    {
        $user = User::factory()->create(['name' => 'Jane Provider']);
        $practice = Practice::factory()->locked()->create([
            'user_id' => $user->id,
            'name' => 'Riverside Family Medicine',
            'billable_providers_count' => 3,
            'address' => '7 Clyde Road, Somerset, NJ 08873',
        ]);
        $package = Package::firstWhere('slug', PackageTier::Professional->value)
            ?? Package::factory()->create(['slug' => PackageTier::Professional->value, 'name' => 'Professional Compliance', 'annual_price' => 1299, 'monthly_price' => 129]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_status' => PaymentStatus::Paid,
            'billing_cycle' => 'annual',
            'next_bill_date' => now()->addYear()->startOfDay(),
            'card_last_four' => '4242',
            'card_expiry_month' => 12,
            'card_expiry_year' => 2028,
            'billing_address' => ['name' => 'Jane Provider', 'address1' => '7 Clyde Road', 'city' => 'Somerset', 'state' => 'NJ', 'zip' => '08873'],
            ...$orderAttributes,
        ]);
        IntakeSubmission::factory()->approved()->create(['order_id' => $order->id]);

        return [$user, $order, $practice];
    }

    private function staleDocument(Order $order, array $attributes = []): GeneratedDocument
    {
        return GeneratedDocument::factory()->completed()->approved()->stale()->create([
            'order_id' => $order->id,
            'document_type' => DocumentType::HipaaPrivacyPolicy,
            ...$attributes,
        ]);
    }

    // --- Page and menu ---

    public function test_guests_are_sent_home(): void
    {
        $this->get(route('account'))->assertRedirect(route('home'));
    }

    public function test_the_account_menu_lists_my_account_first(): void
    {
        [$user] = $this->client();

        $this->actingAs($user)->withoutVite()->get(route('account'))
            ->assertSeeInOrder(['data-account-link>My Account</a>', 'Back to Packages</a>', 'Log out'], false)
            ->assertDontSee('>Change Password<', false);
    }

    public function test_the_admin_account_menu_has_no_back_to_packages_link(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->withoutVite()->get(route('account'))
            ->assertSeeInOrder(['data-account-link>My Account</a>', 'Admin Panel</a>', 'Log out'], false)
            ->assertDontSee('Back to Packages</a>', false)
            ->assertSee('data-back-to-dashboard', false)
            ->assertSee(route('admin.dashboard'), false);
    }

    public function test_clients_do_not_get_the_back_to_dashboard_link(): void
    {
        [$user] = $this->client();

        $this->actingAs($user)->withoutVite()->get(route('account'))
            ->assertDontSee('data-back-to-dashboard', false);
    }

    public function test_the_old_change_password_url_redirects_to_the_security_section(): void
    {
        [$user] = $this->client();

        $this->actingAs($user)->get(route('password.edit'))->assertRedirect(route('account').'#security');
    }

    public function test_a_paying_client_sees_every_section_with_their_data(): void
    {
        [$user, $order] = $this->client();
        PaymentLog::factory()->create(['user_id' => $user->id, 'order_id' => $order->id, 'package_id' => $order->package_id, 'amount' => 3897]);

        $this->actingAs($user)->withoutVite()->get(route('account'))
            ->assertOk()
            ->assertSee('Welcome back, Jane')
            ->assertSee('Riverside Family Medicine')
            ->assertSee('Review status')
            ->assertSee('Plan &amp; billing', false)
            ->assertSee('Documents')
            ->assertSee('Practice info')
            ->assertSee('Sign-in &amp; security', false)
            ->assertSee('Professional Compliance')
            ->assertSee('Card ending 4242')
            ->assertSee('Expires 12/28')
            ->assertSee('7 Clyde Road')
            ->assertSee('$1,299')
            // Renewal = annual price × 3 providers.
            ->assertSee('$3,897 on '.$order->next_bill_date->format('M j, Y'));
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
            ->assertSee('id="practice"', false);
    }

    public function test_admins_only_see_sign_in_and_security(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->withoutVite()->get(route('account'))
            ->assertOk()
            ->assertSee('id="security"', false)
            ->assertDontSee('id="overview"', false)
            ->assertDontSee('id="plan"', false);
    }

    // --- Review status ---

    public function test_review_status_shows_an_approved_intake_and_the_next_annual_review(): void
    {
        [$user] = $this->client();
        $this->actingAs($user);

        Livewire::test('account.review-status')
            ->assertSee('Approved')
            ->assertSee('Your next annual policy review is due')
            ->assertSeeHtml('data-review-step="done"');
    }

    public function test_review_status_points_the_client_to_unanswered_questions(): void
    {
        [$user, $order] = $this->client();
        $order->intakeSubmission->update(['status' => IntakeSubmissionStatus::UnderReview, 'under_review_started_at' => now(), 'reviewed_at' => null]);
        ReviewerQuestion::factory()->create(['intake_submission_id' => $order->intakeSubmission->id]);
        $this->actingAs($user);

        Livewire::test('account.review-status')
            ->assertSee('In review')
            ->assertSee('1 question waiting for your reply')
            ->assertSee('Answer in My Portal');
    }

    // --- Plan & billing ---

    public function test_billing_history_lists_payments_and_the_scheduled_renewal(): void
    {
        [$user, $order] = $this->client();
        $paid = PaymentLog::factory()->create(['user_id' => $user->id, 'order_id' => $order->id, 'package_id' => $order->package_id, 'amount' => 3507.30]);
        PaymentLog::factory()->declined()->create(['user_id' => $user->id, 'package_id' => $order->package_id]);
        $this->actingAs($user);

        Livewire::test('account.plan-billing')
            ->assertSeeHtml('data-billing-row="Scheduled"')
            ->assertSeeHtml('data-billing-row="Paid"')
            ->assertSeeHtml('data-billing-row="Declined"')
            ->assertSee('$3,507.30')
            ->assertSee('$3,897.00')
            ->assertSeeHtml(route('payments.receipt', $paid));
    }

    public function test_a_partner_billed_client_sees_no_card_or_renewal(): void
    {
        [$user] = $this->client(['payment_status' => PaymentStatus::ExternallyBilled, 'card_last_four' => null]);
        $this->actingAs($user);

        Livewire::test('account.plan-billing')
            ->assertSee('billed through your partner')
            ->assertSee('Invoiced by your partner')
            ->assertDontSee('Edit billing address')
            ->assertDontSeeHtml('data-billing-row="Scheduled"');
    }

    public function test_the_billing_address_is_saved_on_every_active_subscription(): void
    {
        [$user, $order] = $this->client();
        $second = Order::factory()->create(['user_id' => $user->id, 'package_id' => $order->package_id, 'payment_status' => PaymentStatus::Paid, 'billing_address' => ['name' => 'Jane Provider']]);
        $this->actingAs($user);

        Livewire::test('account.plan-billing')
            ->call('editAddress')
            ->assertSet('address1', '7 Clyde Road')
            ->set('address1', '45 Maple Ave')
            ->set('city', 'Morristown')
            ->set('zip', '07960')
            ->call('saveAddress')
            ->assertHasNoErrors()
            ->assertSet('editingAddress', false);

        $this->assertSame('45 Maple Ave', $order->fresh()->billing_address['address1']);
        $this->assertSame('Jane Provider', $order->fresh()->billing_address['name']);
        $this->assertSame('Morristown', $second->fresh()->billing_address['city']);
    }

    public function test_the_billing_address_must_be_complete(): void
    {
        [$user] = $this->client();
        $this->actingAs($user);

        Livewire::test('account.plan-billing')
            ->call('editAddress')
            ->set('address1', '')
            ->set('zip', '123')
            ->call('saveAddress')
            ->assertHasErrors(['address1' => 'required', 'zip' => 'regex']);
    }

    // --- Payment receipts ---

    public function test_a_client_can_view_the_receipt_for_their_payment(): void
    {
        [$user, $order] = $this->client();
        $payment = PaymentLog::factory()->create(['user_id' => $user->id, 'order_id' => $order->id, 'package_id' => $order->package_id, 'amount' => 3507.30, 'transaction_id' => 'TXN123']);

        $this->actingAs($user)->withoutVite()->get(route('payments.receipt', $payment))
            ->assertOk()
            ->assertSee('P-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT))
            ->assertSee('$3,507.30')
            ->assertSee('Card ending 4242')
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

    // --- Documents ---

    public function test_documents_show_their_status_version_and_action(): void
    {
        [$user, $order] = $this->client();
        $current = GeneratedDocument::factory()->completed()->approved()->create(['order_id' => $order->id, 'document_type' => DocumentType::ComplianceEthicsManual, 'version' => 2]);
        $this->staleDocument($order);
        $this->actingAs($user);

        Livewire::test('account.documents')
            ->assertSee('Current')
            ->assertSee('v2')
            ->assertSeeHtml(route('documents.download', $current))
            ->assertSee('Needs update')
            ->assertSee('Regenerate 1 document')
            ->assertSee('Your intake answers');
    }

    public function test_a_stale_document_can_be_regenerated(): void
    {
        Queue::fake();
        [$user, $order] = $this->client();
        $document = $this->staleDocument($order);
        $this->actingAs($user);

        Livewire::test('account.documents')
            ->call('regenerate', $document->id)
            ->assertDispatched('account-updated');

        Queue::assertPushed(GenerateComplianceDocument::class, 1);
        $this->assertSame(DocumentStatus::Pending, $document->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'document.regenerate_requested', 'user_id' => $user->id]);
    }

    public function test_a_current_document_cannot_be_regenerated_from_my_account(): void
    {
        Queue::fake();
        [$user, $order] = $this->client();
        $document = GeneratedDocument::factory()->completed()->approved()->create(['order_id' => $order->id]);
        $this->actingAs($user);

        Livewire::test('account.documents')->call('regenerate', $document->id);

        Queue::assertNothingPushed();
    }

    public function test_another_clients_document_cannot_be_regenerated(): void
    {
        Queue::fake();
        [$user] = $this->client();
        [, $otherOrder] = $this->client();
        $document = $this->staleDocument($otherOrder);
        $this->actingAs($user);

        Livewire::test('account.documents')->call('regenerate', $document->id)->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_the_overview_banner_regenerates_every_stale_document(): void
    {
        Queue::fake();
        [$user, $order] = $this->client();
        $this->staleDocument($order);
        $this->staleDocument($order, ['document_type' => DocumentType::HipaaSecurityManual]);
        $this->actingAs($user);

        Livewire::test('account.overview')
            ->assertSeeHtml('data-stale-banner')
            ->assertSee('2 documents still show the old details')
            ->call('regenerateAll');

        Queue::assertPushed(GenerateComplianceDocument::class, 2);
    }

    public function test_cancelled_subscriptions_cannot_regenerate_documents(): void
    {
        Queue::fake();
        [$user, $order] = $this->client(['status' => OrderStatus::Cancelled]);
        $document = $this->staleDocument($order);
        $this->actingAs($user);

        Livewire::test('account.documents')->call('regenerate', $document->id);

        Queue::assertNothingPushed();
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

    // --- Practice info ---

    public function test_saving_practice_info_flags_documents_for_regeneration(): void
    {
        [$user, $order, $practice] = $this->client();
        $document = GeneratedDocument::factory()->completed()->approved()->create(['order_id' => $order->id]);
        $this->actingAs($user);

        Livewire::test('account.practice-info')
            ->call('edit')
            ->assertSet('legalName', 'Riverside Family Medicine')
            ->set('mainPhone', '(732) 555-0148')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('account-updated');

        $this->assertSame('(732) 555-0148', $practice->fresh()->main_phone);
        $this->assertTrue($document->fresh()->is_stale);
    }

    public function test_saving_unchanged_practice_info_leaves_documents_alone(): void
    {
        [$user, $order] = $this->client();
        $document = GeneratedDocument::factory()->completed()->approved()->create(['order_id' => $order->id]);
        $this->actingAs($user);

        Livewire::test('account.practice-info')->call('edit')->call('save')->assertHasNoErrors();

        $this->assertFalse($document->fresh()->is_stale);
    }

    public function test_practice_info_is_validated(): void
    {
        [$user] = $this->client();
        $this->actingAs($user);

        Livewire::test('account.practice-info')
            ->call('edit')
            ->set('legalName', '')
            ->set('mainEmail', 'not-an-email')
            ->set('specialty', 'Astrology')
            ->call('save')
            ->assertHasErrors(['legalName', 'mainEmail', 'specialty']);
    }

    // --- Payment method (Phase 2) ---

    private function fakeTokenize(string $firstSix = '411111', string $lastFour = '1111'): void
    {
        Http::fake([
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/tokenize' => Http::response([
                'status' => true,
                'data' => ['token' => 'TOKEN-NEW', 'firstSix' => $firstSix, 'lastFour' => $lastFour, 'referenceNumber' => 'REF-NEW'],
            ]),
        ]);
    }

    public function test_card_brands_are_recognised_from_the_leading_digits(): void
    {
        $this->assertSame(CardBrand::Visa, CardBrand::fromNumber('4242424242424242'));
        $this->assertSame(CardBrand::Mastercard, CardBrand::fromNumber('5555 5555 5555 4444'));
        $this->assertSame(CardBrand::Mastercard, CardBrand::fromNumber('222300'));
        $this->assertSame(CardBrand::Amex, CardBrand::fromNumber('378282'));
        $this->assertSame(CardBrand::Discover, CardBrand::fromNumber('6011111111111117'));
        $this->assertSame(CardBrand::Discover, CardBrand::fromNumber('650000'));
        $this->assertNull(CardBrand::fromNumber('123456'));
        $this->assertNull(CardBrand::fromNumber('42'));
    }

    public function test_the_payment_method_shows_brand_expiry_and_name(): void
    {
        [$user] = $this->client(['card_brand' => CardBrand::Visa, 'card_holder_name' => 'Jane Provider']);
        $this->actingAs($user);

        Livewire::test('account.plan-billing')
            ->assertSee('Visa ending 4242')
            ->assertSee('Expires 12/28 · Jane Provider')
            ->assertSeeHtml('>VISA</span>')
            ->assertSee('Update payment method');
    }

    public function test_a_card_saved_before_brands_were_stored_still_shows(): void
    {
        [$user] = $this->client();
        $this->actingAs($user);

        Livewire::test('account.plan-billing')->assertSee('Card ending 4242')->assertSeeHtml('>CARD</span>');
    }

    public function test_an_expired_card_is_marked(): void
    {
        [$user] = $this->client(['card_expiry_month' => 1, 'card_expiry_year' => 2020]);
        $this->actingAs($user);

        Livewire::test('account.plan-billing')->assertSee('Expired 01/20');
    }

    public function test_a_new_card_is_saved_on_every_active_subscription(): void
    {
        $this->fakeTokenize('555555', '4444');
        [$user, $order] = $this->client();
        $second = Order::factory()->create(['user_id' => $user->id, 'package_id' => $order->package_id, 'payment_status' => PaymentStatus::Trialing, 'card_last_four' => '0000']);
        $cancelled = Order::factory()->create(['user_id' => $user->id, 'package_id' => $order->package_id, 'status' => OrderStatus::Cancelled, 'card_last_four' => '0000']);
        $this->actingAs($user);

        Livewire::test('account.plan-billing')
            ->call('updateCard', 'Jane Q Provider', '5555 5555 5555 4444', '09/30', '123')
            ->assertReturned(true)
            ->assertHasNoErrors()
            ->assertDispatched('toast')
            ->assertSee('Mastercard ending 4444');

        foreach ([$order, $second] as $saved) {
            $saved->refresh();
            $this->assertSame('TOKEN-NEW', $saved->clover_card_token);
            $this->assertSame('4444', $saved->card_last_four);
            $this->assertSame(CardBrand::Mastercard, $saved->card_brand);
            $this->assertSame('Jane Q Provider', $saved->card_holder_name);
            $this->assertSame(9, $saved->card_expiry_month);
            $this->assertSame(2030, $saved->card_expiry_year);
        }
        $this->assertSame('0000', $cancelled->fresh()->card_last_four);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'order.card_updated', 'order_id' => $order->id]);
    }

    public function test_an_invalid_card_is_rejected_before_anything_is_sent(): void
    {
        Http::fake();
        [$user, $order] = $this->client();
        $this->actingAs($user);

        Livewire::test('account.plan-billing')
            ->call('updateCard', 'J4ne', '1234', '13/30', '1')
            ->assertReturned(false)
            ->assertHasErrors(['cardName', 'cardNumber', 'cardExpiry', 'cardCvc']);

        Http::assertNothingSent();
        $this->assertSame('4242', $order->fresh()->card_last_four);
    }

    public function test_a_card_the_payment_service_refuses_leaves_the_old_card_in_place(): void
    {
        Http::fake([
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/tokenize' => Http::response(['status' => false, 'message' => 'Invalid card']),
        ]);
        [$user, $order] = $this->client();
        $this->actingAs($user);

        Livewire::test('account.plan-billing')
            ->call('updateCard', 'Jane Provider', '4242 4242 4242 4242', '09/30', '123')
            ->assertReturned(false)
            ->assertHasErrors(['card']);

        $this->assertSame('4242', $order->fresh()->card_last_four);
    }

    public function test_partner_billed_clients_cannot_add_a_card(): void
    {
        Http::fake();
        [$user] = $this->client(['payment_status' => PaymentStatus::ExternallyBilled]);
        $this->actingAs($user);

        Livewire::test('account.plan-billing')
            ->assertDontSee('Update payment method')
            ->call('updateCard', 'Jane Provider', '4242 4242 4242 4242', '09/30', '123')
            ->assertHasErrors(['card']);

        Http::assertNothingSent();
    }
}
