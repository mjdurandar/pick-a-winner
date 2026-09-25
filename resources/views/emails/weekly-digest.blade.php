{{--
    Layout is hand-rolled HTML rather than <x-mail::table>: the markdown table
    component gives every column the same weight, which is what wrapped the
    "Sign-ups" heading and split the dates over two lines. Fixed widths and
    nowrap on the two narrow columns fix that, and one table for every film
    beats one table each with the header repeated.

    Keep directives on their own lines. PHP eats the newline after ?>, so they
    leave no blank line inside the HTML — and a blank line would end the block
    and dump the raw markup into the email.
--}}
@php
    $cell = 'padding: 10px 8px; border-bottom: 1px solid #edeff2; font-size: 15px; line-height: 1.4;';
    $th = 'padding: 0 8px 8px; font-size: 12px; letter-spacing: .04em; text-transform: uppercase; color: #8795a1; font-weight: 600; border-bottom: 2px solid #edeff2;';
    $groupRow = 'padding: 18px 8px 6px; font-size: 15px; font-weight: 700; color: #3d4852;';
    $num = $cell.' text-align: right; white-space: nowrap;';
    $when = $cell.' white-space: nowrap; color: #606f7b;';
    $totalRow = 'padding: 8px; font-size: 13px; color: #606f7b; border-bottom: 2px solid #edeff2;';
    $muted = 'font-weight: 400; color: #8795a1;';
@endphp
<x-mail::message>
# Week of {{ $start->format('j M') }} – {{ $end->format('j M Y') }}

<table cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin: 0 0 8px;">
<tr>
<td style="padding: 16px; background: #f7fafc; border-radius: 4px;">
<span style="font-size: 26px; font-weight: 700; color: #3d4852;">{{ number_format($d['totals']['signups']) }}</span>
<span style="font-size: 14px; color: #606f7b;"> sign-up{{ $d['totals']['signups'] === 1 ? '' : 's' }} from {{ $d['totals']['screenings'] }} screening{{ $d['totals']['screenings'] === 1 ? '' : 's' }} across {{ $d['totals']['events'] }} film{{ $d['totals']['events'] === 1 ? '' : 's' }}</span>
</td>
</tr>
</table>

{!! \Illuminate\Support\Str::markdown($attention) !!}

## What screened

@if(count($d['screenings']) === 0)
No screenings fell in this week.
@else
<table cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin: 0 0 8px;">
<tr>
<th align="left" style="{{ $th }}">Screening</th>
<th align="left" width="96" style="{{ $th }} white-space: nowrap;">Date</th>
<th align="right" width="82" style="{{ $th }} text-align: right; white-space: nowrap;">Sign&#8209;ups</th>
</tr>
@foreach($d['screenings'] as $event)
<tr>
<td colspan="3" style="{{ $groupRow }}">{{ $event['event_name'] }}@if($event['film'] && $event['film'] !== $event['event_name'])<span style="{{ $muted }}"> — {{ $event['film'] }}</span>@endif</td>
</tr>
@foreach($event['locations'] as $location)
<tr>
<td style="{{ $cell }}">{{ $location['name'] }}</td>
<td style="{{ $when }}">{{ $location['date'] }}</td>
<td style="{{ $num }}">{{ number_format($location['signups']) }}</td>
</tr>
@endforeach
<tr>
<td colspan="2" style="{{ $totalRow }}">{{ count($event['locations']) }} screening{{ count($event['locations']) === 1 ? '' : 's' }}</td>
<td style="{{ $totalRow }} text-align: right; font-weight: 700; color: #3d4852;">{{ number_format($event['signups']) }}</td>
</tr>
@endforeach
</table>
@endif

## Master sheet sync

@if(count($d['sheetSources']) === 0)
Nothing parked. Every tab is accepted and up to date.
@else
@php($t = $d['sheetTotals'])
**{{ $t['creates'] }} new, {{ $t['updates'] }} changed, {{ $t['missing'] }} gone from the sheet** across {{ count($d['sheetSources']) }} tab{{ count($d['sheetSources']) === 1 ? '' : 's' }}{{ $t['dates'] > 0 ? ", including {$t['dates']} with a moved date or time" : '' }}.

