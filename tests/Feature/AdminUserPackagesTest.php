<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exports\UsersExport;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** Admin › Users shows each client's purchased packages, grouped by practice. */
class AdminUserPackagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function package(string $name): Package
    {
        return Package::factory()->create(['name' => $name, 'slug' => str($name)->slug()->value()]);
    }

    public function test_each_clients_live_packages_are_listed_under_their_practice(): void
    {
        $client = User::factory()->create();
        Practice::factory()->create(['user_id' => $client->id, 'name' => 'Testing ABC']);
        Order::factory()->create(['user_id' => $client->id, 'package_id' => $this->package('Professional Compliance')->id]);
        Order::factory()->trialing()->create(['user_id' => $client->id, 'package_id' => $this->package('Essential Compliance')->id]);
        Order::factory()->create(['user_id' => $client->id, 'package_id' => $this->package('Complete Compliance')->id, 'cancels_at' => now()->addMonth()->setDate(2026, 12, 18)]);

        Livewire::actingAs($this->admin())
            ->test('admin.user-list')
            ->assertSee('Practice & packages')
            ->assertSeeInOrder(['Testing ABC', 'Professional Compliance', 'Essential Compliance', 'trial', 'Complete Compliance', 'ends Dec 18']);
    }

    public function test_cancelled_and_unpaid_orders_are_not_listed(): void
    {
        $client = User::factory()->create();
        Practice::factory()->create(['user_id' => $client->id, 'name' => 'Testing ABC']);
        Order::factory()->create(['user_id' => $client->id, 'package_id' => $this->package('Cancelled Package')->id, 'status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);
        Order::factory()->pendingPayment()->create(['user_id' => $client->id, 'package_id' => $this->package('Unpaid Package')->id]);

        Livewire::actingAs($this->admin())
            ->test('admin.user-list')
            ->assertDontSee('Cancelled Package')
            ->assertDontSee('Unpaid Package')
            ->assertSee('No active package');
    }

    public function test_partner_orders_for_another_practice_get_their_own_group(): void
    {
        $client = User::factory()->create();
        Practice::factory()->create(['user_id' => $client->id, 'name' => 'Main Street Clinic', 'source_system' => 'cch', 'external_practice_id' => 'P-100']);
        Order::factory()->create(['user_id' => $client->id, 'package_id' => $this->package('Professional Compliance')->id, 'external_practice_id' => 'P-100', 'payment_status' => PaymentStatus::ExternallyBilled]);
        Order::factory()->create(['user_id' => $client->id, 'package_id' => $this->package('Advanced Compliance')->id, 'external_practice_id' => 'P-200', 'payment_status' => PaymentStatus::ExternallyBilled]);

        Livewire::actingAs($this->admin())
            ->test('admin.user-list')
            ->assertSeeInOrder(['Main Street Clinic', 'Professional Compliance', 'Partner practice P-200', 'Advanced Compliance']);
    }

    public function test_a_numeric_partner_id_matching_the_practice_profile_shows_the_profile_name(): void
    {
        // Regression: "1011163" became an int when grouping and showed "Partner practice 1011163".
        $client = User::factory()->create();
        Practice::factory()->create(['user_id' => $client->id, 'name' => 'Testing Practice', 'source_system' => 'talkehr', 'external_practice_id' => '1011163']);
        Order::factory()->create(['user_id' => $client->id, 'package_id' => $this->package('Advanced Compliance')->id, 'external_practice_id' => '1011163', 'payment_status' => PaymentStatus::ExternallyBilled]);

        Livewire::actingAs($this->admin())
            ->test('admin.user-list')
            ->assertSeeInOrder(['Testing Practice', 'Advanced Compliance'])
            ->assertDontSee('Partner practice');
    }

    public function test_another_partner_practice_shows_the_name_saved_on_its_order(): void
    {
        $client = User::factory()->create();
        Practice::factory()->create(['user_id' => $client->id, 'name' => 'Testing Practice', 'external_practice_id' => '1011163']);
        Order::factory()->create(['user_id' => $client->id, 'package_id' => $this->package('Advanced Compliance')->id, 'external_practice_id' => '1011164', 'external_practice_name' => 'Riverside Pediatrics']);

        Livewire::actingAs($this->admin())
            ->test('admin.user-list')
            ->assertSeeInOrder(['Riverside Pediatrics', 'Advanced Compliance'])
            ->assertDontSee('Partner practice');
    }

    public function test_admins_show_a_dash(): void
    {
        Livewire::actingAs($this->admin())
            ->test('admin.user-list')
            ->assertDontSee('data-package-chip', false)
            ->assertDontSee('No active package');
    }

    public function test_the_excel_export_has_a_packages_column(): void
    {
        Excel::fake();
        $client = User::factory()->create(['email' => 'client@practice.com']);
        Practice::factory()->create(['user_id' => $client->id, 'name' => 'Testing ABC']);
        Order::factory()->trialing()->create(['user_id' => $client->id, 'package_id' => $this->package('Professional Compliance')->id]);

        Livewire::actingAs($this->admin())->test('admin.user-list')->call('export');

        Excel::assertDownloaded('users-'.now()->format('Y-m-d').'.xlsx', function (UsersExport $export) {
            $row = $export->collection()->first(fn ($row) => $row[1] === 'client@practice.com');

            return $export->headings()[4] === 'Packages' && $row[4] === 'Testing ABC: Professional Compliance (trial)';
        });
    }
}
