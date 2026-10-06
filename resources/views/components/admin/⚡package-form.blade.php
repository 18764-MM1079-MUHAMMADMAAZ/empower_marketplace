<?php

use App\Enums\DocumentType;
use App\Enums\PackageTier;
use App\Models\ActivityLog;
use App\Models\Package;
use Livewire\Component;

new class extends Component
{
    /** The only document types safe to auto-generate on submission approval today — every one
     *  is either policy-driven (merging from the Practice Intake wizard's answers) or AI-
     *  synthesized from the practice's own data (see GenerateComplianceDocument). Other
     *  DocumentType values either need an uploaded file (per-upload/per-location types) or
     *  haven't been migrated off the old questionnaire-upload mechanism, so they're
     *  deliberately not offered here. */
    public const AUTO_GENERATED_DOCUMENT_TYPES = [
        DocumentType::ComplianceEthicsManual,
        DocumentType::HipaaPrivacyPolicy,
        DocumentType::HipaaSecurityManual,
        DocumentType::SecurityRiskAssessment,
        DocumentType::CodingMiniAuditReport,
        DocumentType::ExclusionsScreeningReport,
        DocumentType::TrainingPlanLmsEnrollment,
    ];

    public ?int $packageId = null;

    public string $slug = '';

    public string $name = '';

    public string $tagline = '';

    public string $billingType = 'annual';

    public ?string $monthlyPrice = null;

    public ?string $annualPrice = null;

    public string $description = '';

    public string $featuresText = '';

    public bool $isActive = true;

    public int $sortOrder = 0;

    /** @var array<int, string> */
    public array $includedDocumentTypes = [];

    public function mount(?Package $package = null): void
    {
        if (! $package) {
            return;
        }

        $this->packageId = $package->id;
        $this->slug = $package->slug;
        $this->name = $package->name;
        $this->tagline = $package->tagline ?? '';
        $this->billingType = $package->billing_type;
        $this->monthlyPrice = $package->monthly_price !== null ? (string) $package->monthly_price : null;
        $this->annualPrice = $package->annual_price !== null ? (string) $package->annual_price : null;
        $this->description = $package->description ?? '';
        $this->featuresText = implode("\n", $package->features ?? []);
        $this->isActive = $package->is_active;
        $this->sortOrder = $package->sort_order;
        $this->includedDocumentTypes = $package->included_document_types ?? [];
    }

    /** Tiers not yet backing an existing package — the only valid slugs for a new package. */
    public function availableTiers(): array
    {
        $used = Package::pluck('slug')->all();

        return array_values(array_filter(PackageTier::cases(), fn ($tier) => ! in_array($tier->value, $used, true)));
    }

    public function save(): void
    {
        if (! $this->packageId && empty($this->availableTiers())) {
            $this->addError('slug', 'All compliance tiers already have a package. Edit an existing one instead.');
            $this->dispatch('toast', message: 'All compliance tiers already have a package.', type: 'error');

            return;
        }

        $rules = [
            'name' => 'required|string|max:150',
            'tagline' => 'nullable|string|max:200',
            'billingType' => 'required|in:monthly,annual,custom',
            'monthlyPrice' => 'nullable|numeric|min:0',
            'annualPrice' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:2000',
            'sortOrder' => 'required|integer|min:0|max:255',
            'includedDocumentTypes' => 'array',
            'includedDocumentTypes.*' => 'in:'.implode(',', array_map(fn ($type) => $type->value, self::AUTO_GENERATED_DOCUMENT_TYPES)),
        ];

        if (! $this->packageId) {
            $rules['slug'] = 'required|string|in:'.implode(',', array_map(fn ($tier) => $tier->value, $this->availableTiers()));
        }

        $this->validate($rules);

        $features = array_values(array_filter(array_map('trim', explode("\n", $this->featuresText))));

        $data = [
            'name' => $this->name,
            'tagline' => $this->tagline ?: null,
            'billing_type' => $this->billingType,
            'monthly_price' => $this->monthlyPrice !== null && $this->monthlyPrice !== '' ? $this->monthlyPrice : null,
            'annual_price' => $this->annualPrice !== null && $this->annualPrice !== '' ? $this->annualPrice : null,
            'description' => $this->description ?: null,
            'features' => $features,
            'is_active' => $this->isActive,
            'sort_order' => $this->sortOrder,
            'included_document_types' => $this->includedDocumentTypes,
        ];

        if ($this->packageId) {
            $package = Package::findOrFail($this->packageId);
            $package->update($data);

            ActivityLog::record('package.updated', "{$package->name} was updated.", user: auth()->user(), subject: $package);
            session()->flash('toast', "{$package->name} updated.");
        } else {
            $data['slug'] = $this->slug;
            $package = Package::create($data);

            ActivityLog::record('package.created', "{$package->name} was created.", user: auth()->user(), subject: $package);
            session()->flash('toast', "{$package->name} created.");
        }

        $this->redirect(route('admin.packages'), navigate: true);
    }
};
?>

