<x-layouts.app title="{{ isset($package) ? 'Edit Package' : 'New Package' }}">
    <livewire:admin.package-form :package="$package ?? null" />
</x-layouts.app>
