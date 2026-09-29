<x-layouts.app title="{{ isset($user) ? 'Edit User' : 'New User' }}">
    <livewire:admin.user-form :user="$user ?? null" />
</x-layouts.app>
