{{--
    Directives stay on their own lines: PHP eats the newline after ?>, so they
    leave no blank line inside the raw HTML. A blank line would end the HTML
    block and dump the markup into the email as text.
--}}
<x-mail::message>
# {{ $film ?? $event ?? 'Master sheet' }}: {{ $headline }}

The **{{ $source->tab_name }}** tab{{ $event ? ' of '.$event : '' }}{{ $film ? ' ('.$film.')' : '' }}
came back from the last sync with {{ $headline }}.

Nothing has been written to the locations table. The sync only ever reads the
spreadsheet — these land only once they are accepted.

@if(count($rows))
<table cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin: 0 0 8px;">
@include('emails.partials.sheet-rows', ['rows' => $rows])
</table>
@endif

@if(($counts['missing'] ?? 0) > 0)
@php($missing = $counts['missing'])
The {{ $missing }} marked *gone from sheet* {{ $missing === 1 ? 'is' : 'are' }}
not part of the batch — accepting the rest leaves {{ $missing === 1 ? 'it' : 'them' }}
alone. {{ $missing === 1 ? 'It is a screening' : 'They are screenings' }} this tab
created that the spreadsheet has stopped mentioning, which is either a
cancellation or an edit still in progress — a separate decision, one at a time.
@endif

Thanks,<br>
{{ config('mail.brand') }}
</x-mail::message>
