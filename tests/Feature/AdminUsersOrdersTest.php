<?php

namespace Tests\Feature;

use App\Enums\IntakeSubmissionStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\GeneratedDocument;
use App\Models\IntakeSubmission;
use App\Models\IntakeUpload;
use App\Models\Order;
use App\Models\OshaLocation;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use App\Services\MtbcCardCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUsersOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // ── Users ─────────────────────────────────────────────────────────────

    public function test_admin_users_and_orders_pages_render(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        Practice::factory()->create(['user_id' => $client->id]);
        $package = Package::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);

        $this->withoutVite()->actingAs($admin);

        $this->get(route('admin.users'))->assertOk();
        $this->get(route('admin.users.create'))->assertOk();
        $this->get(route('admin.users.edit', $client))->assertOk();
        $this->get(route('admin.orders'))->assertOk();
        $this->get(route('admin.orders.edit', $order))->assertOk();
    }

    public function test_admin_can_view_and_search_the_users_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['name' => 'Findable Client']);
        User::factory()->create(['name' => 'Someone Else']);

        Livewire::actingAs($admin)
            ->test('admin.user-list')
            ->set('search', 'Findable')
            ->assertSee('Findable Client')
            ->assertDontSee('Someone Else');
    }

    public function test_admin_can_create_a_user(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.user-form')
            ->set('name', 'New Admin')
            ->set('email', 'new-admin@example.com')
            ->set('role', UserRole::Admin->value)
            ->set('password', 'password123')
            ->call('save')
            ->assertRedirect(route('admin.users'));

        $this->assertDatabaseHas('users', ['email' => 'new-admin@example.com', 'role' => UserRole::Admin->value]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'user.created']);
    }

    public function test_admin_can_update_a_users_profile_and_password(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['name' => 'Old Name', 'password' => 'old-password']);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $user])
            ->set('name', 'New Name')
            ->set('password', 'brand-new-password')
            ->call('save')
            ->assertRedirect(route('admin.users'));

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertTrue(Hash::check('brand-new-password', $user->password));
    }

    public function test_admin_can_deactivate_a_user(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['is_active' => true]);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $user])
            ->set('isActive', false)
            ->call('save')
            ->assertRedirect(route('admin.users'));

        $this->assertFalse($user->refresh()->is_active);
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $admin])
            ->set('role', UserRole::Client->value)
            ->call('save')
            ->assertHasErrors('role');

        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $admin])
            ->set('isActive', false)
            ->call('save');

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $admin])
            ->call('delete')
            ->assertHasErrors('delete');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_can_delete_a_user_and_all_their_files_are_removed(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();
        Storage::disk('local')->put('practice-logos/logo.png', 'fake-logo');
        $practice = Practice::factory()->create(['user_id' => $user->id, 'logo_path' => 'practice-logos/logo.png']);
        $package = Package::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create(['order_id' => $order->id]);
        Storage::disk('local')->put('intake/upload.pdf', 'fake-upload');
        $upload = IntakeUpload::factory()->create(['intake_submission_id' => $submission->id, 'storage_path' => 'intake/upload.pdf']);
        Storage::disk('local')->put('compliance/doc.pdf', 'fake-doc');
        $document = GeneratedDocument::factory()->create(['order_id' => $order->id, 'pdf_storage_path' => 'compliance/doc.pdf']);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $user])
            ->call('delete')
            ->assertRedirect(route('admin.users'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('practices', ['id' => $practice->id]);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('intake_uploads', ['id' => $upload->id]);
        $this->assertDatabaseMissing('generated_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'user.deleted']);

        Storage::disk('local')->assertMissing('practice-logos/logo.png');
        Storage::disk('local')->assertMissing('intake/upload.pdf');
        Storage::disk('local')->assertMissing('compliance/doc.pdf');
    }

    public function test_admin_can_delete_a_user_from_the_users_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.user-list')
            ->call('delete', $user->id);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'user.deleted']);
    }

    public function test_admin_cannot_delete_their_own_account_from_the_users_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test('admin.user-list')
            ->call('delete', $admin->id)
            ->assertHasErrors('delete');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_users_list_does_not_show_a_delete_link_for_the_current_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Current Admin']);
        $otherUser = User::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.user-list')
            // The admin's own row has no delete button, so its click handler never renders...
            ->assertDontSeeHtml('confirmId = '.$admin->id.';')
            // ...while another user's row does.
            ->assertSeeHtml('confirmId = '.$otherUser->id.';');
    }

    public function test_users_list_shows_end_trial_only_for_trialing_users(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $trialingUser = User::factory()->create();
        $order = Order::factory()->trialing()->create(['user_id' => $trialingUser->id]);
        $otherUser = User::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.user-list')
            ->assertSeeHtml('confirmEndTrialOrderId = '.$order->id.';')
            ->assertSee('Trialing');
    }

    public function test_admin_ending_a_trial_charges_the_card_and_converts_it_to_paid(): void
    {
        Mail::fake();
        $cipher = new MtbcCardCipher;

        Http::fake([
            '*/api/auth/token' => Http::response(['status' => true, 'data' => ['accessToken' => 'fake-jwt-token']]),
            '*/api/payment/detokenize' => Http::response([
                'status' => true,
                'data' => ['value' => $cipher->encrypt('4111111111111111'), 'cvv' => $cipher->encrypt('123'), 'referenceNumber' => 'REF123'],
            ]),
            '*/api/payment/Create_Charge' => Http::response(['status' => true, 'message' => 'Payment Successful', 'data' => ['id' => 'TEST_END_TRIAL_TXN']]),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $package = Package::factory()->create(['annual_price' => 999]);
        $order = Order::factory()->trialing()->create(['user_id' => $client->id, 'package_id' => $package->id, 'original_price' => 999]);

        Livewire::actingAs($admin)
            ->test('admin.user-list')
            ->call('endTrial', $order->id)
            ->assertHasNoErrors()
            ->assertSee('Charge succeeded');

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('TEST_END_TRIAL_TXN', $order->payment_reference);
    }

    public function test_admin_ending_a_trial_shows_the_decline_reason_on_a_failed_charge(): void
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

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $order = Order::factory()->trialing()->create(['user_id' => $client->id]);

        Livewire::actingAs($admin)
            ->test('admin.user-list')
            ->call('endTrial', $order->id)
            ->assertHasErrors(['endTrial']);

        $order->refresh();
        $this->assertSame(PaymentStatus::Trialing, $order->payment_status);
    }

    public function test_user_edit_page_shows_a_terms_accepted_badge_when_an_order_accepted_terms(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $package = Package::factory()->create();
        Order::factory()->create([
            'user_id' => $client->id,
            'package_id' => $package->id,
            'terms_accepted_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->assertSee('Terms & Conditions accepted');
    }

    public function test_user_edit_page_hides_the_terms_accepted_badge_when_no_order_accepted_terms(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->assertDontSee('Terms & Conditions accepted');
    }

    // ── Practice / OSHA locations ────────────────────────────────────────

    public function test_admin_can_edit_a_users_practice_profile(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $client->id, 'name' => 'Old Practice Name']);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->set('practiceName', 'New Practice Name')
            ->set('practiceIsLocked', true)
            ->call('save')
            ->assertRedirect(route('admin.users'));

        $practice->refresh();
        $this->assertSame('New Practice Name', $practice->name);
        $this->assertTrue($practice->is_profile_locked);
        $this->assertNotNull($practice->locked_at);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'practice.updated']);
    }

    public function test_admin_can_clear_optional_practice_profile_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $client->id]);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->set('practiceAddress', '')
            ->set('practiceSpecialty', '')
            ->set('practiceBillableProvidersCount', null)
            ->call('save')
            ->assertHasNoErrors(['practiceAddress', 'practiceSpecialty', 'practiceBillableProvidersCount']);

        $practice->refresh();
        $this->assertNull($practice->address);
        $this->assertNull($practice->specialty);
        $this->assertSame(1, $practice->billable_providers_count);
    }

    public function test_admin_can_edit_the_practices_compliance_team_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $client->id]);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->set('practiceComplianceOfficerName', 'Dr. Jane Rivera')
            ->set('practiceComplianceOfficerPhone', '555-0100')
            ->set('practiceComplianceOfficerEmail', 'jane@example.com')
            ->set('practiceItVendorName', 'Acme IT')
            ->set('practiceUsesEhcpHotline', false)
            ->set('practiceComplianceHotlineNumber', '555-0199')
            ->set('practiceComplianceHotlineEmail', 'hotline@example.com')
            ->set('practiceHotlinePosterCount', 3)
            ->call('addCommitteeMember')
            ->set('practiceCommitteeMembers.0.name', 'T. Lee')
            ->set('practiceCommitteeMembers.0.title', 'Nurse Manager')
            ->call('save')
            ->assertRedirect(route('admin.users'));

        $practice->refresh();
        $this->assertSame('Dr. Jane Rivera', $practice->compliance_officer_name);
        $this->assertSame('555-0100', $practice->compliance_officer_phone);
        $this->assertSame('jane@example.com', $practice->compliance_officer_email);
        $this->assertSame('Acme IT', $practice->it_vendor_name);
        $this->assertSame('555-0199', $practice->compliance_hotline_number);
        $this->assertSame(3, $practice->hotline_poster_count);
        $this->assertSame([['name' => 'T. Lee', 'title' => 'Nurse Manager']], $practice->compliance_committee_members);
    }

    public function test_using_the_shared_hotline_clears_the_practices_own_hotline_details(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $practice = Practice::factory()->create([
            'user_id' => $client->id,
            'compliance_hotline_number' => '555-0199',
            'compliance_hotline_email' => 'hotline@example.com',
        ]);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->set('practiceUsesEhcpHotline', true)
            ->call('save')
            ->assertRedirect(route('admin.users'));

        $practice->refresh();
        $this->assertTrue($practice->uses_ehcp_hotline);
        $this->assertNull($practice->compliance_hotline_number);
        $this->assertNull($practice->compliance_hotline_email);
    }

    public function test_users_page_shows_practice_intake_progress_and_links_to_the_submission(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        Practice::factory()->create(['user_id' => $client->id]);
        $package = Package::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);
        $submission = IntakeSubmission::factory()->create([
            'order_id' => $order->id,
            'status' => IntakeSubmissionStatus::Draft,
            'wizard_screen' => 'basics',
        ]);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->assertSee('Practice Intake')
            ->assertSee('Wizard screen: basics')
            ->assertSee(route('admin.submissions.show', $submission), false);
    }

    public function test_users_page_shows_not_started_for_an_order_with_no_submission_yet(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        Practice::factory()->create(['user_id' => $client->id]);
        $package = Package::factory()->create();
        Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->assertSee('Not started');
    }

    public function test_editing_a_practice_preserves_a_specialty_outside_the_preset_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $client->id, 'specialty' => 'Neurosurgery']);

        $component = Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->assertSee('Neurosurgery');

        $component->set('practiceName', $practice->name)->call('save')->assertRedirect(route('admin.users'));

        $this->assertSame('Neurosurgery', $practice->refresh()->specialty);
    }

    public function test_admin_can_add_an_osha_location(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $client->id]);

        $component = Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->call('addOshaLocation')
            ->set('oshaLocations.0.name', 'New Satellite Office')
            ->call('saveOshaLocation', 0);

        $component->assertHasNoErrors();
        $this->assertDatabaseHas('osha_locations', ['practice_id' => $practice->id, 'name' => 'New Satellite Office']);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'osha_location.created']);
    }

    public function test_admin_can_edit_an_osha_location(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $client->id]);
        $location = OshaLocation::factory()->create(['practice_id' => $practice->id, 'name' => 'Old Location Name']);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->set('oshaLocations.0.name', 'Updated Location Name')
            ->call('saveOshaLocation', 0)
            ->assertHasNoErrors();

        $this->assertSame('Updated Location Name', $location->refresh()->name);
    }

    public function test_admin_can_delete_an_osha_location(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $practice = Practice::factory()->create(['user_id' => $client->id]);
        $location = OshaLocation::factory()->create(['practice_id' => $practice->id]);

        Livewire::actingAs($admin)
            ->test('admin.user-form', ['user' => $client])
            ->call('deleteOshaLocation', 0);

        $this->assertDatabaseMissing('osha_locations', ['id' => $location->id]);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'osha_location.deleted']);
    }

    // ── Orders ────────────────────────────────────────────────────────────

    public function test_admin_can_view_and_filter_the_orders_list(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create(['name' => 'Filterable Client']);
        $package = Package::factory()->create();
        Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id, 'status' => OrderStatus::Cancelled]);

        Livewire::actingAs($admin)
            ->test('admin.order-list')
            ->assertSee('Filterable Client')
            ->set('status', OrderStatus::Paid->value)
            ->assertDontSee('Filterable Client');
    }

    public function test_admin_can_update_an_orders_status_and_amount(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $package = Package::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);

        Livewire::actingAs($admin)
            ->test('admin.order-form', ['order' => $order])
            ->set('status', OrderStatus::Cancelled->value)
            ->set('amountPaid', '0')
            ->set('notes', 'Refunded per client request.')
            ->call('save')
            ->assertRedirect(route('admin.orders'));

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('Refunded per client request.', $order->notes);
        $this->assertDatabaseHas('activity_logs', ['event_type' => 'order.updated']);
    }

    public function test_admin_can_delete_an_order_and_its_files_are_removed(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $package = Package::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);
        Storage::disk('local')->put('compliance/doc.pdf', 'fake-doc');
        $document = GeneratedDocument::factory()->create(['order_id' => $order->id, 'pdf_storage_path' => 'compliance/doc.pdf']);

        Livewire::actingAs($admin)
            ->test('admin.order-form', ['order' => $order])
            ->call('delete')
            ->assertRedirect(route('admin.orders'));

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('generated_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing('compliance/doc.pdf');
    }
}
