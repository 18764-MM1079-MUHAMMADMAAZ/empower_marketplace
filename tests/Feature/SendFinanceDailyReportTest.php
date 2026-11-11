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
use App\Services\FinanceOrderForms;
use App\Services\OrderFormPdfGenerator;
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

    private function newOrderYesterday(array $attributes = []): Order
    {
        $user = User::factory()->create(['name' => 'Jane Provider', 'email' => 'jane@practice.com']);
        Practice::factory()->create([
            'user_id' => $user->id,
            'name' => 'Sunrise Family Medicine',
            'address' => '1 Main St, Austin, TX 78701',
            'phone' => '5125550100',
            'specialty' => 'Cardiology',
            'source_system' => 'cch',
            'subscription_account_id' => 'ACCT-77',
            'preferred_billing_option' => 'CareCloud subscription',
        ]);

        return Order::factory()->create($attributes + [
            'user_id' => $user->id,
            'package_id' => Package::factory()->create(['name' => 'Professional Compliance'])->id,
            'original_price' => 1299.00,
            'amount_paid' => 1299.00,
            'billing_cycle' => 'annual',
            'billing_address' => ['name' => 'Jane Provider', 'address1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'],
            'created_at' => now()->subDay(),
            'paid_at' => now()->subDay(),
        ]);
    }

    public function test_the_report_carries_an_order_form_pdf_for_each_new_order_next_to_the_sheet(): void
    {
        Mail::fake();
        config(['services.finance.report_email' => 'finance@empowerhci.com']);
        $order = $this->newOrderYesterday();

        Artisan::call('reports:finance-daily');

        Mail::assertQueued(FinanceDailyReportMail::class, function (FinanceDailyReportMail $mail) use ($order) {
            $names = collect($mail->attachments())->map(fn ($attachment) => $attachment->as)->all();

            return $mail->orderFormOrderIds === [$order->id]
                && count($names) === 2
                && in_array('order-form-'.$order->id.'.pdf', $names, true)
                && collect($names)->contains(fn ($name) => str_ends_with($name, '.csv'));
        });
    }

    public function test_the_order_form_pdf_is_a_real_pdf(): void
    {
        $order = $this->newOrderYesterday();

        $this->assertStringStartsWith('%PDF', app(OrderFormPdfGenerator::class)->generate($order));
    }

    public function test_the_order_form_uses_the_practice_and_user_data_and_the_clients_current_form_of_payment(): void
    {
        $order = $this->newOrderYesterday();
        $order->update(['terms_accepted_at' => now(), 'terms_accepted_ip' => '203.0.113.9']);

        $html = view('documents.order-form-pdf', ['order' => $order->fresh(['package', 'user.practice']), 'practice' => $order->user->practice])->render();

        $termsUrl = 'https://carecloud.app.box.com/v/ProactiveComplianceTerms';

        foreach (['ORDER FORM', 'Your Practice At A Glance', 'Bill-to Account:', 'Reference: EM-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT), 'Products &amp; Services', 'Quantity', 'UOM', 'Payment Term:', 'First Billing Cycle:', 'Initial Term:', '12 Months', 'Master Service Agreement', $termsUrl, 'Questions? Contact me', 'Sunrise Family Medicine', '1 Main St, Austin, TX 78701', '5125550100', 'Cardiology', 'CareCloud', 'ACCT-77',
            'Jane Provider', 'jane@practice.com', 'Professional Compliance', '$1,299.00', 'current form of payment: CareCloud subscription (account ACCT-77)',
            '203.0.113.9'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        $this->assertSame(2, substr_count($html, 'href="'.$termsUrl.'"'));
    }

    public function test_an_order_form_falls_back_to_the_card_on_file_when_no_billing_option_is_set(): void
    {
        $order = $this->newOrderYesterday(['card_last_four' => '4242', 'card_expiry_month' => 7, 'card_expiry_year' => 2029]);
        $order->user->practice->update(['preferred_billing_option' => null]);

        $html = view('documents.order-form-pdf', ['order' => $order->fresh(['package', 'user.practice']), 'practice' => $order->user->practice->fresh()])->render();

        $this->assertStringContainsString('current form of payment: card ending 4242 (exp. 07/2029)', $html);
    }

    public function test_the_configured_copy_addresses_are_copied_without_duplicating_a_recipient(): void
    {
        Mail::fake();
        config([
            'services.finance.report_email' => 'finance@empowerhci.com',
            'services.finance.order_form_copy_emails' => 'financeops@carecloud.com, info@empowerhci.com, finance@empowerhci.com',
        ]);
        $this->newOrderYesterday();

        Artisan::call('reports:finance-daily');

        Mail::assertQueued(FinanceDailyReportMail::class, fn (FinanceDailyReportMail $mail) => $mail->hasTo('finance@empowerhci.com')
            && $mail->hasCc('financeops@carecloud.com')
            && $mail->hasCc('info@empowerhci.com')
            && ! $mail->hasCc('finance@empowerhci.com'));
    }

    public function test_no_copies_are_sent_when_none_are_configured(): void
    {
        Mail::fake();
        config(['services.finance.report_email' => 'finance@empowerhci.com']);
        $this->newOrderYesterday();

        Artisan::call('reports:finance-daily');

        Mail::assertQueued(FinanceDailyReportMail::class, fn (FinanceDailyReportMail $mail) => $mail->cc === []);
    }

    public function test_a_cancellation_only_report_has_no_order_forms(): void
    {
        Mail::fake();
        config(['services.finance.report_email' => 'finance@empowerhci.com']);
        $this->newOrderYesterday(['created_at' => now()->subDays(5), 'status' => OrderStatus::Cancelled, 'cancelled_at' => now()->subDay()]);

        Artisan::call('reports:finance-daily');

        Mail::assertQueued(FinanceDailyReportMail::class, fn (FinanceDailyReportMail $mail) => $mail->orderFormOrderIds === [] && count($mail->attachments()) === 1);
    }

    public function test_an_admin_can_open_an_orders_form_from_the_orders_page_and_a_client_cannot(): void
    {
        $order = $this->newOrderYesterday();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.orders.order-form', $order))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        auth()->logout();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.orders.order-form', $order))
            ->assertRedirect();
    }

    public function test_the_order_form_shows_the_provider_count_and_per_provider_price(): void
    {
        $order = $this->newOrderYesterday(['original_price' => 5097.00, 'amount_paid' => 5097.00]);
        $order->package->update(['annual_price' => 1699.00]);
        $order->user->practice->update(['billable_providers_count' => 3]);

        $html = view('documents.order-form-pdf', ['order' => $order->fresh(['package', 'user.practice']), 'practice' => $order->user->practice->fresh()])->render();

        $this->assertStringContainsString('$1,699.00 / year', $html);
        $this->assertStringContainsString('$5,097.00 / year', $html);
        $this->assertMatchesRegularExpression('#text-align: center;">3</td>#', $html);
    }

    public function test_the_order_form_banner_and_table_include_the_quote_expiry_and_non_billable_users_lines(): void
    {
        $order = $this->newOrderYesterday();

        $html = view('documents.order-form-pdf', ['order' => $order->fresh(['package', 'user.practice']), 'practice' => $order->user->practice])->render();

        $this->assertStringContainsString('Quote expires: N/A (order placed online)', $html);
        $this->assertStringContainsString('Non-Billable Users:', $html);
    }

    public function test_sending_a_new_order_form_with_no_finance_address_and_no_admins_does_nothing(): void
    {
        Mail::fake();
        $order = $this->newOrderYesterday();

        app(FinanceOrderForms::class)->sendNewOrder($order);

        Mail::assertNothingQueued();
    }
}
