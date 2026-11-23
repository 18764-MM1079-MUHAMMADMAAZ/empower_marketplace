{{-- A read-only field grid for My Account (v5). $rows: [[label, value|null], ...]; empty values show "—". --}}
<dl class="mt-3 grid grid-cols-1 sm:grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-x-8 gap-y-5">
    @foreach($rows as [$fieldLabel, $fieldValue])
    <div>
        <dt class="mb-1 text-xs text-[#5d6e7f]">{{ $fieldLabel }}</dt>
        <dd class="font-medium break-words {{ filled($fieldValue) ? 'text-[#173045]' : 'text-[#8592a1]' }}">{{ filled($fieldValue) ? $fieldValue : '—' }}</dd>
    </div>
    @endforeach
</dl>
