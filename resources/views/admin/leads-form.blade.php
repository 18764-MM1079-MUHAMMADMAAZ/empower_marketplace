<x-layouts.app title="{{ isset($lead) ? 'Edit Lead' : 'New Lead' }}" :inline-heading="true">
    <livewire:admin.lead-form :lead="$lead ?? null" />
</x-layouts.app>
