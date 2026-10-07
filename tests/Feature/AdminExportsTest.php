<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\FinanceDailyReportMail;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class AdminExportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_export_leads_to_excel(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Lead::factory()->create(['name' => 'Exportable Lead']);

        Livewire::actingAs($admin)
            ->test('admin.lead-list')
            ->call('export')
            ->assertFileDownloaded();
    }

    public function test_admin_can_export_users_to_excel(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['name' => 'Exportable User']);

        Livewire::actingAs($admin)
            ->test('admin.user-list')
            ->call('export')
            ->assertFileDownloaded();
    }

    public function test_admin_can_export_orders_to_excel_respecting_the_current_filters(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $package = Package::factory()->create();
        Order::factory()->create(['user_id' => $client->id, 'package_id' => $package->id]);

        Livewire::actingAs($admin)
            ->test('admin.order-list')
            ->set('search', 'no-such-client')
            ->call('export')
            ->assertFileDownloaded();
    }

    public function test_admin_can_export_payment_logs_to_excel(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        PaymentLog::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.payment-log-list')
            ->call('export')
            ->assertFileDownloaded();
    }

    public function test_admin_can_export_activity_log_to_excel(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        ActivityLog::factory()->create();

        Livewire::actingAs($admin)
            ->test('admin.activity-log-list')
            ->call('export')
            ->assertFileDownloaded();
    }

    public function test_admin_can_trigger_the_finance_report_on_demand_from_the_orders_page(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $client = User::factory()->create();
        $package = Package::factory()->create();
        Order::factory()->create([
            'user_id' => $client->id,
            'package_id' => $package->id,
            'created_at' => now()->subDay(),
            'paid_at' => now()->subDay(),
        ]);

        Livewire::actingAs($admin)
            ->test('admin.order-list')
            ->call('sendFinanceReport')
            ->assertDispatched('toast');

        Mail::assertQueued(FinanceDailyReportMail::class, fn (FinanceDailyReportMail $mail) => $mail->hasTo($admin->email));
    }
}
