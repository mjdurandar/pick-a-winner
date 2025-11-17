<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Event Breakdown - {{ $event->event_name }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #333;
        }
        
        .header .event-name {
            margin-top: 10px;
            font-size: 18px;
            color: #666;
        }
        
        .summary-cards {
            width: 100%;
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        
        .summary-cards-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .summary-cards-table td {
            width: 33.33%;
            padding: 5px;
            vertical-align: top;
        }
        
        .summary-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            text-align: center;
            height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .summary-card .label {
            font-size: 11px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .summary-card .value {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }
        
        .section {
            margin-bottom: 30px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .section h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 15px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 5px;
            page-break-after: avoid;
            break-after: avoid;
        }
        
        .question-card {
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 20px;
            padding: 15px;
            background: #fafafa;
            /* Try to keep card together, but allow break if too large */
            /* Using orphans/widows to help with page breaks */
            orphans: 3;
            widows: 3;
            /* Prefer to keep together, but don't force if content is too large */
            min-height: 50px;
            /* Prefer to keep question card together, but allow breaking for very large tables */
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        /* Allow breaking for very large tables within cards if needed */
        .question-card table tbody {
            page-break-inside: auto;
            break-inside: auto;
        }
        
        .question-header {
            margin-bottom: 15px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .question-title {
            font-size: 16px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .question-meta {
            font-size: 11px;
            color: #666;
        }
        
        .response-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            /* Allow table to break if needed, but keep rows together */
            page-break-inside: auto;
            break-inside: auto;
        }
        
        .response-table thead {
            display: table-header-group;
            /* Repeat header on each page if table breaks */
            page-break-after: avoid;
            break-after: avoid;
        }
        
        .response-table tbody {
            display: table-row-group;
        }
        
        /* Keep individual rows together - never break a row */
        .response-table tbody tr {
            page-break-inside: avoid;
            break-inside: avoid;
            /* Don't break before unless necessary */
            page-break-before: auto;
        }
        
        .response-table th,
        .response-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .response-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            font-size: 11px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .response-table td {
            font-size: 11px;
        }
        
        .response-value {
            font-weight: 500;
        }
        
        .response-count {
            text-align: center;
            font-weight: bold;
            color: #007bff;
        }
        
        .response-percentage {
            text-align: center;
            color: #666;
        }
        
        .location-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .location-item {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 3px;
            padding: 10px;
            flex: 1;
            min-width: 200px;
            max-width: 300px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .location-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .location-name {
            font-weight: bold;
            font-size: 13px;
        }
        
        .location-signups {
            font-weight: bold;
            color: #28a745;
            font-size: 14px;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ccc;
            padding-top: 10px;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .page-break {
            page-break-before: always;
            break-before: page;
        }
        
        .no-break {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        /* Prevent orphaned table rows */
        .response-table tbody tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        /* Keep at least 2 rows together at bottom of page */
        .response-table tbody tr:last-child {
            page-break-after: avoid;
            break-after: avoid;
        }
        
        .response-table tbody tr:nth-last-child(2) {
            page-break-after: avoid;
            break-after: avoid;
        }
        
        /* Add spacing before question cards */
        .question-card + .question-card {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Event Breakdown Report</h1>
        <div class="event-name">{{ $event->event_name }}</div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <table class="summary-cards-table">
            <tr>
                <td>
                    <div class="summary-card">
                        <div class="label">Total Signups</div>
                        <div class="value">{{ $totalSignups }}</div>
                    </div>
                </td>
                <td>
                    <div class="summary-card">
                        <div class="label">Total Questions</div>
                        <div class="value">{{ count($breakdown) }}</div>
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

    @if(count($locationBreakdown) > 0)
    <!-- Location Breakdown -->
    <div class="section">
        <h2>Location Breakdown</h2>
        <div class="location-grid">
            @foreach($locationBreakdown as $location)
            <div class="location-item">
                <div class="location-header">
                    <div class="location-name">{{ $location['location_name'] }}</div>
                    <div class="location-signups">{{ $location['signups'] }}</div>
                </div>
                <div style="font-size: 11px; color: #666;">
                    {{ $location['percentage'] }}% of total signups
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if(count($breakdown) > 0)
    <!-- Question Breakdown -->
    <div class="section page-break">
        <h2>Question Breakdown</h2>
        
        @foreach($breakdown as $index => $question)
        <div class="question-card">
            <div class="question-header">
                <div class="question-title">{{ $question['question_text'] }}</div>
                <div class="question-meta">
                    Type: {{ $question['question_type'] }} | 
                    Responses: {{ $question['total_responses'] }} / {{ $totalSignups }}
                    @if($totalSignups > 0)
                        ({{ round(($question['total_responses'] / $totalSignups) * 100, 1) }}%)
                    @endif
                </div>
            </div>
            
            @if(count($question['responses']) > 0)
            <table class="response-table">
                <thead>
                    <tr>
                        <th style="width: 60%;">Response</th>
                        <th style="width: 20%;">Count</th>
                        <th style="width: 20%;">Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($question['responses'] as $response)
                    <tr>
                        <td class="response-value">{{ $response['value'] }}</td>
                        <td class="response-count">{{ $response['count'] }}</td>
                        <td class="response-percentage">{{ $response['percentage'] }}%</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div style="color: #666; font-style: italic; font-size: 11px;">
                No responses for this question
            </div>
            @endif
        </div>
        @endforeach
    </div>
    @endif

    <div class="footer">
        Generated on {{ date('M d, Y \a\t g:i A') }} | Pick a Winner System
    </div>
</body>
</html>

