<?php

use App\Enums\DocumentStatus;
use App\Models\ActivityLog;
use App\Models\GeneratedDocument;
use App\Models\Practice;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $editing = false;

    public string $legalName = '';

    public string $dbaName = '';

    public string $specialty = '';

    public string $mainPhone = '';

    public string $mainEmail = '';

    public string $address = '';

    #[Computed]
    public function practice(): Practice
    {
        return auth()->user()->practice;
    }

    public function edit(): void
    {
        $p = $this->practice;
        $this->legalName = (string) ($p->legal_practice_name ?: $p->name);
        $this->dbaName = (string) $p->dba_name;
        $this->specialty = (string) $p->specialty;
        $this->mainPhone = (string) ($p->main_phone ?: $p->phone);
        $this->mainEmail = (string) $p->main_email;
        $this->address = (string) $p->address;
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->validate([
            'legalName' => 'required|string|max:150',
            'dbaName' => 'nullable|string|max:150',
            'specialty' => ['required', 'string', 'max:100', Rule::in(array_unique([...Practice::SPECIALTIES, (string) $this->practice->specialty]))],
            'mainPhone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9()+.\-\s]{7,30}$/'],
            'mainEmail' => 'nullable|email:rfc|max:255',
            'address' => 'required|string|max:255',
        ], ['mainPhone.regex' => 'Enter a valid phone number.'], [
            'legalName' => 'legal name', 'dbaName' => 'DBA name', 'mainPhone' => 'main phone', 'mainEmail' => 'main email',
        ]);

        $practice = $this->practice;
        // Compared as shown on the page (legal name falls back to the practice name, main phone
        // to the partner's phone), so saving the form untouched doesn't count as a change.
        $shown = fn (Practice $p) => [
            'legal_practice_name' => $p->legal_practice_name ?: $p->name,
            'dba_name' => $p->dba_name,
            'specialty' => $p->specialty,
            'main_phone' => $p->main_phone ?: $p->phone,
            'main_email' => $p->main_email,
            'address' => $p->address,
        ];
        $before = $shown($practice);

        $practice->fill([
            'legal_practice_name' => trim($this->legalName),
            'dba_name' => trim($this->dbaName) ?: null,
            'specialty' => $this->specialty,
            'main_phone' => trim($this->mainPhone) ?: null,
            'main_email' => trim($this->mainEmail) ?: null,
            'address' => trim($this->address),
        ]);

        $changed = array_keys(array_diff_assoc(array_map('strval', $shown($practice)), array_map('strval', $before)));
        $practice->save();

        if ($changed !== []) {
            // Practice::booted() already does this for the address/specialty of a locked profile;
            // this covers the other printed fields too.
            GeneratedDocument::whereIn('order_id', $practice->user->orders()->pluck('id'))
                ->where('is_stale', false)
                ->where('status', DocumentStatus::Completed)
                ->update(['is_stale' => true, 'stale_reason' => 'practice_profile_updated']);

            ActivityLog::record('practice.updated', 'Practice details updated from My Account.', user: auth()->user(), subject: $practice);
        }

        $this->editing = false;
        unset($this->practice);
        $this->dispatch('account-updated');
        $this->dispatch('toast', message: $changed !== []
            ? 'Practice info saved. Regenerate your documents so they show the new details.'
            : 'No changes to save.', type: 'success');
    }
};
?>

@php
$p = $this->practice;
$otherLocations = collect($p->practice_locations ?? [])
    ->map(fn ($l) => trim((string) $l))
    ->filter()
    ->reject(fn ($l) => strcasecmp($l, trim((string) $p->address)) === 0)
    ->values();
$hotline = $p->uses_ehcp_hotline
    ? 'Empower hotline'.($p->hotline_poster_count ? ' · '.$p->hotline_poster_count.' '.Str::plural('poster', $p->hotline_poster_count) : '')
    : collect([$p->compliance_hotline_number, $p->compliance_hotline_email])->filter()->implode(' · ');
$contact = fn (?string $phone, ?string $email) => collect([$phone, $email])->filter()->implode(' · ');
$names = fn ($members) => collect($members ?? [])->map(fn ($m) => is_array($m) ? trim(($m['name'] ?? '').(! empty($m['title']) ? ' ('.$m['title'].')' : '')) : (string) $m)->filter()->implode(', ');

$roles = collect([
    ['HIPAA Privacy Officer', $p->hipaa_privacy_officer_name, $contact($p->hipaa_privacy_officer_phone, $p->hipaa_privacy_officer_email)],
    ['HIPAA Security Officer', $p->hipaa_security_officer_name, $contact($p->hipaa_security_officer_phone, $p->hipaa_security_officer_email)],
    ['Compliance Officer', $p->compliance_officer_name, $contact($p->compliance_officer_phone, $p->compliance_officer_email)],
    ['Release of Information Officer', $p->release_of_info_officer_name, $contact($p->release_of_info_officer_phone, $p->release_of_info_officer_email)],
    [$p->it_mode === 'vendor' ? 'IT company' : 'IT contact', $p->it_mode === 'vendor' ? ($p->it_vendor_name ?: $p->it_contact_name) : $p->it_contact_name, $contact($p->it_contact_phone, $p->it_contact_email)],
    ['Compliance committee', $p->committee_none ? 'None' : $names($p->compliance_committee_members), null],
    ['Owners with oversight', $names($p->compliance_governing_board_members), null],
])->filter(fn ($role) => filled($role[1]));

