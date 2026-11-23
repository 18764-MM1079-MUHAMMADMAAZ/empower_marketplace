{{-- My Account › Activity Log › Export PDF (rendered by TCPDF, so plain tables and inline styles only). --}}
<p style="font-size:10pt;color:#334155;">{{ $user->name }} · {{ $user->email }}@if($user->practice?->name) · {{ $user->practice->name }}@endif<br>
Exported {{ now(\App\Services\ClientActivity::TIMEZONE)->format('M j, Y g:i A') }} {{ $tz }} · {{ $rows->count() }} {{ Str::plural('activity', $rows->count()) }} · Kept permanently; entries are never edited or deleted.</p>
<table cellpadding="4" style="font-size:8.5pt;border-collapse:collapse;">
    <thead>
        <tr style="background-color:#f1f5f9;color:#334155;font-weight:bold;">
            <th width="6%">Sr. No.</th>
            <th width="13%">When ({{ $tz }})</th>
            <th width="35%">Activity</th>
            <th width="22%">Device &amp; location</th>
            <th width="14%">Type</th>
            <th width="10%">Result</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $row)
        <tr style="{{ $row['failed'] ? 'background-color:#fff7f6;' : '' }}">
            <td width="6%">{{ $row['sr'] }}</td>
            <td width="13%">{{ $row['when']->format('M j, Y g:i A') }}</td>
            <td width="35%">{{ $row['title'] }}<br><span style="color:#5d6e7f;">{{ $row['actor'] }}</span></td>
            <td width="22%">{{ $row['where'] }}@if($row['detail'])<br><span style="color:#5d6e7f;">{{ $row['detail'] }}</span>@endif</td>
            <td width="14%">{{ $row['type_label'] }}</td>
            <td width="10%">{{ $row['failed'] ? 'Failed' : '' }}</td>
        </tr>
        @empty
        <tr><td colspan="6">No activity matches these filters.</td></tr>
        @endforelse
    </tbody>
</table>
