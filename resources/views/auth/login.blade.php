<x-layouts.auth title="Sign In" hero-title="Welcome back"
    hero-subtitle="Log in to manage your packages and documents, or sign up to get started.">
    <div
        class="bg-white border border-[#d4e5f1] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">

        <div class="flex border-b border-[#d4e5f1] px-5 pt-4">
            <span
                class="pb-3 px-4 text-sm font-semibold text-[#0e3a61] border-b-2 border-[#0e3a61] -mb-px cursor-default">Login</span>
            <a href="{{ route('register') }}"
                class="pb-3 px-4 text-sm font-medium text-[#0b9ed0] border-b-2 border-transparent -mb-px hover:text-[#0e3a61] transition-colors">Sign
                Up</a>
        </div>

        <div class="px-5 py-5">
            {{-- <h3 class="text-lg font-semibold text-[#173a59] mb-1">Log In</h3>
            <p class="text-xs text-[#5c778d] mb-5">Enter your credentials to access your portal.</p> --}}
            <livewire:auth.login-form />
        </div>
    </div>

    @if(session('status'))
    <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 4000)" x-show="show" x-transition x-cloak
        class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[100]">
        <div
            class="flex items-center gap-2 rounded-xl bg-[#0e3a61] text-white pl-4 pr-5 py-3 shadow-[0_18px_50px_rgba(10,32,55,0.25)]">
            <span class="text-[#8ddaf2] font-bold">&#9432;</span>
            <span class="text-sm font-semibold">{{ session('status') }}</span>
        </div>
    </div>
    @endif
</x-layouts.auth>