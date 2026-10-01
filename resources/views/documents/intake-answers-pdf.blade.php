@php
    $practiceName = $practice?->name ?: 'Practice';
    $providers = $practice?->billable_providers_count ?? 1;
@endphp

<table width="100%" cellpadding="10" cellspacing="0">
    <tr>
        <td colspan="2" style="background-color: #12304f;">
            <span style="color: #ffffff; font-size: 20px; font-weight: bold;">Practice Intake Answers</span>
        </td>
    </tr>
    <tr>
        <td width="60%" style="background-color: #12304f; vertical-align: top;">
            <p style="color: #ffffff; font-size: 11px; font-weight: bold; margin: 0;">{{ $practiceName }}</p>
            <p style="color: #ffffff; font-size: 9px; margin: 4px 0 0;">{{ $order->package?->name ?? 'Compliance Package' }}</p>
        </td>
        <td width="40%" style="background-color: #12304f; text-align: right; vertical-align: top;">
            <p style="color: #ffffff; font-size: 9px; margin: 0;">Generated {{ now()->format('F j, Y') }}</p>
            <p style="color: #ffffff; font-size: 9px; margin: 4px 0 0;">Submission #{{ $submission->id }}</p>
        </td>
    </tr>
</table>

<p style="margin-top: 16px; margin-bottom: 6px; font-size: 12px; font-weight: bold; color: #12304f;">Practice basics</p>
<table width="100%" cellpadding="6" cellspacing="0" style="border: 1px solid #dbe4ee;">
    <tr style="border-bottom: 1px solid #eef2f6;">
        <td width="38%" style="font-size: 9px; color: #5d6e7f;">Practice name &amp; specialty</td>
        <td style="font-size: 9px; color: #173045;">{{ collect([$practice?->name, $practice?->specialty])->filter()->implode(' · ') ?: '(not yet provided)' }}</td>
    </tr>
    <tr style="border-bottom: 1px solid #eef2f6;">
        <td style="font-size: 9px; color: #5d6e7f;">Billable providers</td>
        <td style="font-size: 9px; color: #173045;">{{ $providers }} billable provider{{ $providers === 1 ? '' : 's' }}</td>
    </tr>
    <tr style="border-bottom: 1px solid #eef2f6;">
        <td style="font-size: 9px; color: #5d6e7f;">Practice address</td>
        <td style="font-size: 9px; color: #173045;">{{ $practice?->address ?: '(not yet provided)' }}</td>
    </tr>
    <tr>
        <td style="font-size: 9px; color: #5d6e7f;">Practice logo</td>
        <td style="font-size: 9px; color: #173045;">{{ $practice?->logo_path ? 'Logo uploaded' : 'No logo added' }}</td>
    </tr>
</table>

<p style="margin-top: 14px; margin-bottom: 6px; font-size: 12px; font-weight: bold; color: #12304f;">Uploaded documents</p>
<table width="100%" cellpadding="6" cellspacing="0" style="border: 1px solid #dbe4ee;">
    @foreach($documentRows as $row)
    <tr style="{{ ! $loop->last ? 'border-bottom: 1px solid #eef2f6;' : '' }}">
        <td width="38%" style="font-size: 9px; color: #5d6e7f;">{{ $row['label'] }}</td>
        <td style="font-size: 9px; color: #173045;">{{ $row['status'] }}</td>
    </tr>
    @endforeach
</table>

@if($includesWorkflowQuestionnaire)
<p style="margin-top: 14px; margin-bottom: 6px; font-size: 12px; font-weight: bold; color: #12304f;">Your team</p>
<table width="100%" cellpadding="6" cellspacing="0" style="border: 1px solid #dbe4ee;">
    @foreach($teamRows as $row)
    <tr style="{{ ! $loop->last ? 'border-bottom: 1px solid #eef2f6;' : '' }}">
        <td width="38%" style="font-size: 9px; color: #5d6e7f;">{{ $row['label'] }}</td>
        <td style="font-size: 9px; color: #173045;">{{ $row['value'] }}</td>
    </tr>
    @endforeach
</table>

@foreach($workflowSections as $section)
<p style="margin-top: 16px; margin-bottom: 0; font-size: 11px; font-weight: bold; color: #ffffff; background-color: #1a7aad; padding: 5px 8px;">{{ $section['label'] }}</p>
<table width="100%" cellpadding="7" cellspacing="0" style="border: 1px solid #dbe4ee; border-top: none;">
    @foreach($section['questions'] as $question)
    <tr style="{{ ! $loop->last ? 'border-bottom: 1px solid #eef2f6;' : '' }}">
        <td>
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="font-size: 10px; font-weight: bold; color: #173045;">{{ $question['title'] }}</td>
                    <td width="110" style="text-align: right;">
                        <span style="font-size: 7.5px; font-weight: bold; color: #{{ $question['badge']['color'] }}; background-color: #{{ $question['badge']['background'] }}; padding: 2px 6px;">{{ $question['badge']['label'] }}</span>
                    </td>
                </tr>
            </table>
            <p style="font-size: 9px; color: #173045; margin: 4px 0 0; line-height: 1.4;">{{ $question['value'] }}</p>
        </td>
    </tr>
    @endforeach
</table>
@endforeach
@endif

@if($certification)
<p style="margin-top: 16px; margin-bottom: 6px; font-size: 12px; font-weight: bold; color: #12304f;">Certification</p>
<table width="100%" cellpadding="6" cellspacing="0" style="border: 1px solid #dbe4ee;">
    <tr style="border-bottom: 1px solid #eef2f6;">
        <td width="38%" style="font-size: 9px; color: #5d6e7f;">Certified by</td>
        <td style="font-size: 9px; color: #173045;">{{ $certification['by'] }}</td>
    </tr>
    <tr style="border-bottom: 1px solid #eef2f6;">
        <td style="font-size: 9px; color: #5d6e7f;">Signature</td>
        <td style="font-size: 9px; color: #173045; font-style: italic;">{{ $certification['signature'] ?: '(not provided)' }}</td>
    </tr>
    <tr>
        <td style="font-size: 9px; color: #5d6e7f;">Date</td>
        <td style="font-size: 9px; color: #173045;">{{ $certification['date'] ?? '(not provided)' }}</td>
    </tr>
</table>
@endif
