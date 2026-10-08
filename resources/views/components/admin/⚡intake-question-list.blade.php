<?php

use App\Models\ActivityLog;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?int $editingQuestionId = null;

    public string $editTitle = '';

    public string $editPromptSummary = '';

    public string $editWhyWeAsk = '';

    #[Computed]
    public function sections(): Collection
    {
        return IntakeSection::with('questions.policies')->orderBy('sort_order')->get();
    }

    public function edit(int $questionId): void
    {
        $question = IntakeQuestion::findOrFail($questionId);

        $this->editingQuestionId = $questionId;
        $this->editTitle = $question->title;
        $this->editPromptSummary = $question->prompt_summary ?? '';
        $this->editWhyWeAsk = $question->why_we_ask ?? '';
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->editingQuestionId = null;
    }

    public function save(): void
    {
        $this->validate([
            'editTitle' => 'required|string|max:150',
            'editPromptSummary' => 'nullable|string|max:500',
            'editWhyWeAsk' => 'nullable|string|max:500',
        ]);

        $question = IntakeQuestion::findOrFail($this->editingQuestionId);
        $question->update([
            'title' => $this->editTitle,
            'prompt_summary' => $this->editPromptSummary ?: null,
            'why_we_ask' => $this->editWhyWeAsk ?: null,
        ]);

        ActivityLog::record(
            'intake_question.updated',
            "Practice Intake question \"{$question->title}\" was updated.",
            user: auth()->user(),
            subject: $question,
        );

        $this->editingQuestionId = null;
        unset($this->sections);

        $this->dispatch('toast', message: 'Intake question updated.', type: 'success');
    }
};
?>

<div class="space-y-4">
    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <h2 data-tour="page-title" class="text-lg font-semibold text-navy mb-1">Practice Intake Questions</h2>
        <p class="text-sm text-empower-muted">The 66 workflow questions shown one-per-screen in the Practice Intake wizard, grouped by section. Edit the title, prompt, and "why we ask" copy shown to the client — the policies a question maps to aren't editable here, since that structure drives document generation.</p>
    </div>

    <div data-tour="sections" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
    @foreach($this->sections as $section)
    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <h3 class="text-sm font-semibold text-navy mb-3">{{ $section->label }}</h3>

        <div class="divide-y divide-empower-border">
            @foreach($section->questions as $question)
            <div class="py-3">
                @if($editingQuestionId === $question->id)
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-[#173a59] mb-1">Title</label>
                        <input wire:model="editTitle" type="text"
                            class="w-full rounded-xl border {{ $errors->has('editTitle') ? 'border-red-400' : 'border-empower-border' }} bg-page px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        @error('editTitle') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#173a59] mb-1">Prompt summary <span class="text-empower-muted font-normal">(shown under the title)</span></label>
                        <textarea wire:model="editPromptSummary" rows="2"
                            class="w-full rounded-xl border {{ $errors->has('editPromptSummary') ? 'border-red-400' : 'border-empower-border' }} bg-page px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition"></textarea>
                        @error('editPromptSummary') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#173a59] mb-1">Why we ask <span class="text-empower-muted font-normal">(shown in the side panel)</span></label>
                        <textarea wire:model="editWhyWeAsk" rows="2"
                            class="w-full rounded-xl border {{ $errors->has('editWhyWeAsk') ? 'border-red-400' : 'border-empower-border' }} bg-page px-4 py-2 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition"></textarea>
                        @error('editWhyWeAsk') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-3">
                        <button wire:click="save" wire:target="save"
                            class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-4 py-1.5 text-xs font-bold text-white hover:bg-[#087fa9] transition-colors"
                            wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="save">
                            <span wire:loading.remove wire:target="save">Save</span>
                            <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5"><x-spinner class="h-3 w-3" /> Saving…</span>
                        </button>
                        <button wire:click="cancelEdit" class="text-xs font-semibold text-empower-muted hover:underline">Cancel</button>
                    </div>
                </div>
                @else
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-empower-text">{{ $question->title }}</p>
                        @if($question->prompt_summary)
                        <p class="text-xs text-empower-muted mt-0.5">{{ $question->prompt_summary }}</p>
                        @else
                        <p class="text-xs text-[#9a6700] italic mt-0.5">No prompt summary yet.</p>
                        @endif
                        <div class="flex flex-wrap gap-1.5 mt-1.5">
                            @foreach($question->policies as $policy)
                            <span class="inline-flex items-center rounded-full bg-page px-2 py-0.5 text-[0.68rem] font-semibold text-navy">{{ $policy->code }}</span>
                            @endforeach
                        </div>
                    </div>
                    <button wire:click="edit({{ $question->id }})" class="text-xs font-bold text-[#0b9ed0] hover:underline flex-shrink-0">Edit</button>
                </div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endforeach
    </div>

    @if($this->sections->isEmpty())
    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <p class="text-sm text-empower-muted italic">No Practice Intake sections/questions have been seeded yet.</p>
    </div>
    @endif
</div>
