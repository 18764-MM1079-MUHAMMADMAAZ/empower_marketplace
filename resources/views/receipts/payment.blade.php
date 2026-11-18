@php
$receiptNumber = 'P-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT);
$cycle = $order?->billing_cycle ?? \App\Enums\BillingCycle::Annual;
$address = $payment->billing_address ?: ($order?->billing_address ?? []);
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt {{ $receiptNumber }}: {{ config('app.name') }}</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('images/favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .receipt-card { box-shadow: none !important; border: none !important; }
        }
    </style>
</head>

<body class="min-h-screen bg-page font-sans antialiased py-10 print:py-0">
    <div class="no-print mx-auto max-w-2xl px-4 mb-4 flex justify-between items-center">
        <a href="{{ route('account') }}#plan" class="text-sm font-semibold text-[#1a7aad] hover:underline">&larr; Back to My Account</a>
        <button onclick="window.print()"
            class="inline-flex items-center gap-2 rounded-lg bg-[#12304f] px-4 py-2 text-sm font-semibold text-white hover:bg-[#0a2037] transition-colors">
            Print / Save as PDF
        </button>
    </div>

    <div class="receipt-card mx-auto max-w-2xl bg-white border border-[#dbe4ee] rounded-[1.25rem] shadow-[0_18px_50px_rgba(10,32,55,0.08)] p-8 sm:p-10 print:p-0">
        <div class="flex items-start justify-between gap-4">
            <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name') }}" class="h-10 w-auto" onerror="this.style.display='none'">
            <div class="text-right">
                <p class="text-xl font-extrabold text-[#12304f]">Receipt</p>
                <p class="text-sm text-[#5d6e7f]">{{ $receiptNumber }}</p>
            </div>
        </div>

        <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 gap-6 text-sm">
            <div>
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Billed to</p>
                <p class="font-semibold text-[#173045]">{{ $address['name'] ?? $payment->user->name }}</p>
                @if($practice?->name)<p class="text-[#5d6e7f]">{{ $practice->name }}</p>@endif
                @if(! empty($address['address1']))
                <p class="text-[#5d6e7f]">{{ $address['address1'] }}<br>{{ trim(($address['city'] ?? '').', '.($address['state'] ?? '').' '.($address['zip'] ?? ''), ', ') }}</p>
                @endif
                <p class="text-[#5d6e7f]">{{ $payment->user->email }}</p>
            </div>
            <div class="sm:text-right">
                <p class="text-[10px] font-extrabold uppercase tracking-wide text-[#8592a1] mb-1">Payment</p>
                <p class="text-[#173045]">Paid {{ $payment->created_at->format('F j, Y') }}</p>
                @if($order?->card_last_four)<p class="text-[#5d6e7f]">{{ $order->card_brand?->label() ?? 'Card' }} ending {{ $order->card_last_four }}</p>@endif
                @if($payment->transaction_id)<p class="text-[#5d6e7f] break-all">Transaction {{ $payment->transaction_id }}</p>@endif
            </div>
        </div>

        <table class="mt-8 w-full border-collapse text-sm">
            <thead>
                <tr class="border-b-2 border-[#12304f] text-left">
                    <th class="py-2 font-bold text-[#173045]">Description</th>
                    <th class="py-2 font-bold text-[#173045] text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b border-[#dbe4ee]">
                    <td class="py-3 text-[#173045]">
                        {{ $payment->package?->name ?? $order?->package?->name ?? 'Empower compliance package' }} ({{ $cycle->label() }})
                        @if($payment->message)<span class="block text-xs text-[#5d6e7f]">{{ $payment->message }}</span>@endif
                    </td>
                    <td class="py-3 text-[#173045] text-right">${{ number_format((float) $payment->amount, 2) }}</td>
                </tr>
                <tr>
                    <td class="py-3 text-base font-bold text-[#12304f]">Total paid</td>
                    <td class="py-3 text-base font-bold text-[#12304f] text-right">${{ number_format((float) $payment->amount, 2) }}</td>
                </tr>
            </tbody>
        </table>

        <p class="mt-8 text-xs text-[#5d6e7f]">Questions about this receipt? Reply to any Empower email or contact us from the Marketplace.</p>
    </div>
</body>

</html>
