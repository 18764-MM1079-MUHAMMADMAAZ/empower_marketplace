<x-layouts.app title="{{ isset($user) ? 'Edit User' : 'New User' }}" :inline-heading="true">
    <livewire:admin.user-form :user="$user ?? null" />
</x-layouts.app>
