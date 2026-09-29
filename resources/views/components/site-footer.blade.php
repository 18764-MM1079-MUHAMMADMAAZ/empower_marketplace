@props(['footerClass' => 'py-6'])

<footer class="bg-white border-t border-[#d4e5f1] {{ $footerClass }}">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <p class="text-[0.65rem] leading-relaxed text-[#8598ab] max-w-7xl mx-auto">
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
            <span class="inline-flex items-center rounded-lg bg-[#f9fcff] px-2.5 py-1.5">
                <img src="{{ asset('images/logo.webp') }}" alt="Empower" class="h-[28px] sm:h-[45px] w-auto"
                    onerror="this.parentElement.innerHTML='<span class=\'font-bold text-[#0e3a61] text-sm\'>EMPOWER</span>'">
            </span>
            <p class="text-xs text-[#5c778d] text-center">&copy; 2026 CareCloud, Inc. &middot; Empower Healthcare &amp;
                Compliance Inc. &middot; carecloud.com &middot; empowerhci.com</p>
        </div>
    </div>
</footer>