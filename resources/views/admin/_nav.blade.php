@php
$active ??= \Illuminate\Support\Str::before(\Illuminate\Support\Str::after(request()->route()?->getName() ?? '',
'admin.'), '.');
$mobile ??= false;
@endphp
<nav class="flex flex-col gap-1">
    @foreach([
    'dashboard' => ['admin.dashboard', 'Dashboard'],
    'submissions' => ['admin.submissions', 'Submissions'],
    'documents' => ['admin.documents', 'Documents'],
    'packages' => ['admin.packages', 'Packages'],
    'discount-codes' => ['admin.discount-codes', 'Discount Codes'],
    'intake-questions' => ['admin.intake-questions', 'Intake Questions'],
    'leads' => ['admin.leads', 'Leads'],
    'specialist-calls' => ['admin.specialist-calls', 'Specialist Calls'],
    'users' => ['admin.users', 'Users'],
    'orders' => ['admin.orders', 'Orders'],
    'payment-logs' => ['admin.payment-logs', 'Payment Logs'],
    'activity-log' => ['admin.activity-log', 'Activity Log'],
    // 'questionnaires' => ['admin.questionnaires', 'Questionnaires'],
    // 'document-generator' => ['admin.document-generator', 'Document Generator'],
    ] as $key => [$route, $label])
    <a href="{{ route($route) }}" wire:navigate @if($mobile) x-on:click="adminSidebarOpen = false" @endif
        class="rounded-lg px-3.5 py-2 text-sm font-semibold transition-colors {{ $active === $key ? 'bg-navy text-white' : 'text-empower-muted hover:bg-page' }}">
        {{ $label }}
    </a>
    @endforeach
</nav>