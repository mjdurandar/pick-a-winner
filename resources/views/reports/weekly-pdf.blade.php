<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Weekly Report - {{ $dateRange['start_formatted'] }} to {{ $dateRange['end_formatted'] }}</title>
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
        }
        
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #333;
        }
        
        .header .date-range {
            margin-top: 10px;
            font-size: 16px;
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
            width: 25%;
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
        }
        
        .section h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 15px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 5px;
        }
        
        .event-card {
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 20px;
            padding: 15px;
            background: #fafafa;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .event-name {
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }
        
        .event-stats {
            text-align: right;
        }
        
        .total-signups {
            font-size: 20px;
            font-weight: bold;
            color: #007bff;
        }
        
        .locations-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            page-break-inside: avoid;
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
        
        .location-details {
            font-size: 11px;
            color: #666;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            page-break-inside: auto;
        }
        
        .table thead {
            display: table-header-group;
        }
        
        .table tbody tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .table th,
        .table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        
        .table th {
            background-color: #f8f9fa;
            font-weight: bold;
            font-size: 11px;
            page-break-inside: avoid;
        }
        
        .table td {
            font-size: 11px;
        }
        
        .table tfoot {
            display: table-footer-group;
        }
        
        .status-badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
        }
        
        .status-ongoing {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-upcoming {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-tba {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        .status-no-locations {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
        
        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Weekly Report</h1>
        <div class="date-range">{{ $dateRange['start_formatted'] }} - {{ $dateRange['end_formatted'] }}</div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <table class="summary-cards-table">
            <tr>
                <td>
                    <div class="summary-card">
                        <div class="label">Total Events</div>
                        <div class="value">{{ $summary['total_events'] }}</div>
                    </div>
                </td>
                <td>
                    <div class="summary-card">
                        <div class="label">Total Locations</div>
                        <div class="value">{{ $summary['total_locations'] }}</div>
                    </div>
                </td>
                <td>
                    <div class="summary-card">
                        <div class="label">Total Signups</div>
                        <div class="value">{{ $summary['total_signups'] }}</div>
                    </div>
                </td>
                <td>
                    <div class="summary-card">
                        <div class="label">Average per Event</div>
                        <div class="value">{{ $summary['average_signups_per_event'] }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @if(count($reportData) > 0)
    <!-- Filtered Report Data -->
    <div class="section">
        <h2>Filtered Report Data ({{ $dateRange['start_formatted'] }} - {{ $dateRange['end_formatted'] }})</h2>
        
        @foreach($reportData as $event)
        <div class="event-card">
            <div class="event-header">
                <div>
                    <div class="event-name">{{ $event['event_name'] }}</div>
                    <div style="font-size: 11px; color: #666;">
                        Filter Date Range: {{ $dateRange['start_formatted'] }} - {{ $dateRange['end_formatted'] }}
                    </div>
                </div>
                <div class="event-stats">
                    <div class="total-signups">{{ $event['total_signups'] }}</div>
                    <div style="font-size: 11px; color: #666;">Total Signups</div>
                    @if(!$event['has_signup_form'])
                    <div style="font-size: 10px; color: #dc3545;">No signup form</div>
                    @endif
                </div>
            </div>
            
            @if(count($event['locations']) > 0)
            <div style="margin-bottom: 10px;">
                <strong>Locations ({{ count($event['locations']) }}):</strong>
            </div>
            <div class="locations-grid">
                @foreach($event['locations'] as $location)
                <div class="location-item">
                    <div class="location-header">
                        <div class="location-name">{{ $location['location_name'] }}</div>
                        <div class="location-signups">{{ $location['signups'] }}</div>
                    </div>
                    <div class="location-details">
                        <div>{{ $location['formatted_date'] }}</div>
                        <div>{{ $location['formatted_time'] }}</div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div style="color: #666; font-style: italic; font-size: 11px;">
                No locations found for this event in the selected date range.
            </div>
            @endif
        </div>
        @endforeach
    </div>
    @endif

    @if(count($allEventsSummary) > 0)
    <div class="page-break"></div>
    
    <!-- All Events Summary -->
    <div class="section">
        <h2>All Events Summary (Total Data)</h2>
        
        @php
            $eventsPerPage = 15; // Adjust this number based on your needs
            $chunks = array_chunk($allEventsSummary, $eventsPerPage);
        @endphp
        
        @foreach($chunks as $index => $eventsChunk)
            @if($index > 0)
                <div class="page-break"></div>
            @endif
            
            <table class="table">
                <thead>
                    <tr>
                        <th>Event Name</th>
                        <th>Total Locations</th>
                        <th>Total Signups</th>
                        <th>Avg per Location</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($eventsChunk as $event)
                    <tr>
                        <td><strong>{{ $event['event_name'] }}</strong></td>
                        <td>{{ $event['total_locations'] }}</td>
                        <td><strong style="color: #007bff;">{{ $event['total_signups'] }}</strong></td>
                        <td>{{ $event['average_per_location'] }}</td>
                        <td>
                            <span class="status-badge status-{{ strtolower(str_replace(' ', '-', $event['status'])) }}">
                                {{ $event['status'] }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                @if($index === count($chunks) - 1)
                <tfoot>
                    <tr style="background-color: #f8f9fa; font-weight: bold;">
                        <td>TOTAL</td>
                        <td>{{ $allEventsTotals['total_locations'] }}</td>
                        <td style="color: #007bff;">{{ $allEventsTotals['total_signups'] }}</td>
                        <td>{{ $allEventsTotals['average_per_location'] }}</td>
                        <td>
                            {{ collect($allEventsSummary)->where('status', 'Ongoing')->count() }} Ongoing, 
                            {{ collect($allEventsSummary)->where('status', 'Completed')->count() }} Completed
                        </td>
                    </tr>
                </tfoot>
                @endif
            </table>
        @endforeach
    </div>
    @endif

    <div class="footer">
        Generated on {{ date('M d, Y \a\t g:i A') }} | Pick a Winner System
    </div>
</body>
</html>
