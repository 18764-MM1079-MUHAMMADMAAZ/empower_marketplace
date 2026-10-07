<?php

namespace App\Mail;

use App\Exports\FinanceDailyExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Daily digest of new subscriptions and cancellations for Finance to apply to CareCloud billing
 * (sso.md §10). See SendFinanceDailyReport for how $rows is built.
 */
class FinanceDailyReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param Collection<int, array<string, mixed>> $rows */
    public function __construct(public Carbon $reportDate, public Collection $rows) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Daily Subscription Report — {$this->reportDate->format('M j, Y')}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.admin.finance-daily-report',
            with: [
                'reportDate' => $this->reportDate,
                'rowCount' => $this->rows->count(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => Excel::raw(new FinanceDailyExport($this->rows), ExcelFormat::CSV),
                "subscriptions-{$this->reportDate->format('Y-m-d')}.csv",
            )->withMime('text/csv'),
        ];
    }
}
