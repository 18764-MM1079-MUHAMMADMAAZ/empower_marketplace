<?php

use App\Enums\IntakeUploadType;
use App\Models\ActivityLog;
use App\Support\Questionnaires;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function questionnaires(): Collection
    {
        return Questionnaires::allWithVisibility();
    }

    public function toggleVisibility(string $uploadType): void
    {
        $type = IntakeUploadType::from($uploadType);
        $current = $this->questionnaires->firstWhere(fn (array $q) => $q['uploadType'] === $type);
        $newVisible = ! $current['isVisible'];

        $promoted = Questionnaires::setVisibility($type, $newVisible);

        ActivityLog::record(
            $newVisible ? 'questionnaire.shown' : 'questionnaire.hidden',
            "{$current['title']} was ".($newVisible ? 'made visible on' : 'hidden from')." Step 2.",
            user: auth()->user(),
        );

        if ($promoted) {
            ActivityLog::record(
                'questionnaire.required_reassigned',
                "{$promoted['title']} is now the required questionnaire since {$current['title']} was hidden.",
                user: auth()->user(),
            );
        }

        unset($this->questionnaires);
    }
};
?>

<div class="space-y-4">
    <p class="text-sm text-empower-muted">
        Control which questionnaires clients can download and fill out at Step 2 of the portal.
        Hiding one removes it from Step 2/3 for new and resubmitting clients — existing uploads and
        generated documents are untouched. If the questionnaire currently marked Required is hidden,
        another visible one is automatically promoted so clients are never left with nothing
        mandatory to submit back.
    </p>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-[720px] text-sm">
            <thead>
                <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                    <th class="px-5 py-3">Questionnaire</th>
                    <th class="px-5 py-3">Package Tiers</th>
                    <th class="px-5 py-3">Required</th>
                    <th class="px-5 py-3">Visibility</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-empower-border">
                @foreach($this->questionnaires as $questionnaire)
                    <tr class="hover:bg-page/60 transition-colors">
                        <td class="px-5 py-3.5">
                            <div class="font-semibold text-navy">{{ $questionnaire['title'] }}</div>
                            <div class="text-xs text-empower-muted">{{ $questionnaire['description'] }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">
                            {{ $questionnaire['tiers'] === null ? 'All tiers' : implode(', ', array_map('ucfirst', $questionnaire['tiers'])) }}
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.65rem] font-extrabold uppercase tracking-wider {{ $questionnaire['required'] ? 'bg-[#fff3cd] text-[#9a6700]' : 'bg-[#edf2f7] text-empower-muted' }}">
                                {{ $questionnaire['required'] ? 'Required' : 'Optional' }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            <button wire:click="toggleVisibility('{{ $questionnaire['uploadType']->value }}')" wire:target="toggleVisibility('{{ $questionnaire['uploadType']->value }}')"
                                wire:loading.attr="disabled" wire:target="toggleVisibility('{{ $questionnaire['uploadType']->value }}')"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold uppercase tracking-wider transition-colors cursor-pointer {{ $questionnaire['isVisible'] ? 'bg-[#dff7f0] text-[#0f7a4f]' : 'bg-[#edf2f7] text-empower-muted' }}">
                                <span wire:loading.remove wire:target="toggleVisibility('{{ $questionnaire['uploadType']->value }}')">{{ $questionnaire['isVisible'] ? 'Visible' : 'Hidden' }}</span>
                                <span wire:loading wire:target="toggleVisibility('{{ $questionnaire['uploadType']->value }}')"><x-spinner class="h-3 w-3" /></span>
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            </table>
        </div>
    </div>
</div>