<div class="space-y-4">
    <a href="{{ route('admin.packages') }}" wire:navigate class="text-sm font-semibold text-[#0b9ed0] hover:underline">&larr; Back to packages</a>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <h2 class="text-lg font-semibold text-navy mb-4">{{ $packageId ? 'Edit Package' : 'New Package' }}</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Tier / Slug</label>
                @if($packageId)
                    <input type="text" value="{{ $slug }}" disabled
                        class="w-full rounded-xl border border-empower-border bg-[#eef6fb] px-4 py-2.5 text-sm text-empower-muted cursor-not-allowed">
                    <p class="mt-1 text-xs text-empower-muted">The tier can't change once a package exists — it drives which compliance documents are generated.</p>
                @else
                    <select wire:model="slug"
                        class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                        <option value="">Select a tier…</option>
                        @foreach($this->availableTiers() as $tier)
                            <option value="{{ $tier->value }}">{{ $tier->label() }}</option>
                        @endforeach
                    </select>
                    @if(empty($this->availableTiers()))
                        <p class="mt-1 text-xs text-red-600">All compliance tiers already have a package.</p>
                    @endif
                    @error('slug') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @endif
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Name</label>
                <input wire:model="name" type="text"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Tagline</label>
                <input wire:model="tagline" type="text"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('tagline') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Billing Type</label>
                <select wire:model="billingType"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    <option value="monthly">Monthly</option>
                    <option value="annual">Annual</option>
                    <option value="custom">Custom quote</option>
                </select>
                @error('billingType') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Sort Order</label>
                <input wire:model="sortOrder" type="number" min="0"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('sortOrder') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Monthly Price ($)</label>
                <input wire:model="monthlyPrice" type="number" step="0.01" min="0" placeholder="Leave blank for custom quote"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('monthlyPrice') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Annual Price ($)</label>
                <input wire:model="annualPrice" type="number" step="0.01" min="0" placeholder="Leave blank for custom quote"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('annualPrice') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Description</label>
                <textarea wire:model="description" rows="3"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition resize-none"></textarea>
                @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Features (one per line)</label>
                <textarea wire:model="featuresText" rows="5" placeholder="Employee Handbook (Basic)&#10;OSHA Safety Plan"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition"></textarea>
                <p class="mt-1 text-xs text-empower-muted">Shown on the pricing page and in each client's dashboard.</p>
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Auto-Generated Manuals</label>
                <p class="text-xs text-empower-muted mb-2">Generated automatically once a submission on this package is approved, from the Practice Intake wizard's answers.</p>
                <div class="space-y-2">
                    @foreach(self::AUTO_GENERATED_DOCUMENT_TYPES as $type)
                        <label class="flex items-center gap-2">
                            <input wire:model="includedDocumentTypes" value="{{ $type->value }}" type="checkbox" class="rounded border-empower-border text-navy focus:ring-accent">
                            <span class="text-sm text-empower-text">{{ $type->label() }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="sm:col-span-2">
                <label class="inline-flex items-center gap-2">
                    <input wire:model="isActive" type="checkbox" class="rounded border-empower-border text-navy focus:ring-accent">
                    <span class="text-sm font-semibold text-[#173a59]">Active (visible on the pricing page)</span>
                </label>
            </div>
        </div>

        <div class="mt-5 flex justify-end">
            <button wire:click="save" wire:target="save"
                class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $packageId ? 'Save Changes' : 'Create Package' }} &rarr;</span>
                <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving…</span>
            </button>
        </div>
    </div>
</div>