$rows = [
    ['Legal name', $p->legal_practice_name ?: $p->name],
    ['DBA', $p->dba_name],
    ['Specialty', $p->specialty],
    ['Main phone', $p->main_phone ?: $p->phone],
    ['Main email', $p->main_email],
    ['Address', $p->address],
    ['Other locations', $otherLocations->implode("\n")],
    ['Billable providers', $p->billable_providers_count],
    ['Compliance hotline', $hotline],
];
$inputClass = 'w-full rounded-lg border border-[#d4e5f1] bg-white px-3 py-2 text-sm text-[#173a59] focus:outline-none focus:ring-2 focus:ring-[#0b9ed0] focus:border-transparent';
@endphp

<section id="practice" data-account-section aria-labelledby="h-practice"
    class="scroll-mt-32 lg:scroll-mt-24 bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div>
            <h2 id="h-practice" class="text-lg font-semibold text-[#12304f]">Practice info</h2>
            <p class="text-sm text-[#5d6e7f] max-w-2xl">This information appears in your compliance manuals. Changing it flags those documents for regeneration.</p>
        </div>
        @unless($editing)
        <button type="button" wire:click="edit" data-edit-practice
            class="rounded-lg border border-[#dbe4ee] px-3.5 py-1.5 text-xs font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">Edit practice info</button>
        @endunless
    </div>

    @if($editing)
    <form wire:submit="save" class="grid gap-3 sm:grid-cols-2" novalidate>
        <div>
            <label for="pi-legal" class="block text-xs font-semibold text-[#31465b] mb-1">Legal name</label>
            <input id="pi-legal" wire:model="legalName" type="text" class="{{ $inputClass }}">
            @error('legalName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="pi-dba" class="block text-xs font-semibold text-[#31465b] mb-1">DBA <span class="font-normal text-[#8592a1]">(optional)</span></label>
            <input id="pi-dba" wire:model="dbaName" type="text" class="{{ $inputClass }}">
            @error('dbaName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="pi-specialty" class="block text-xs font-semibold text-[#31465b] mb-1">Specialty</label>
            <select id="pi-specialty" wire:model="specialty" class="{{ $inputClass }}">
                <option value="">Choose a specialty</option>
                @foreach(array_unique(array_filter([...\App\Models\Practice::SPECIALTIES, $p->specialty])) as $option)
                <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            @error('specialty') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="pi-phone" class="block text-xs font-semibold text-[#31465b] mb-1">Main phone</label>
            <input id="pi-phone" wire:model="mainPhone" type="tel" autocomplete="tel" class="{{ $inputClass }}">
            @error('mainPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="pi-email" class="block text-xs font-semibold text-[#31465b] mb-1">Main email</label>
            <input id="pi-email" wire:model="mainEmail" type="email" autocomplete="email" class="{{ $inputClass }}">
            @error('mainEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="pi-address" class="block text-xs font-semibold text-[#31465b] mb-1">Address</label>
            <input id="pi-address" wire:model="address" type="text" autocomplete="street-address" class="{{ $inputClass }}">
            @error('address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <p class="sm:col-span-2 text-xs text-[#5d6e7f]">To change your locations, provider count or compliance team, update your intake answers in My Portal.</p>
        <div class="sm:col-span-2 flex gap-2">
            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                class="rounded-lg bg-[#12304f] px-4 py-2 text-sm font-bold text-white hover:bg-[#0c233b] transition-colors">Save practice info</button>
            <button type="button" wire:click="cancel"
                class="rounded-lg border border-[#dbe4ee] px-4 py-2 text-sm font-semibold text-[#5d6e7f] hover:bg-[#f4f7fb] transition-colors">Cancel</button>
        </div>
    </form>
    @else
    <dl class="grid sm:grid-cols-2 gap-x-6 border-t border-[#eef2f6]">
        @foreach($rows as [$label, $value])
        <div class="flex justify-between gap-4 border-b border-[#eef2f6] py-2.5 text-sm">
            <dt class="text-[#5d6e7f] shrink-0">{{ $label }}</dt>
            <dd class="font-semibold text-[#173045] text-right whitespace-pre-line wrap-break-word min-w-0">{{ filled($value) ? $value : '—' }}</dd>
        </div>
        @endforeach
    </dl>
    @endif

    @if($roles->isNotEmpty())
    <h3 class="text-base font-bold text-[#12304f] mt-6 mb-3">Compliance roles</h3>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach($roles as [$role, $name, $details])
        <div class="rounded-xl border border-[#dbe4ee] bg-[#f8fbfd] px-4 py-3">
            <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1]">{{ $role }}</p>
            <p class="text-sm font-bold text-[#173045] mt-1">{{ $name }}</p>
            @if($details)<p class="text-xs text-[#5d6e7f] mt-0.5 wrap-break-word">{{ $details }}</p>@endif
        </div>
        @endforeach
    </div>
    @endif
</section>
