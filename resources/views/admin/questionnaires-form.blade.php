<x-layouts.app title="{{ isset($questionnaire) ? 'Edit Questionnaire' : 'New Questionnaire' }}" :inline-heading="true">
    <livewire:admin.questionnaire-form :questionnaire="$questionnaire ?? null" />
</x-layouts.app>
