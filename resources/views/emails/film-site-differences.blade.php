{{--
    Directives stay on their own lines: PHP eats the newline after ?>, so they
    leave no blank line inside the raw HTML. A blank line would end the HTML
    block and dump the markup into the email as text.
--}}
@php
    $groupRow = 'padding: 18px 8px 6px; font-size: 15px; font-weight: 700; color: #3d4852; border-bottom: 2px solid #edeff2;';
@endphp
<x-mail::message>
@if($everything)
# {{ $film ?? $event ?? 'Film site' }}: {{ count($problems) === 1 ? 'a current difference' : 'current differences' }}

**{{ $site }}** and the Win App disagree about {{ $event ? $event : 'this tour' }}.
This is everything the check found when it was run just now.
@else
# {{ $film ?? $event ?? 'Film site' }}: {{ $newCount === 1 ? 'a new difference' : 'new differences' }}

**{{ $site }}** and the Win App have stopped agreeing about
{{ $event ? $event : 'this tour' }}. {{ $newCount }} {{ $newCount === 1 ? 'difference is' : 'differences are' }} new since the last check (tagged NEW); every standing difference is listed below.
@endif

Nothing has been changed on either side — the check only reads the website and
the locations. Fix whichever side is wrong.

**{{ $errors }} error{{ $errors === 1 ? '' : 's' }}, {{ $warnings }} warning{{ $warnings === 1 ? '' : 's' }}, {{ $matched }} matched**{{ $resolved > 0 ? ", {$resolved} cleared since the last run" : '' }}.

@if(count($problems))
<table cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin: 0 0 8px;">
<tr>
<td style="{{ $groupRow }}">Needs fixing ({{ count($problems) }})</td>
</tr>
@include('emails.partials.site-rows', ['rows' => $problems, 'showNew' => ! $everything])
</table>
@endif

@if(count($notices))
<table cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin: 0 0 8px;">
<tr>
<td style="{{ $groupRow }}">Past screenings — no action needed ({{ count($notices) }})</td>
</tr>
@include('emails.partials.site-notices', ['rows' => $notices])
</table>
@endif

Thanks,<br>
{{ config('mail.brand') }}
</x-mail::message>
