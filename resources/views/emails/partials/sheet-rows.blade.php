@php
    $cell = 'padding: 10px 8px; border-bottom: 1px solid #edeff2; font-size: 14px; line-height: 1.5; vertical-align: top; color: #606f7b;';
    $badge = 'margin-left: 4px; padding: 1px 6px; border-radius: 3px; font-size: 11px; font-weight: 700;';
    $badgeColour = ['New' => ' background: #e3fcec; color: #1f9d55;', 'Date moved' => ' background: #fcebea; color: #cc1f1a;', 'Changed' => ' background: #fff8e6; color: #c26401;', 'Gone from sheet' => ' background: #f1f5f8; color: #606f7b;'];
@endphp
@foreach($rows as $row)
<tr>
<td style="{{ $cell }}"><strong style="color: #3d4852;">{{ $row['label'] }}</strong> <span style="{{ $badge }}{{ $badgeColour[$row['action']] ?? '' }}">{{ strtoupper($row['action']) }}</span>@foreach($row['lines'] as $line)<br>{{ $line }}@endforeach</td>
</tr>
@endforeach
