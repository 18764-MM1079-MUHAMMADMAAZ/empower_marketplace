<x-layouts.app title="{{ isset($package) ? 'Edit Package' : 'New Package' }}" :inline-heading="true">
    <livewire:admin.package-form :package="$package ?? null" />
</x-layouts.app>
