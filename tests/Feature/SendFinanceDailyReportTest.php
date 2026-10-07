<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Exports\FinanceDailyExport;
use App\Mail\FinanceDailyReportMail;
use App\Models\Order;
use App\Models\Package;
use App\Models\Practice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Excel;
use Tests\TestCase;

class SendFinanceDailyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_yesterdays_new_orders_to_every_admin(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['name' => 'Jane Provider', 'email' => 'jane@practice.com']);
        Practice::factory()->create(['user_id' => $user->id, 'name' => 'Sunrise Family Medicine', 'address' => '1 Main St']);
        $package = Package::factory()->create(['name' => 'Professional Compliance']);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'original_price' => 1299.00,
            'billing_cycle' => 'annual',
            'clover_card_token' => 'tok_123',
            'created_at' => now()->subDay(),
            'paid_at' => now()->subDay(),
        ]);

        Artisan::call('reports:finance-daily');

        Mail::assertQueued(FinanceDailyReportMail::class, function (FinanceDailyReportMail $mail) use ($admin, $order) {
            $row = $mail->rows->firstWhere('Order ID', $order->id);

            return $mail->hasTo($admin->email)
                && $row !== null
                && $row['Status'] === 'New'
                && $row['Source System'] === 'Direct'
                && $row['Practice Name'] === 'Sunrise Family Medicine'
                && $row['Plan'] === 'Professional Compliance'
                && $row['Price'] === '1299.00'
                && $row['Preferred Billing Option'] === 'Card on file'
                && $row['Subscribed By Email'] === 'jane@practice.com';
        });
    }

    public function test_reports_yesterdays_cancellations_as_reversed(): void
    {
        Mail::fake();

        User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create([
            'status' => OrderStatus::Cancelled,
            'created_at' => now()->subWeek(),
            'cancelled_at' => now()->subDay(),
        ]);

        Artisan::call('reports:finance-daily');

        Mail::assertQueued(FinanceDailyReportMail::class, function (FinanceDailyReportMail $mail) use ($order) {
            $row = $mail->rows->firstWhere('Order ID', $order->id);

            return $row !== null && $row['Status'] === 'Reversed';
        });
    }

    public function test_sends_nothing_when_there_is_no_activity(): void
    {
        Mail::fake();
        User::factory()->create(['role' => UserRole::Admin]);

        Artisan::call('reports:finance-daily', ['--date' => now()->subDays(30)->toDateString()]);

        Mail::assertNothingQueued();
    }

    public function test_respects_a_configured_recipient_instead_of_admin_users(): void
    {
        Mail::fake();
        config(['services.finance.report_email' => 'finance@empowerhci.com']);

        User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->create(['created_at' => now()->subDay(), 'paid_at' => now()->subDay()]);

        Artisan::call('reports:finance-daily');

        Mail::assertQueued(FinanceDailyReportMail::class, fn (FinanceDailyReportMail $mail) => $mail->hasTo('finance@empowerhci.com'));
    }

    public function test_the_csv_export_renders_real_rows_without_error(): void
    {
        $rows = collect([[
            'Order ID' => 1, 'Order Date' => now()->toDateTimeString(), 'Status' => 'New',
            'Source System' => 'Direct', 'Practice ID' => 1, 'Subscription/Account ID' => null,
            'Practice Name' => 'Test Practice', 'Practice Address' => '1 Main St', 'Plan' => 'Essential',
            'Price' => '999.00', 'Term' => 'year', 'Start Date' => now()->toDateString(),
            'Preferred Billing Option' => 'Card on file', 'Subscribed By Name' => 'Jane',
            'Subscribed By Email' => 'jane@example.com',
        ]]);

        $csv = \Maatwebsite\Excel\Facades\Excel::raw(new FinanceDailyExport($rows), Excel::CSV);

        $this->assertStringContainsString('Order ID', $csv);
        $this->assertStringContainsString('Test Practice', $csv);
        $this->assertStringContainsString('jane@example.com', $csv);
    }
}
