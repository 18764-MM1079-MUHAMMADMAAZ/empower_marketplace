<?php

use App\Models\ActivityLog;
use App\Models\CompliancePolicy;
use App\Models\GeneratedDocument;
use App\Models\IntakeUpload;
use App\Models\Questionnaire;
use App\Support\Questionnaires;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function questionnaires(): Collection
    {
        return Questionnaire::all();
    }

    /** Whether this questionnaire's document type has moved to the Practice Intake wizard's
     *  policy-driven merge (see GenerateComplianceDocument) — if so, this row's schema is no
     *  longer consulted for any real client submission. */
    public function isMigratedToWizard(Questionnaire $questionnaire): bool
    {
        $documentType = $questionnaire->documentType();

        return $documentType && CompliancePolicy::where('manual', $documentType->value)->exists();
    }

    public function toggleVisibility(int $questionnaireId): void
    {
        $questionnaire = Questionnaire::findOrFail($questionnaireId);
        $newVisible = ! $questionnaire->is_visible;

        $promoted = Questionnaires::setVisibility($questionnaire->upload_type, $newVisible);

        ActivityLog::record(
            $newVisible ? 'questionnaire.shown' : 'questionnaire.hidden',
            "{$questionnaire->title} was ".($newVisible ? 'made visible on' : 'hidden from')." Step 2.",
            user: auth()->user(),
            subject: $questionnaire,
        );

        if ($promoted) {
            ActivityLog::record(
                'questionnaire.required_reassigned',
                "{$promoted['title']} is now the required questionnaire since {$questionnaire->title} was hidden.",
                user: auth()->user(),
            );
        }

        unset($this->questionnaires);

        $this->dispatch('toast', message: "{$questionnaire->title} ".($newVisible ? 'made visible.' : 'hidden.'), type: 'success');
    }

    public function delete(int $questionnaireId): void
    {
        $questionnaire = Questionnaire::findOrFail($questionnaireId);
        $documentType = $questionnaire->documentType();

        $hasHistory = IntakeUpload::where('upload_type', $questionnaire->upload_type)->exists()
            || ($documentType && GeneratedDocument::where('document_type', $documentType)->exists());

        if ($hasHistory) {
            $this->addError('delete', "{$questionnaire->title} has existing uploads or generated documents and can't be deleted. Hide it instead.");
            $this->dispatch('toast', message: "{$questionnaire->title} has uploads and can't be deleted.", type: 'error');

            return;
        }

        $title = $questionnaire->title;
        $questionnaire->delete();

        ActivityLog::record('questionnaire.deleted', "{$title} was deleted.", user: auth()->user());

        unset($this->questionnaires);

        $this->dispatch('toast', message: "{$title} deleted.", type: 'success');
    }
};
?>

