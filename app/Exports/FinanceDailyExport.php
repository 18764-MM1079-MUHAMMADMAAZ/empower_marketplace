<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Thin wrapper around a pre-built row collection for the daily Finance report (sso.md §10) — all
 * the Order/Practice business logic lives in SendFinanceDailyReport, not here, so this class stays
 * exactly what maatwebsite/excel needs and nothing more.
 */
class FinanceDailyExport implements FromCollection, WithHeadings
{
    /** @param Collection<int, array<string, mixed>> $rows */
    public function __construct(private Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Order ID', 'Order Date', 'Status', 'Source System', 'Practice ID',
            'Subscription/Account ID', 'Practice Name', 'Practice Address', 'Plan', 'Price', 'Term',
            'Start Date', 'Preferred Billing Option', 'Subscribed By Name', 'Subscribed By Email',
        ];
    }
}
