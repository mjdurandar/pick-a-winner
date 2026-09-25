{{--
    Directives stay on their own lines: PHP eats the newline after ?>, so they
    leave no blank line inside the raw HTML. A blank line would end the HTML
    block and dump the markup into the email as text.
--}}
@php
    $cell = 'padding: 10px 8px; border-bottom: 1px solid #edeff2; font-size: 15px; line-height: 1.4;';
    $totalRow = 'padding: 8px; font-size: 13px; color: #606f7b; border-bottom: 2px solid #edeff2;';
@endphp
<x-mail::message>
@if($everything)
# {{ $film ?? $event ?? 'Film site' }}: {{ count($samples) === 1 && $sampleOverflow === 0 ? 'a current difference' : 'current differences' }}

**{{ $site }}** and the Win App disagree about {{ $event ? $event : 'this tour' }}.
This is everything the check found when it was run just now, not only what is new.
@else
# {{ $film ?? $event ?? 'Film site' }}: {{ count($samples) === 1 && $sampleOverflow === 0 ? 'a new difference' : 'new differences' }}

**{{ $site }}** and the Win App have stopped agreeing about
{{ $event ? $event : 'this tour' }}.
@endif

Nothing has been changed on either side — the check only reads the website and
the locations. Fix whichever side is wrong.

@if(count($samples))
<table cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin: 0 0 8px;">
@foreach($samples as $row)
<tr>
<td width="150" style="{{ $cell }}{{ $row['severity'] === 'error' ? ' color: #cc1f1a; font-weight: 600;' : '' }}">{{ $row['place'] }}</td>
<td style="{{ $cell }} color: #606f7b; font-size: 13px;">{{ $row['message'] }}</td>
</tr>
@endforeach
@if($sampleOverflow > 0)
<tr>
<td colspan="2" style="{{ $totalRow }}">…and {{ $sampleOverflow }} more on the dashboard.</td>
</tr>
@endif
</table>
@endif

Standing totals for this check: {{ $errors }} error{{ $errors === 1 ? '' : 's' }},
{{ $warnings }} warning{{ $warnings === 1 ? '' : 's' }}{{ $resolved > 0 ? ", and {$resolved} cleared since the last run" : '' }}.

<x-mail::button :url="$dashboardUrl">
Open the site checks
</x-mail::button>

[Open {{ $site }}]({{ $siteUrl }})

Thanks,<br>
{{ config('mail.brand') }}
</x-mail::message>
