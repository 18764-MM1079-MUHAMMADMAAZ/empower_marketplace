<?php

use App\Enums\DocumentType;
use App\Enums\IntakeUploadType;
use App\Enums\PackageTier;
use App\Models\ActivityLog;
use App\Models\CompliancePolicy;
use App\Models\GeneratedDocument;
use App\Models\IntakeUpload;
use App\Models\Questionnaire;
use App\Support\Questionnaires;
use App\Support\QuestionnaireSchemaGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public ?int $questionnaireId = null;

    public string $uploadType = '';

    public string $title = '';

    public string $description = '';

    /** @var array<int, string> */
    public array $tiers = [];

    public bool $allTiers = true;

    public bool $isRequired = false;

    public bool $isVisible = true;

    public $questionnaireFile = null;

    public $manualTemplateFile = null;

    // Set once a questionnaire already has a file on disk (edit mode), so re-uploading is optional.
    public bool $hasQuestionnaireFile = false;

    public bool $hasManualTemplateFile = false;

    public string $prefix = '';

    // The schema QuestionnaireSchemaGenerator derived from the currently-selected manual
    // template file, editable here before it's actually persisted on save().
    /** @var array{prefix: string, count: int, extra_fields: array<string, string>}|null */
    public ?array $pendingSchema = null;

    // Whether this questionnaire's upload_type already has real submissions/documents against
    // it — regenerating its schema won't retroactively fix those, so the form warns rather than
    // silently replacing it.
    public bool $hasUploadHistory = false;

    public function mount(?Questionnaire $questionnaire = null): void
    {
        if (! $questionnaire) {
            return;
        }

        $this->questionnaireId = $questionnaire->id;
        $this->uploadType = $questionnaire->upload_type->value;
        $this->title = $questionnaire->title;
        $this->description = $questionnaire->description;
        $this->tiers = $questionnaire->tiers ?? [];
        $this->allTiers = $questionnaire->tiers === null;
        $this->isRequired = $questionnaire->is_required;
        $this->isVisible = $questionnaire->is_visible;
        $this->prefix = $questionnaire->schema['prefix'] ?? '';
        $this->hasQuestionnaireFile = filled($questionnaire->questionnaire_file_path);
        $this->hasManualTemplateFile = $questionnaire->documentType()
            && Storage::disk('manual_templates')->exists("{$questionnaire->documentType()->value}.docx");
        $this->hasUploadHistory = $this->questionnaireHasHistory($questionnaire);
    }

    private function questionnaireHasHistory(Questionnaire $questionnaire): bool
    {
        $documentType = $questionnaire->documentType();

        return IntakeUpload::where('upload_type', $questionnaire->upload_type)->exists()
            || ($documentType && GeneratedDocument::where('document_type', $documentType)->exists());
    }

    /** Upload types that don't already have a Questionnaire row — plus the one being edited. */
    public function availableUploadTypes(): array
    {
        $taken = Questionnaire::query()
            ->when($this->questionnaireId, fn ($query) => $query->where('id', '!=', $this->questionnaireId))
            ->pluck('upload_type')
            ->map(fn ($type) => $type->value)
            ->all();

        return array_values(array_filter(
            IntakeUploadType::cases(),
            function ($type) use ($taken) {
                if (in_array($type->value, $taken, true)) {
                    return false;
                }

                $documentType = DocumentType::forQuestionnaireType($type);

                // Excludes PolishedClientDocument-style types: a per-upload flow with no merge
                // template at all, so the "questionnaire + manual template" pattern this form
                // manages doesn't apply to it.
                return $documentType !== null && ! $documentType->isPerUpload();
            }
        ));
    }

    public function updatedPrefix(): void
    {
        $this->regenerateSchema();
    }

    public function updatedManualTemplateFile(): void
    {
        if ($this->prefix === '') {
            $this->prefix = Str::of($this->title !== '' ? $this->title : 'doc')
                ->lower()->replaceMatches('/[^a-z0-9]/', '')->substr(0, 4)->toString() ?: 'doc';
        }

        $this->regenerateSchema();
    }

    private function regenerateSchema(): void
    {
        if (! $this->manualTemplateFile || $this->prefix === '') {
            return;
        }

        $this->pendingSchema = app(QuestionnaireSchemaGenerator::class)
            ->generate($this->manualTemplateFile->getRealPath(), $this->prefix);
    }

    public function resolvedDocumentType(): ?string
    {
        if (! $this->uploadType) {
            return null;
        }

        $documentType = DocumentType::forQuestionnaireType(IntakeUploadType::from($this->uploadType));

        return $documentType?->label();
    }

    /** Whether this document type has moved to the Practice Intake wizard's policy-driven
     *  merge — if so, everything on this form (schema, re-uploaded template) has no effect on
     *  any real client document anymore. */
    public function isMigratedToWizard(): bool
    {
        if (! $this->uploadType) {
            return false;
        }

        $documentType = DocumentType::forQuestionnaireType(IntakeUploadType::from($this->uploadType));

        return $documentType && CompliancePolicy::where('manual', $documentType->value)->exists();
    }

    public function save(): void
    {
        $rules = [
            'uploadType' => 'required|string',
            'title' => 'required|string|max:150',
            'description' => 'required|string|max:1000',
            'tiers' => 'array',
            'tiers.*' => 'string|in:'.implode(',', array_map(fn ($case) => $case->value, PackageTier::cases())),
            'isRequired' => 'boolean',
            'isVisible' => 'boolean',
            'questionnaireFile' => ($this->hasQuestionnaireFile ? 'nullable' : 'required').'|file|mimes:docx|max:10240',
            'manualTemplateFile' => ($this->hasManualTemplateFile ? 'nullable' : 'required').'|file|mimes:docx|max:10240',
            'prefix' => ($this->manualTemplateFile || ! $this->questionnaireId ? 'required' : 'nullable').'|string|max:20|regex:/^[a-z0-9]+$/',
        ];

        $this->validate($rules);

        $uploadType = IntakeUploadType::from($this->uploadType);
        $documentType = DocumentType::forQuestionnaireType($uploadType);

        $questionnaireFilePath = null;

        if ($this->questionnaireFile) {
            $filename = $this->title.'.docx';
            Storage::disk('public')->putFileAs('Manuals/Questionnaires', $this->questionnaireFile, $filename);
            $questionnaireFilePath = 'Manuals/Questionnaires/'.$filename;
        }

        if ($this->manualTemplateFile && $documentType) {
            Storage::disk('manual_templates')->putFileAs('', $this->manualTemplateFile, "{$documentType->value}.docx");
        }

        $data = [
            'upload_type' => $uploadType,
            'title' => $this->title,
            'description' => $this->description,
            'tiers' => $this->allTiers ? null : $this->tiers,
            'is_required' => $this->isRequired,
            'is_visible' => $this->isVisible,
        ];

        if ($questionnaireFilePath) {
            $data['questionnaire_file_path'] = $questionnaireFilePath;
        }

        // Only touch the stored schema when this save actually re-derived one (a new manual
        // template was uploaded) — editing other fields without re-uploading leaves the existing
        // schema exactly as it was.
        if ($this->pendingSchema !== null) {
            $data['schema'] = $this->pendingSchema;
        }

        if ($this->questionnaireId) {
            $questionnaire = Questionnaire::findOrFail($this->questionnaireId);
            $questionnaire->update($data);

            ActivityLog::record('questionnaire.updated', "{$questionnaire->title} was updated.", user: auth()->user(), subject: $questionnaire);
        } else {
            $questionnaire = Questionnaire::create($data);

            ActivityLog::record('questionnaire.created', "{$questionnaire->title} was created.", user: auth()->user(), subject: $questionnaire);
        }

        // The "exactly one required questionnaire" invariant is enforced the same way the
        // visibility toggle does it — a direct save() here bypasses Questionnaires::setVisibility(),
        // so re-sync explicitly rather than duplicating that logic.
        Questionnaires::setVisibility($questionnaire->upload_type, $questionnaire->is_visible);

        $this->redirect(route('admin.questionnaires'), navigate: true);
    }
};
?>

