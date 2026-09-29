<x-layouts.app title="{{ isset($questionnaire) ? 'Edit Questionnaire' : 'New Questionnaire' }}">
    <livewire:admin.questionnaire-form :questionnaire="$questionnaire ?? null" />
</x-layouts.app>
