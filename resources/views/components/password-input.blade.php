@props(['generate' => false])
{{--
    A password field with an eye button to show / hide what's typed. Every attribute (wire:model, id,
    autocomplete, class, placeholder…) goes onto the <input>. The visibility lives in Alpine and is
    kept across Livewire re-renders (morph clones Alpine state before patching attributes).

    :generate="true" adds a "Generate strong password" button: it fills the field with 16 random
    characters that meet Password::defaults() (upper, lower, number, symbol), reveals it and copies
    it to the clipboard. Look-alike characters (0/O, 1/l/I) are left out so it can be read out.
--}}
<div x-data="{
        show: false,
        generatePassword() {
            let sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*-_=+?']
            let all = sets.join('')
            let random = (max) => {
                let limit = Math.floor(4294967296 / max) * max, buf = new Uint32Array(1)
                do { crypto.getRandomValues(buf) } while (buf[0] >= limit)
                return buf[0] % max
            }
            let chars = sets.map(set => set[random(set.length)])
            while (chars.length < 16) chars.push(all[random(all.length)])
            for (let i = chars.length - 1; i > 0; i--) {
                let j = random(i + 1);
                [chars[i], chars[j]] = [chars[j], chars[i]]
            }
            let password = chars.join('')

            this.$refs.input.value = password
            this.$refs.input.dispatchEvent(new Event('input', { bubbles: true }))
            this.show = true

            let done = (copied) => this.$dispatch('toast', {
                message: copied ? 'Strong password generated and copied to the clipboard.' : 'Strong password generated.',
                type: 'success',
            })
            navigator.clipboard ? navigator.clipboard.writeText(password).then(() => done(true), () => done(false)) : done(false)
        },
    }" class="relative">
    <input type="password" :type="show ? 'text' : 'password'" x-ref="input"
        {{ $attributes->merge(['style' => $generate ? 'padding-right: 4.75rem' : 'padding-right: 2.75rem']) }}>
    <div class="absolute inset-y-0 right-0 flex items-center pr-1.5">
        <button type="button" x-on:click="show = ! show"
            :aria-label="show ? 'Hide password' : 'Show password'" aria-label="Show password"
            :title="show ? 'Hide password' : 'Show password'" title="Show password"
            :aria-pressed="show.toString()" aria-pressed="false"
            class="flex h-8 w-8 items-center justify-center rounded-lg text-[#5c778d] hover:bg-[#eef5fa] hover:text-[#0e3a61] focus:outline-none focus-visible:text-[#0b9ed0] transition-colors">
            {{-- eye --}}
            <svg x-show="! show" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            {{-- eye with a slash --}}
            <svg x-show="show" x-cloak class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
            </svg>
        </button>
        @if($generate)
        <button type="button" x-on:click="generatePassword()" title="Generate a strong password"
            aria-label="Generate a strong password" data-generate-password
            class="flex h-8 w-8 items-center justify-center rounded-lg text-[#5c778d] hover:bg-[#eef5fa] hover:text-[#0e3a61] focus:outline-none focus-visible:text-[#0b9ed0] transition-colors">
            {{-- key --}}
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
            </svg>
        </button>
        @endif
    </div>
</div>
