<?php

use App\Enums\UserRole;
use App\Mail\DiscountCodeSharedMail;
use App\Models\ActivityLog;
use App\Models\DiscountCode;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public DiscountCode $discountCode;

    public string $userSearch = '';

    public string $leadSearch = '';

    /** @var array<int, int> */
    public array $selectedUserIds = [];

    /** @var array<int, int> */
    public array $selectedLeadIds = [];

    public string $additionalEmails = '';

    public bool $sent = false;

    public int $lastSentCount = 0;

    public function mount(DiscountCode $discountCode): void
    {
        $this->discountCode = $discountCode;
    }

    #[Computed]
    public function filteredUsers(): Collection
    {
        return User::where('role', UserRole::Client)
            ->when($this->userSearch, fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->userSearch}%")
                ->orWhere('email', 'like', "%{$this->userSearch}%")))
            ->orderBy('name')
            ->limit(25)
            ->get();
    }

    #[Computed]
    public function filteredLeads(): Collection
    {
        return Lead::when($this->leadSearch, fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->leadSearch}%")
                ->orWhere('email', 'like', "%{$this->leadSearch}%")))
            ->orderBy('name')
            ->limit(25)
            ->get();
    }

    /**
     * @return array<string, array{email: string, name: ?string}>
     */
    private function resolveRecipients(): array
    {
        $recipients = [];

        foreach (User::whereIn('id', $this->selectedUserIds)->get() as $user) {
            $recipients[strtolower($user->email)] = ['email' => $user->email, 'name' => $user->name];
        }

        foreach (Lead::whereIn('id', $this->selectedLeadIds)->get() as $lead) {
            $recipients[strtolower($lead->email)] = ['email' => $lead->email, 'name' => $lead->name];
        }

        $additional = collect(preg_split('/[,\n]+/', $this->additionalEmails))
            ->map(fn ($email) => trim($email))
            ->filter();

        foreach ($additional as $email) {
            $recipients[strtolower($email)] ??= ['email' => $email, 'name' => null];
        }

        return $recipients;
    }

    public function send(): void
    {
        $this->validate([
            'additionalEmails' => 'nullable|string',
        ]);

        $additionalEmails = collect(preg_split('/[,\n]+/', $this->additionalEmails))
            ->map(fn ($email) => trim($email))
            ->filter()
            ->values();

        if ($additionalEmails->isNotEmpty()) {
            $validator = Validator::make(
                ['additionalEmails' => $additionalEmails->all()],
                ['additionalEmails.*' => 'email:rfc,filter'],
            );

            if ($validator->fails()) {
                $this->addError('additionalEmails', 'Please enter only valid email addresses, separated by commas or new lines.');
                $this->dispatch('toast', message: 'Please enter valid email addresses.', type: 'error');

                return;
            }
        }

        $recipients = $this->resolveRecipients();

        if (empty($recipients)) {
            $this->addError('recipients', 'Please select or enter at least one recipient.');
            $this->dispatch('toast', message: 'Select or enter at least one recipient.', type: 'error');

            return;
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient['email'])->send(new DiscountCodeSharedMail($this->discountCode, $recipient['name']));
        }

        ActivityLog::record(
            'discount_code.shared',
            "{$this->discountCode->code} was emailed to ".count($recipients).' recipient(s).',
            user: auth()->user(),
            subject: $this->discountCode,
        );

        $this->lastSentCount = count($recipients);
        $this->sent = true;
        $this->selectedUserIds = [];
        $this->selectedLeadIds = [];
        $this->additionalEmails = '';

        $this->dispatch('toast', message: "{$this->discountCode->code} emailed to {$this->lastSentCount} recipient(s).", type: 'success');
    }
};
?>

<div class="space-y-4">
    <a href="{{ route('admin.discount-codes') }}" wire:navigate class="text-sm font-semibold text-[#0b9ed0] hover:underline">&larr; Back to discount codes</a>

    <div class="bg-white border border-empower-border rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-5 space-y-4">
        <h2 class="text-lg font-semibold text-navy">Send <span class="font-mono">{{ $discountCode->code }}</span></h2>

        @if($sent)
            <div class="flex items-center gap-3 rounded-xl bg-[#eef8f3] border border-[#bfe3d2] px-4 py-3.5">
                <span class="text-[#117a51]">&#10003;</span>
                <p class="text-sm font-semibold text-[#0f7a4f]">Emailed to {{ $lastSentCount }} recipient(s).</p>
            </div>
        @endif

        @error('recipients') <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Existing Clients</label>
                <input wire:model.live="userSearch" type="text" placeholder="Search by name or email…"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition mb-2">
                <div class="border border-empower-border rounded-xl max-h-56 overflow-y-auto divide-y divide-empower-border">
                    @forelse($this->filteredUsers as $user)
                        <label class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-page">
                            <input type="checkbox" wire:model="selectedUserIds" value="{{ $user->id }}" class="rounded border-empower-border text-navy focus:ring-accent">
                            <span>{{ $user->name }} <span class="text-empower-muted">({{ $user->email }})</span></span>
                        </label>
                    @empty
                        <p class="px-3 py-4 text-xs text-empower-muted italic">No clients found.</p>
                    @endforelse
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Leads</label>
                <input wire:model.live="leadSearch" type="text" placeholder="Search by name or email…"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition mb-2">
                <div class="border border-empower-border rounded-xl max-h-56 overflow-y-auto divide-y divide-empower-border">
                    @forelse($this->filteredLeads as $lead)
                        <label class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-page">
                            <input type="checkbox" wire:model="selectedLeadIds" value="{{ $lead->id }}" class="rounded border-empower-border text-navy focus:ring-accent">
                            <span>{{ $lead->name }} <span class="text-empower-muted">({{ $lead->email }})</span></span>
                        </label>
                    @empty
                        <p class="px-3 py-4 text-xs text-empower-muted italic">No leads found.</p>
                    @endforelse
                </div>
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-[#173a59] mb-1.5">Additional Email Addresses</label>
                <textarea wire:model="additionalEmails" rows="3" placeholder="jane@practice.com, john@practice.com"
                    class="w-full rounded-xl border border-empower-border bg-page px-4 py-2.5 text-sm text-empower-text focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition"></textarea>
                <p class="mt-1 text-xs text-empower-muted">Separate multiple addresses with a comma or a new line.</p>
                @error('additionalEmails') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex justify-end">
            <button wire:click="send" wire:target="send"
                class="inline-flex items-center gap-1 rounded-lg bg-[#2299dd] px-5 py-2 text-sm font-bold text-white hover:bg-[#087fa9] transition-colors"
                wire:loading.attr="disabled" wire:loading.class="opacity-70 cursor-not-allowed" wire:target="send">
                <span wire:loading.remove wire:target="send">Send &rarr;</span>
                <span wire:loading.inline-flex wire:target="send" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Sending…</span>
            </button>
        </div>
    </div>
</div>
