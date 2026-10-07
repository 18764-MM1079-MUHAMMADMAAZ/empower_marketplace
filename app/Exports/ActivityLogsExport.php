<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ActivityLogsExport implements FromCollection, WithHeadings
{
    /** @param Collection<int, array<string, mixed>> $rows */
    public function __construct(private Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['When', 'Actor', 'Event', 'Description', 'Order ID'];
    }
}