<div class="space-y-4" x-data="{ confirmId: null, confirmLabel: '' }">
    <div class="rounded-xl border border-[#bfdcf3] bg-[#fff3cd] px-4 py-3 text-xs text-[#9a6700]">
        Clients no longer download or upload these questionnaires — the Practice Intake wizard now
        captures workflow answers directly. Rows marked <strong>"Wizard-driven"</strong> below are
        fully inactive: their schema and any re-uploaded template have no effect on real client
        documents. This screen still matters for the HIPAA Business Associate manual (not yet
        migrated) and for testing in the Document Generator tool.
    </div>
    <p class="text-xs text-empower-muted">
        The compliance manual template each questionnaire feeds. Hiding one removes it from the
        Document Generator's test flow — existing uploads and generated documents are untouched. If
        the questionnaire currently marked Required is hidden, another visible one is automatically
        promoted.
    </p>

    @error('delete')
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>
    @enderror

    {{-- Temporarily hidden — creating new questionnaire types is disabled for now.
    <div class="flex justify-end">
        <a href="{{ route('admin.questionnaires.create') }}" wire:navigate
            class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-4 py-2 text-xs font-bold text-white hover:bg-[#087fa9] transition-colors">
            + New Questionnaire
        </a>
    </div>
    --}}

    <div
        class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">
        <div class="w-full overflow-x-auto">
            <table class="w-full min-w-[820px] text-sm">
                <thead>
                    <tr class="bg-page text-left text-xs font-extrabold uppercase tracking-wider text-empower-muted">
                        <th class="px-5 py-3">Questionnaire</th>
                        <th class="px-5 py-3">Package Tiers</th>
                        <th class="px-5 py-3">Required</th>
                        <th class="px-5 py-3">Visibility</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-empower-border">
                    @forelse($this->questionnaires as $questionnaire)
                    <tr class="hover:bg-page/60 transition-colors">
                        <td class="px-5 py-3.5">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-navy">{{ $questionnaire->title }}</span>
                                @if($this->isMigratedToWizard($questionnaire))
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[0.62rem] font-extrabold uppercase tracking-wider bg-[#fde2e2] text-[#a53b3b]">Wizard-driven</span>
                                @endif
                            </div>
                            <div class="text-xs text-empower-muted">{{ $questionnaire->description }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-empower-text">
                            {{ $questionnaire->tiers === null ? 'All tiers' : implode(', ', array_map('ucfirst',
                            $questionnaire->tiers)) }}
                        </td>
                        <td class="px-5 py-3.5">
                            <span
                                class="inline-flex items-center px-2.5 py-1 rounded-full text-[0.65rem] font-extrabold uppercase tracking-wider {{ $questionnaire->is_required ? 'bg-[#fff3cd] text-[#9a6700]' : 'bg-[#edf2f7] text-empower-muted' }}">
                                {{ $questionnaire->is_required ? 'Required' : 'Optional' }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            <button wire:click="toggleVisibility({{ $questionnaire->id }})"
                                wire:target="toggleVisibility({{ $questionnaire->id }})" wire:loading.attr="disabled"
                                wire:target="toggleVisibility({{ $questionnaire->id }})"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[0.68rem] font-extrabold uppercase tracking-wider transition-colors cursor-pointer {{ $questionnaire->is_visible ? 'bg-[#dff7f0] text-[#0f7a4f]' : 'bg-[#edf2f7] text-empower-muted' }}">
                                <span wire:loading.remove wire:target="toggleVisibility({{ $questionnaire->id }})">{{
                                    $questionnaire->is_visible ? 'Visible' : 'Hidden' }}</span>
                                <span wire:loading wire:target="toggleVisibility({{ $questionnaire->id }})">
                                    <x-spinner class="h-3 w-3" />
                                </span>
                            </button>
                        </td>
                        <td class="px-5 py-3.5 text-right space-x-3">
                            <a href="{{ route('admin.questionnaires.edit', $questionnaire) }}" wire:navigate
                                class="text-xs font-bold text-[#0b9ed0] hover:underline">Edit</a>
                            <button type="button"
                                x-on:click="confirmId = {{ $questionnaire->id }}; confirmLabel = @js($questionnaire->title)"
                                class="text-xs font-bold text-red-600 hover:underline">Delete</button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-sm text-empower-muted italic">No
                            questionnaires yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div x-show="confirmId !== null" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-sm bg-white rounded-[1.25rem] shadow-xl p-6" x-on:click.outside="confirmId = null">
            <h3 class="text-base font-semibold text-navy mb-2">Delete <span x-text="confirmLabel"></span>?</h3>
            <p class="text-sm text-empower-muted mb-5">This cannot be undone.</p>
            <div class="flex justify-end gap-3">
                <button type="button" x-on:click="confirmId = null"
                    class="rounded-lg border border-empower-border px-4 py-2 text-sm font-semibold text-empower-muted hover:bg-page transition-colors">
                    Cancel
                </button>
                <button type="button" wire:target="delete"
                    x-on:click="$wire.delete(confirmId).then(() => confirmId = null).catch(() => {})"
                    wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="delete"
                    class="inline-flex items-center gap-1 rounded px-5 py-2 text-sm font-bold transition-colors bg-red-600 text-white hover:bg-red-700">
                    <span wire:loading.remove wire:target="delete">Delete</span>
                    <span wire:loading.inline-flex wire:target="delete" class="inline-flex items-center gap-1.5">
                        <x-spinner class="h-3.5 w-3.5" /> Deleting…
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>