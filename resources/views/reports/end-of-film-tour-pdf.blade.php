<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>End of Film Tour Report - {{ $event->event_name }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; color: #333; margin: 0; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; page-break-inside: avoid; }
        .header h1 { margin: 0; font-size: 24px; color: #333; }
        .header .event-name { margin-top: 10px; font-size: 18px; color: #666; }
        .header .brand-name { margin-top: 4px; font-size: 14px; color: #555; }
        .summary-cards { width: 100%; margin-bottom: 30px; page-break-inside: avoid; }
        .summary-cards-table { width: 100%; border-collapse: collapse; }
        .summary-cards-table td { width: 33.33%; padding: 5px; vertical-align: top; }
        .summary-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; text-align: center; height: 80px; display: flex; flex-direction: column; justify-content: center; }
        .summary-card .label { font-size: 11px; color: #666; margin-bottom: 5px; }
        .summary-card .value { font-size: 18px; font-weight: bold; color: #333; }
        .section { margin-bottom: 30px; page-break-inside: avoid; }
        .section h2 { font-size: 18px; color: #333; margin-bottom: 15px; border-bottom: 1px solid #ccc; padding-bottom: 5px; page-break-after: avoid; }
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data-table th, table.data-table td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 11px; }
        table.data-table th { background-color: #f8f9fa; font-weight: bold; }
        .location-grid { display: flex; flex-wrap: wrap; gap: 10px; page-break-inside: avoid; }
        .location-item { background: white; border: 1px solid #e0e0e0; border-radius: 3px; padding: 10px; flex: 1; min-width: 200px; max-width: 300px; page-break-inside: avoid; }
        .location-name { font-weight: bold; font-size: 13px; }
        .location-signups { font-weight: bold; color: #28a745; font-size: 14px; }
        .question-card { border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px; padding: 15px; background: #fafafa; page-break-inside: avoid; }
        .question-title { font-size: 16px; font-weight: bold; color: #333; margin-bottom: 5px; }
        .question-meta { font-size: 11px; color: #666; }
        .response-table { width: 100%; border-collapse: collapse; margin-top: 10px; page-break-inside: auto; }
        .response-table th, .response-table td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 11px; }
        .response-table th { background-color: #f8f9fa; font-weight: bold; }
        .response-table tbody tr { page-break-inside: avoid; }
        .footer { margin-top: 40px; text-align: center; font-size: 10px; color: #666; border-top: 1px solid #ccc; padding-top: 10px; page-break-inside: avoid; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <div class="header">
        <h1>End of Film Tour Report</h1>
        <div class="event-name">{{ $event->event_name }}</div>
        @if($film)
        <div class="brand-name">Brand: {{ $film->name }}</div>
        @endif
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <table class="summary-cards-table">
            <tr>
                <td>
                    <div class="summary-card">
                        <div class="label">Total Signups</div>
                        <div class="value">{{ number_format($totalSignups) }}</div>
                    </div>
                </td>
                <td>
                    <div class="summary-card">
                        <div class="label">Total Tickets</div>
                        <div class="value">{{ number_format($totalTickets) }}</div>
                    </div>
                </td>
                <td>
                    <div class="summary-card">
                        <div class="label">Total Locations</div>
                        <div class="value">{{ count($locationBreakdown) }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @if(isset($lastFilmSignups) && isset($lastFilmYear))
    <div class="section">
        <h2>Signups comparison</h2>
        <table class="data-table" style="max-width: 400px;">
            <thead>
                <tr>
                    <th>Current total (this film tour)</th>
                    <th>Last film ({{ $lastFilmYear }})</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ number_format($totalSignups) }}</td>
                    <td>{{ number_format($lastFilmSignups) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
    @endif

    @if(count($locationBreakdown) > 0)
    @php $top5Locations = collect($locationBreakdown)->take(5); @endphp
    <div class="section">
        <h2>Top 5 Locations by Attendees</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 8%;">#</th>
                    <th style="width: 52%;">Location</th>
                    <th style="width: 20%;">Attendees</th>
                    <th style="width: 20%;">Tickets total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($top5Locations as $i => $loc)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $loc['location_name'] }}</td>
                    <td>{{ number_format($loc['signups']) }}</td>
                    <td>{{ number_format($loc['tickets'] ?? 0) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if(count($locationBreakdown) > 0)
    <div class="section">
        <h2>Location Breakdown (Signups &amp; Tickets)</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Location</th>
                    <th>Signups</th>
                    <th>Tickets</th>
                    <th>% of signups</th>
                </tr>
            </thead>
            <tbody>
                @foreach($locationBreakdown as $loc)
                <tr>
                    <td>{{ $loc['location_name'] }}</td>
                    <td>{{ $loc['signups'] }}</td>
                    <td>{{ $loc['tickets'] ?? 0 }}</td>
                    <td>{{ $loc['percentage'] }}%</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if(count($breakdown) > 0)
    <div class="section page-break">
        <h2>Demographics &amp; Survey Breakdown (from sign-up form)</h2>
        @foreach($breakdown as $question)
        <div class="question-card">
            <div class="question-title">{{ $question['question_text'] }}</div>
            <div class="question-meta">
                Type: {{ $question['question_type'] ?? '—' }} |
                Responses: {{ $question['total_responses'] }} / {{ $totalSignups }}
                @if($totalSignups > 0)
                    ({{ number_format(($question['total_responses'] / $totalSignups) * 100, 1) }}%)
                @endif
            </div>
            @if(count($question['responses'] ?? []) > 0)
            <table class="response-table">
                <thead>
                    <tr>
                        <th style="width: 60%;">Response</th>
                        <th style="width: 20%;">Count</th>
                        <th style="width: 20%;">%</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($question['responses'] as $r)
                    <tr>
                        <td>{{ $r['value'] }}</td>
                        <td>{{ $r['count'] }}</td>
                        <td>{{ $r['percentage'] ?? 0 }}%</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div style="color: #666; font-style: italic; font-size: 11px;">No responses</div>
            @endif
        </div>
        @endforeach
    </div>
    @endif

    <div class="footer">
        Generated on {{ date('M d, Y \a\t g:i A') }} | End of Film Tour Report | Pick a Winner System
    </div>
</body>
</html>
