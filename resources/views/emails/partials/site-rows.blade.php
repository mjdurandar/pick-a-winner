@php
    $cell = 'padding: 10px 8px; border-bottom: 1px solid #edeff2; font-size: 14px; line-height: 1.5; vertical-align: top; color: #606f7b;';
    $badge = 'margin-left: 4px; padding: 1px 6px; border-radius: 3px; font-size: 11px; font-weight: 700;';
    $badgeColour = ['error' => ' background: #fcebea; color: #cc1f1a;', 'warning' => ' background: #fff8e6; color: #c26401;', 'notice' => ' background: #f1f5f8; color: #606f7b;'];
@endphp
@foreach($rows as $row)
<tr>
<td style="{{ $cell }}"><strong style="color: {{ $row['severity'] === 'error' ? '#cc1f1a' : '#3d4852' }};">{{ $row['place'] }}</strong> <span style="{{ $badge }}{{ ($showNew ?? false) && $row['new'] ? ' background: #e8f4fd; color: #1c6ca1;' : ($badgeColour[$row['severity']] ?? '') }}">{{ strtoupper($row['severity']) }}{{ ($showNew ?? false) && $row['new'] ? ' · NEW' : '' }}</span><br>{{ $row['message'] }}<br>Win App: {{ $row['app'] }}<br>Website: {{ $row['site'] }}</td>
</tr>
@endforeach
