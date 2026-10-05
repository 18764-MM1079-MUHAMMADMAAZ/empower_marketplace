<x-layouts.auth
    title="Create Account"
    hero-title="Get started"
    hero-subtitle="Create an account, then head over to Packages to purchase your first plan."
>
    <div class="bg-white border border-[#d4e5f1] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] overflow-hidden">

        <div class="flex border-b border-[#d4e5f1] px-5 pt-4">
            <a href="{{ route('login') }}" class="pb-3 px-4 text-sm font-medium text-[#0b9ed0] border-b-2 border-transparent -mb-px hover:text-[#0e3a61] transition-colors">Login</a>
            <span class="pb-3 px-4 text-sm font-semibold text-[#0e3a61] border-b-2 border-[#0e3a61] -mb-px cursor-default">Sign Up</span>
        </div>

        <div class="px-5 py-5">
            <h3 class="text-lg font-semibold text-[#173a59] mb-1">Sign Up</h3>
            <p class="text-xs text-[#5c778d] mb-5">Create the account that will manage your practice's Empower
                portal. You'll set it up as part of checkout.</p>
            <livewire:auth.register-form :package="request()->query('package')" />
        </div>
    </div>
</x-layouts.auth>