<div class="space-y-4">
    <a href="{{ route('admin.questionnaires') }}" wire:navigate class="text-sm font-semibold text-[#0b9ed0] hover:underline">&larr; Back to questionnaires</a>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <h2 class="text-lg font-semibold text-navy mb-4">{{ $questionnaireId ? 'Edit Questionnaire' : 'New Questionnaire' }}</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Upload Type</label>
                @if(! $questionnaireId && empty($this->availableUploadTypes()))
                    <p class="text-sm text-[#9a6700] bg-[#fff3cd] rounded-xl px-4 py-2.5">
                        Every questionnaire-capable type is already registered. Adding a genuinely new
                        one first needs a developer to run <code class="font-mono text-xs bg-white/60 rounded px-1 py-0.5">php artisan questionnaire:register-type</code> and deploy it.
                    </p>
                @else
                    <select wire:model="uploadType" {{ $questionnaireId ? 'disabled' : '' }}
                        class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition disabled:opacity-60">
                        <option value="">Select a type&hellip;</option>
                        @foreach($this->availableUploadTypes() as $case)
                            <option value="{{ $case->value }}">{{ $case->promptLabel() }}</option>
                        @endforeach
                    </select>
                    @if($questionnaireId)
                        <p class="mt-1 text-xs text-empower-muted">Upload type can't be changed after creation.</p>
                    @endif
                    @error('uploadType') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Title</label>
                <input wire:model="title" type="text" placeholder="HIPAA Privacy Questionnaire"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @if($this->resolvedDocumentType())
                <div class="sm:col-span-2">
                    <p class="text-xs text-empower-muted">Feeds compliance manual: <span class="font-semibold text-navy">{{ $this->resolvedDocumentType() }}</span></p>
                </div>
            @endif

            @if($this->isMigratedToWizard())
                <div class="sm:col-span-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <strong>This manual is now driven by the Practice Intake wizard.</strong> Clients answer its
                    workflow questions directly in the portal — nothing below (schema, re-uploaded template) affects
                    any real client document anymore. To change this manual's content, edit its questions under
                    <a href="{{ route('admin.intake-questions') }}" wire:navigate class="underline font-semibold">Intake Questions</a>
                    instead.
                </div>
            @endif

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Description</label>
                <textarea wire:model="description" rows="2" placeholder="Practice workflow details used to build your..."
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition"></textarea>
                @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Package Tiers</label>
                <label class="inline-flex items-center gap-2 mb-2">
                    <input wire:model.live="allTiers" type="checkbox" class="rounded border-empower-border text-navy focus:ring-accent">
                    <span class="text-sm text-empower-text">All tiers</span>
                </label>
                @if(! $allTiers)
                    <div class="flex flex-wrap gap-3 mt-1">
                        @foreach(PackageTier::cases() as $tier)
                            <label class="inline-flex items-center gap-2">
                                <input wire:model="tiers" value="{{ $tier->value }}" type="checkbox" class="rounded border-empower-border text-navy focus:ring-accent">
                                <span class="text-sm text-empower-text">{{ $tier->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
                @error('tiers') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="inline-flex items-center gap-2">
                    <input wire:model="isRequired" type="checkbox" class="rounded border-empower-border text-navy focus:ring-accent">
                    <span class="text-sm font-semibold text-[#173a59]">Required</span>
                </label>
                <p class="text-xs text-empower-muted mt-0.5">Only one questionnaire is ever required at a time — checking this clears it on any other.</p>
            </div>

            <div class="sm:col-span-2">
                <label class="inline-flex items-center gap-2">
                    <input wire:model="isVisible" type="checkbox" class="rounded border-empower-border text-navy focus:ring-accent">
                    <span class="text-sm font-semibold text-[#173a59]">Visible on Step 2</span>
                </label>
            </div>

            <div class="sm:col-span-2 pt-2 mt-1 border-t border-empower-border">
                <p class="text-xs font-extrabold uppercase tracking-wider text-empower-muted mb-3">Files &amp; Merge Fields</p>
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Prefix</label>
                <input wire:model.live="prefix" type="text" placeholder="cmp" maxlength="20"
                    class="w-full sm:w-1/2 rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm font-mono text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                <p class="mt-1 text-xs text-empower-muted">Short merge-field code (lowercase letters/numbers only) — must match the questionnaire's own numbering, e.g. "cmp" for questions coded CMP-01, CMP-02&hellip;</p>
                @error('prefix') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Questionnaire File (.docx)</label>
                <input wire:model="questionnaireFile" type="file" accept=".docx"
                    class="block w-full text-sm text-empower-text file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-navy file:text-white hover:file:bg-navy-dark cursor-pointer">
                @if($hasQuestionnaireFile)
                    <p class="mt-1 text-xs text-empower-muted">A file is already on record — leave blank to keep it.</p>
                @endif
                @error('questionnaireFile') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Manual Template (.docx)</label>
                <input wire:model="manualTemplateFile" type="file" accept=".docx" wire:loading.attr="disabled" wire:target="manualTemplateFile,prefix"
                    class="block w-full text-sm text-empower-text file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-navy file:text-white hover:file:bg-navy-dark cursor-pointer">
                <p class="mt-1 text-xs text-empower-muted">Uploading (or replacing) this automatically detects its merge fields below — nothing is saved until you click {{ $questionnaireId ? 'Save Changes' : 'Create Questionnaire' }}.</p>
                @if($hasManualTemplateFile)
                    <p class="mt-1 text-xs text-empower-muted">A template is already on record — leave blank to keep it and its current schema.</p>
                @endif
                <span wire:loading wire:target="manualTemplateFile,prefix" class="mt-1 inline-flex items-center gap-1.5 text-xs text-empower-muted"><x-spinner class="h-3 w-3" /> Detecting merge fields&hellip;</span>
                @error('manualTemplateFile') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @if($pendingSchema)
                <div class="sm:col-span-2 rounded-xl border border-empower-border bg-page p-4 space-y-3">
                    <p class="text-sm font-semibold text-navy">Detected schema</p>

                    @if($questionnaireId && $hasUploadHistory)
                        <div class="rounded-lg bg-[#fff3cd] text-[#9a6700] text-xs px-3 py-2">
                            This questionnaire already has submissions or generated documents. Regenerating its
                            schema won't remap their already-extracted answers to any new field names — only
                            <strong>new</strong> submissions will use this updated schema.
                        </div>
                    @endif

                    <p class="text-xs text-empower-text">
                        {{ $pendingSchema['count'] }} numbered question{{ $pendingSchema['count'] === 1 ? '' : 's' }}
                        detected using prefix "<span class="font-mono">{{ $pendingSchema['prefix'] }}</span>".
                    </p>

                    @if(count($pendingSchema['extra_fields']))
                        <div class="space-y-2">
                            <p class="text-xs font-semibold text-[#173a59]">Other fields — review/edit each description:</p>
                            @foreach(array_keys($pendingSchema['extra_fields']) as $key)
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-start">
                                    <span class="text-xs font-mono text-empower-muted pt-2.5 truncate" title="{{ $key }}">{{ $key }}</span>
                                    <input wire:model="pendingSchema.extra_fields.{{ $key }}" type="text"
                                        class="sm:col-span-2 w-full rounded-lg border border-empower-border bg-white px-3 py-2 text-xs text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-empower-muted">No other fields detected beyond the numbered questions.</p>
                    @endif
                </div>
            @endif
        </div>

        <div class="mt-5 flex justify-end">
            <button wire:click="save" wire:target="save"
                class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $questionnaireId ? 'Save Changes' : 'Create Questionnaire' }} &rarr;</span>
                <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving…</span>
            </button>
        </div>
    </div>
</div>
