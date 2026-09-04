@php $active ??= null; @endphp
<div class="flex flex-wrap items-center gap-2 mb-10">
    @foreach([
    'dashboard' => ['admin.dashboard', 'Dashboard'],
    'submissions' => ['admin.submissions', 'Submissions'],
    'documents' => ['admin.documents', 'Documents'],
    'packages' => ['admin.packages', 'Packages'],
    'discount-codes' => ['admin.discount-codes', 'Discount Codes'],
    'questionnaire-settings' => ['admin.questionnaire-settings', 'Questionnaires'],
    'leads' => ['admin.leads', 'Leads'],
    'users' => ['admin.users', 'Users'],
    'orders' => ['admin.orders', 'Orders'],
    'payment-logs' => ['admin.payment-logs', 'Payment Logs'],
    'activity-log' => ['admin.activity-log', 'Activity Log'],
    ] as $key => [$route, $label])
    <a href="{{ route($route) }}" wire:navigate
        class="rounded-lg px-3.5 py-1.5 text-sm font-semibold transition-colors {{ $active === $key ? 'bg-navy text-white' : 'text-empower-muted hover:bg-page' }}">
        {{ $label }}
    </a>
    @endforeach
</div>