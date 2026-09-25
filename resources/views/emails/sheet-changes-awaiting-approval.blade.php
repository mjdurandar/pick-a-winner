<x-mail::message>
# {{ $film ?? $event ?? 'Master sheet' }}: {{ $headline }}

The **{{ $source->tab_name }}** tab{{ $event ? ' of '.$event : '' }}{{ $film ? ' ('.$film.')' : '' }}
came back from the last sync with {{ $headline }}.

Nothing has been written to the locations table. The sync only ever reads the
spreadsheet — these land when you accept them on the review screen.

@if(count($samples))
<x-mail::table>
| What | Screening | Fields |
| :--- | :-------- | :----- |
@foreach($samples as $row)
| {{ $row['action'] }} | {{ $row['label'] }} | {{ $row['detail'] ?: '—' }} |
@endforeach
</x-mail::table>

@if($sampleOverflow > 0)
…and {{ $sampleOverflow }} more on the review screen.
@endif
@endif

<x-mail::button :url="$reviewUrl">
Review and accept
</x-mail::button>

@if(($counts['missing'] ?? 0) > 0)
@php($missing = $counts['missing'])
The {{ $missing }} marked *gone from the sheet* {{ $missing === 1 ? 'is' : 'are' }}
not part of the batch — accepting the rest leaves {{ $missing === 1 ? 'it' : 'them' }}
alone. {{ $missing === 1 ? 'It is a screening' : 'They are screenings' }} this tab
created that the spreadsheet has stopped mentioning, which is either a
cancellation or an edit still in progress — a separate decision, one at a time,
on the review screen.
@endif

[Open the spreadsheet]({{ $sheetUrl }})

Thanks,<br>
{{ config('mail.brand') }}
</x-mail::message>
