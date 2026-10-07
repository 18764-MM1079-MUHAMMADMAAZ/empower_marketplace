<?php

use App\Enums\UserRole;
use App\Mail\LeadConfirmationMail;
use App\Mail\NewLeadNotificationMail;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    /** Topic key => [dialog title, dialog subtitle], matching the reference mockup. */
    private const TOPICS = [
        'general' => ['General question', 'Contact the Empower team', "Tell us about your practice and we'll follow up within one business day."],
        'package' => ['Help choosing a package', 'Find the right package', "Share a few details and we'll recommend a tier."],
        'quote' => ['Complete tier — custom quote', 'Request a Complete tier quote', 'Complete is scoped to your practice. Tell us what you need.'],
        'legal' => ['Legal Review & Risk Assessment add-on', 'Legal Review & Risk Assessment', "We'll connect you with independent counsel to scope the add-on."],
    ];

    public bool $open = false;

    public bool $submitted = false;

    #[Validate('required|in:general,package,quote,legal')]
    public string $topic = 'general';

    #[Validate('required|string|max:100|regex:/^[\p{L}\s.\'-]+$/u')]
    public string $name = '';

    #[Validate('required|email:rfc,filter|max:150')]
    public string $email = '';

    #[Validate('nullable|string|max:150|not_regex:/[<>]/')]
    public string $practiceName = '';

    #[Validate('nullable|integer|min:1|max:100000')]
    public ?int $billableProviders = null;

    #[Validate('nullable|string|max:2000|not_regex:/[<>]/')]
    public string $message = '';

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Required',
            'name.regex' => 'Please enter a valid name using letters only.',
            'email.required' => 'Required',
            'email.email' => 'Enter a valid email',
            'practiceName.not_regex' => 'Please remove the < and > characters.',
            'message.not_regex' => 'Please remove the < and > characters.',
        ];
    }

    /** Trims and strips tags/control characters from free text before it is stored or emailed.
     *  Eloquent already binds every value as a query parameter (no SQL injection) and Blade/mail
     *  templates escape on output; this is the defence-in-depth layer. */
    private function clean(?string $value): string
    {
        $value = strip_tags((string) $value);

        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
    }

    /** @return array<string, string> topic key => option label */
    public function topicOptions(): array
    {
        return array_map(fn (array $copy) => $copy[0], self::TOPICS);
    }

    /** @return array{0: string, 1: string, 2: string} */
    public function copy(): array
    {
        return self::TOPICS[$this->topic] ?? self::TOPICS['general'];
    }

    public function mount(): void
    {
        $requested = request()->query('contact');

        if ($requested !== null) {
            $this->openWith(array_key_exists($requested, self::TOPICS) ? $requested : 'general');
        }
    }

    public function openWith(string $topic = 'general'): void
    {
        $this->resetErrorBag();
        $this->submitted = false;
        $this->topic = array_key_exists($topic, self::TOPICS) ? $topic : 'general';
        $this->message = '';
        $this->billableProviders = 1;

        $user = auth()->user();
        $this->name = $user?->name ?? '';
        $this->email = $user?->email ?? '';
        $this->practiceName = $user?->practice?->name ?? '';

        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function submit(): void
    {
        $throttleKey = 'contact-dialog:'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->addError('email', 'Too many requests. Please try again in a few minutes.');

            return;
        }

        $this->validate();

        RateLimiter::hit($throttleKey, 600);

        $practiceName = $this->clean($this->practiceName);
        $message = $this->clean($this->message);

        $lead = Lead::create([
            'name' => $this->clean($this->name),
            'email' => $this->email,
            'topic' => $this->topic,
            'source' => 'contact_form',
            'practice_name' => $practiceName !== '' ? $practiceName : null,
            'billable_providers' => $this->billableProviders,
            'message' => $message !== '' ? $message : null,
            'package_interest' => $this->topic === 'quote' ? 'complete' : null,
        ]);

        try {
            Mail::to($lead->email)->send(new LeadConfirmationMail($lead));
        } catch (\Throwable $e) {
            report($e);
        }

        User::where('role', UserRole::Admin)->pluck('email')->each(
            function (string $adminEmail) use ($lead) {
                try {
                    Mail::to($adminEmail)->send(new NewLeadNotificationMail($lead));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        );

        $this->submitted = true;
    }
};
?>

<div x-on:open-contact.window="$wire.openWith($event.detail?.topic ?? 'general')">
    <div x-show="$wire.open" x-cloak x-on:keydown.escape.window="$wire.close()"
        class="fixed inset-0 z-[70] flex items-center justify-center bg-black/60 backdrop-blur-sm px-4 py-6" role="dialog"
        aria-modal="true" aria-labelledby="contact-dialog-title">
        <div class="relative w-full max-w-xl bg-white rounded-2xl shadow-xl overflow-hidden max-h-[92vh] overflow-y-auto"
            x-on:click.outside="$wire.close()">
            <div class="flex items-start justify-between px-6 pt-6 pb-4 border-b border-[#eef2f6]">
                <div>
                    <h3 id="contact-dialog-title" class="text-xl font-extrabold text-[#0e1b30]">
                        {{ $submitted ? 'Message sent' : $this->copy()[1] }}</h3>
                    @unless($submitted)
                    <p class="text-sm text-[#5d6e7f] mt-1">{{ $this->copy()[2] }}</p>
                    @endunless
                </div>
                <button type="button" wire:click="close" aria-label="Close"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-[#dbe4ee] text-[#5c778d] hover:text-[#0e3a61] transition-colors">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @if($submitted)
            <div class="px-6 py-10 text-center">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-[#e6f6ef] text-[#1f9d6b]">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </div>
                <h4 class="text-base font-bold text-[#173045] mb-1">Thanks, {{ \Illuminate\Support\Str::of($name)->before(' ') }}!</h4>
                <p class="text-sm text-[#5d6e7f]">Your message is in. A member of the Empower team will follow up at
                    <strong>{{ $email }}</strong> within one business day.</p>
            </div>
            <div class="flex items-center justify-end bg-[#f5f7fa] border-t border-[#eef2f6] px-6 py-4">
                <button type="button" wire:click="close"
                    class="rounded-full bg-[#3a9bd5] px-6 py-2 text-sm font-semibold text-white hover:bg-[#2b82b8] transition-colors">Done</button>
            </div>
            @else
            <form wire:submit="submit" novalidate>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-[#31465b] mb-1.5" for="cd-topic">Topic</label>
                        <select wire:model.live="topic" id="cd-topic"
                            class="w-full rounded-xl border border-[#c9d6e3] bg-white px-3.5 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#3a9bd5] focus:border-transparent">
                            @foreach($this->topicOptions() as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-[#31465b] mb-1.5" for="cd-name">Full name</label>
                            <input wire:model="name" id="cd-name" type="text" autocomplete="name"
                                class="w-full rounded-xl border {{ $errors->has('name') ? 'border-red-500' : 'border-[#c9d6e3]' }} bg-white px-3.5 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#3a9bd5] focus:border-transparent">
                            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-[#31465b] mb-1.5" for="cd-email">Work email</label>
                            <input wire:model="email" id="cd-email" type="email" autocomplete="email"
                                class="w-full rounded-xl border {{ $errors->has('email') ? 'border-red-500' : 'border-[#c9d6e3]' }} bg-white px-3.5 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#3a9bd5] focus:border-transparent">
                            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-[#31465b] mb-1.5" for="cd-practice">Practice name</label>
                            <input wire:model="practiceName" id="cd-practice" type="text" autocomplete="organization"
                                class="w-full rounded-xl border border-[#c9d6e3] bg-white px-3.5 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#3a9bd5] focus:border-transparent">
                            @error('practiceName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-[#31465b] mb-1.5" for="cd-providers">Billable providers</label>
                            <input wire:model="billableProviders" id="cd-providers" type="number" min="1"
                                class="w-full rounded-xl border border-[#c9d6e3] bg-white px-3.5 py-2.5 text-sm text-[#173045] focus:outline-none focus:ring-2 focus:ring-[#3a9bd5] focus:border-transparent">
                            @error('billableProviders') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-[#31465b] mb-1.5" for="cd-message">How can we help?</label>
                        <textarea wire:model="message" id="cd-message" rows="4"
                            placeholder="Current program status, deadlines, payor requirements…"
                            class="w-full rounded-xl border border-[#c9d6e3] bg-white px-3.5 py-2.5 text-sm text-[#173045] placeholder-[#8598ab] focus:outline-none focus:ring-2 focus:ring-[#3a9bd5] focus:border-transparent"></textarea>
                        @error('message') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 bg-[#f5f7fa] border-t border-[#eef2f6] px-6 py-4">
                    <button type="button" wire:click="close"
                        class="rounded-full border border-[#dbe4ee] bg-white px-5 py-2 text-sm font-semibold text-[#173045] hover:bg-[#f5f7fa] transition-colors">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                        class="rounded-full bg-[#3a9bd5] px-6 py-2 text-sm font-semibold text-white hover:bg-[#2b82b8] transition-colors disabled:opacity-70">
                        <span wire:loading.remove wire:target="submit">Send message</span>
                        <span wire:loading.inline-flex wire:target="submit" class="inline-flex items-center gap-1.5"><x-spinner class="h-3.5 w-3.5" /> Sending…</span>
                    </button>
                </div>
            </form>
            @endif
        </div>
    </div>
</div>