Nothing has been written to the locations table — the sync only ever reads the
spreadsheet. These land when someone accepts them on the review screen.

@foreach($d['sheetSources'] as $source)
<table cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin: 0 0 8px;">
<tr>
<td colspan="3" style="{{ $groupRow }}">{{ $source['tab'] }}@if($source['event'])<span style="{{ $muted }}"> — {{ $source['event'] }}</span>@endif</td>
</tr>
@foreach($source['samples'] as $row)
<tr>
<td width="110" style="{{ $cell }} white-space: nowrap; color: #606f7b; font-size: 13px;">{{ $row['action'] }}</td>
<td style="{{ $cell }}">{{ $row['label'] }}</td>
<td style="{{ $cell }} color: #606f7b; font-size: 13px;">{{ $row['detail'] ?: '—' }}</td>
</tr>
@endforeach
@if($source['overflow'] > 0)
<tr>
<td colspan="3" style="{{ $totalRow }}">…and {{ $source['overflow'] }} more on the review screen.</td>
</tr>
@endif
</table>
@endforeach

<x-mail::button :url="$sheetUrl">
Review and accept
</x-mail::button>
@endif

## Website sync

@if(count($d['siteChecks']) === 0)
No film site checks are set up.
@elseif($d['siteTotals']['errors'] === 0 && $d['siteTotals']['warnings'] === 0 && $d['siteTotals']['stale'] === 0)
Every site matches the Win App.
@else
@php($t = $d['siteTotals'])
**{{ $t['errors'] }} error{{ $t['errors'] === 1 ? '' : 's' }} and {{ $t['warnings'] }} warning{{ $t['warnings'] === 1 ? '' : 's' }}** across the film sites{{ $t['stale'] > 0 ? ', and '.$t['stale'].' check'.($t['stale'] === 1 ? ' has' : 's have').' not run in over a day' : '' }}.

@foreach($d['siteChecks'] as $check)
@continue($check['errors'] === 0 && $check['warnings'] === 0 && ! $check['stale'])
<table cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin: 0 0 8px;">
<tr>
<td colspan="2" style="{{ $groupRow }}">{{ $check['site'] }}<span style="{{ $muted }}">{{ $check['season'] ? ' · '.$check['season'] : '' }}{{ $check['event'] ? ' — '.$check['event'] : '' }}</span></td>
</tr>
@if($check['stale'])
<tr>
<td colspan="2" style="{{ $cell }} color: #c26401; font-size: 13px;">Last checked {{ $check['checked_at']?->diffForHumans() ?? 'never' }}.{{ $check['error'] ? ' Last error: '.$check['error'] : '' }}</td>
</tr>
@endif
@foreach($check['samples'] as $row)
<tr>
<td width="150" style="{{ $cell }}">{{ $row['place'] }}</td>
<td style="{{ $cell }} color: #606f7b; font-size: 13px;">{{ $row['message'] }}</td>
</tr>
@endforeach
@if($check['overflow'] > 0)
<tr>
<td colspan="2" style="{{ $totalRow }}">…and {{ $check['overflow'] }} more on the dashboard.</td>
</tr>
@endif
@if($check['new_issues'] > 0 || $check['resolved_issues'] > 0)
<tr>
<td colspan="2" style="{{ $totalRow }}">{{ $check['new_issues'] }} new since the previous run, {{ $check['resolved_issues'] }} resolved.</td>
</tr>
@endif
</table>
@endforeach

<x-mail::button :url="$siteUrl">
Open the site checks
</x-mail::button>
@endif

<x-mail::subcopy>
[Open the full weekly report]({{ $reportUrl }}) for demographics, exports and any week you like.
</x-mail::subcopy>
</x-mail::message>
