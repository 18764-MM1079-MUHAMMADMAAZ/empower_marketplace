<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PaymentLogsExport implements FromCollection, WithHeadings
{
    /** @param Collection<int, array<string, mixed>> $rows */
    public function __construct(private Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['When', 'Customer', 'Package', 'Amount', 'Status', 'Transaction ID', 'Message', 'Order ID'];
    }
}
