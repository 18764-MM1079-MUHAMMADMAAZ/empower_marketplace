<?php

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\Package;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?int $leadId = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $practiceName = '';

    public ?int $billableProviders = null;

    public string $topic = '';

    public string $message = '';

    public string $packageInterest = '';

    public string $adminNotes = '';

    /** @return array<string, string> */
    #[Computed]
    public function topics(): array
    {
        return [
            'general' => 'General question',
            'package' => 'Help choosing a package',
            'quote' => 'Complete tier — custom quote',
            'legal' => 'Legal Review & Risk Assessment add-on',
        ];
    }

    #[Computed]
    public function packages()
    {
        return Package::where('is_active', true)->orderBy('sort_order')->get();
    }

    public function mount(?Lead $lead = null): void
    {
        if (! $lead) {
            return;
        }

        $this->leadId = $lead->id;
        $this->name = $lead->name;
        $this->email = $lead->email;
        $this->phone = $lead->phone ?? '';
        $this->practiceName = $lead->practice_name ?? '';
        $this->billableProviders = $lead->billable_providers;
        $this->topic = $lead->topic ?? '';
        $this->message = $lead->message ?? '';
        $this->packageInterest = $lead->package_interest ?? '';
        $this->adminNotes = $lead->admin_notes ?? '';
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:150|regex:/^[\p{L}\s.\'-]+$/u',
            'email' => 'required|email:rfc,filter|max:255',
            'phone' => 'nullable|regex:/^\+?[1-9]\d{7,14}$/',
            'message' => 'nullable|string|max:2000|not_regex:/[<>]/',
            'practiceName' => 'nullable|string|max:150|not_regex:/[<>]/',
            'billableProviders' => 'nullable|integer|min:1|max:100000',
            'topic' => 'nullable|in:general,package,quote,legal',
            'packageInterest' => 'nullable|string|max:150',
            'adminNotes' => 'nullable|string|max:2000',
        ], [
            'name.regex' => 'Please enter a valid name using letters only.',
            'message.not_regex' => 'Please remove the < and > characters.',
            'practiceName.not_regex' => 'Please remove the < and > characters.',
            'phone.regex' => 'Please enter a valid international phone number, digits only (e.g. +15551234567).',
        ]);

        $data = [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone ?: null,
            'message' => $this->message ?: null,
            'practice_name' => $this->practiceName ?: null,
            'billable_providers' => $this->billableProviders,
            'topic' => $this->topic ?: null,
            'package_interest' => $this->packageInterest ?: null,
            'admin_notes' => $this->adminNotes ?: null,
        ];

        if ($this->leadId) {
            $lead = Lead::findOrFail($this->leadId);
            $lead->update($data);

            ActivityLog::record('lead.updated', "{$lead->name} was updated.", user: auth()->user(), subject: $lead);
            session()->flash('toast', "{$lead->name} updated.");
        } else {
            $lead = Lead::create($data + ['source' => 'manual']);

            ActivityLog::record('lead.created', "{$lead->name} was created.", user: auth()->user(), subject: $lead);
            session()->flash('toast', "{$lead->name} created.");
        }

        $this->redirect(route('admin.leads'), navigate: true);
    }
};
?>

<div class="space-y-4">
    <a href="{{ route('admin.leads') }}" wire:navigate class="text-sm font-semibold text-[#0b9ed0] hover:underline">&larr; Back to leads</a>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5">
        <h2 class="text-lg font-semibold text-navy mb-4">{{ $leadId ? 'Edit Lead' : 'New Lead' }}</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Name</label>
                <input wire:model="name" type="text" required maxlength="150"
                    pattern="[\p{L}\s.'\-]+" title="Letters, spaces, periods, apostrophes and hyphens only"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Email</label>
                <input wire:model="email" type="email" required maxlength="255"
                    pattern="[^\s@]+@[^\s@]+\.[^\s@]+" title="Please include a domain extension, e.g. name@example.com"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Practice name</label>
                <input wire:model="practiceName" type="text" maxlength="150"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('practiceName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Billable providers</label>
                <input wire:model="billableProviders" type="number" min="1" max="100000"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('billableProviders') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Topic</label>
                <select wire:model="topic"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    <option value="">Not specified</option>
                    @foreach($this->topics as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('topic') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Phone</label>
                <input wire:model="phone" type="tel" inputmode="tel" placeholder="+15551234567" maxlength="16"
                    pattern="[+]?[1-9][0-9]{7,14}" title="A valid international phone number, e.g. +15551234567"
                    x-on:input="$el.value = $el.value.replace(/[^0-9+]/g, '')"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Package Interest</label>
                <select wire:model="packageInterest"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition">
                    <option value="">Select a package…</option>
                    @if($packageInterest !== '' && ! $this->packages->contains('slug', $packageInterest))
                        <option value="{{ $packageInterest }}">{{ $packageInterest }}</option>
                    @endif
                    @foreach($this->packages as $package)
                        <option value="{{ $package->slug }}">{{ $package->name }}</option>
                    @endforeach
                </select>
                @error('packageInterest') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Message</label>
                <textarea wire:model="message" rows="3"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition resize-none"></textarea>
                @error('message') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Admin Notes</label>
                <textarea wire:model="adminNotes" rows="3" placeholder="Internal notes, not visible to the lead"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition resize-none"></textarea>
                @error('adminNotes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-5 flex justify-end">
            <button wire:click="save" wire:target="save"
                class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $leadId ? 'Save Changes' : 'Create Lead' }} &rarr;</span>
                <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Saving…</span>
            </button>
        </div>
    </div>
</div>
