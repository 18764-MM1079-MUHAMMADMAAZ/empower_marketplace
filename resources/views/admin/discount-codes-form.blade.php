<x-layouts.app title="{{ isset($discountCode) ? 'Edit Discount Code' : 'New Discount Code' }}" :inline-heading="true">
    <livewire:admin.discount-code-form :discount-code="$discountCode ?? null" />
</x-layouts.app>
