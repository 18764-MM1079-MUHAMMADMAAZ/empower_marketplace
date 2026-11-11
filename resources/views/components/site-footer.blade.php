@props(['footerClass' => 'py-6', 'containerClass' => 'max-w-7xl', 'contactLink' => false])

<footer class="bg-white border-t border-[#d4e5f1] {{ $footerClass }}">
    <div class="mx-auto {{ $containerClass }} px-4 sm:px-6 lg:px-8">
        <p class="text-[0.65rem] leading-relaxed text-[#8598ab] {{ $containerClass }} mx-auto">
            Empower Healthcare &amp; Compliance Inc. and CareCloud, Inc. are not a law firm, do not practice law, and do
            not provide legal advice; nothing on this site creates an attorney-client relationship. Participation in a
            Proactive Compliance package does not guarantee regulatory compliance and does not protect against audit,
            investigation, or enforcement action by any payor or government agency. Empower and this site are not
            affiliated with, endorsed by, or approved by CMS, OIG, DOJ, or any other government agency. The Legal Review
            &amp; Risk Assessment add-on is conducted at the direction of Frier Levitt or comparable independent
            counsel; attorney-client privilege is fact-specific and is not guaranteed. Photographs and figures are for
            illustrative purposes only. Package pricing, scope, and deliverables described on this site are subject to
            change and become binding only under a separate written services agreement.
        </p>
        <div class="mt-5 flex flex-col md:flex-row items-center justify-between gap-4 border-t border-[#d4e5f1] pt-4">
            <img src="{{ asset('images/empower-carecloud-logo.png') }}" alt="Empower | CareCloud"
                class="h-[34px] sm:h-[46px] w-auto">
            <p class="text-xs text-[#5c778d] text-center">&copy; 2026 CareCloud, Inc. &middot; Empower Healthcare &amp;
                Compliance Inc. &middot; carecloud.com &middot; empowerhci.com</p>
            <nav class="flex flex-wrap items-center justify-center gap-x-5 gap-y-1 text-xs font-semibold text-[#1a7aad]" aria-label="Legal and contact">
                <a href="https://carecloud.com/privacy-policy/" target="_blank" rel="noopener noreferrer" class="hover:underline">Privacy Policy</a>
                <a href="https://carecloud.app.box.com/v/ProactiveComplianceTerms" target="_blank" rel="noopener noreferrer" class="hover:underline">Terms of Use</a>
                @if($contactLink)
                <button type="button" onclick="window.dispatchEvent(new CustomEvent('open-contact', { detail: { topic: 'general' } }))"
                    class="hover:underline">Contact us</button>
                @endif
            </nav>
        </div>
    </div>
</footer>