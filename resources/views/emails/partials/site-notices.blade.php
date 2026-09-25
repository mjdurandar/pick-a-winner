<tr>
<td style="padding: 8px; border-bottom: 1px solid #edeff2; font-size: 13px; line-height: 1.6; vertical-align: top; color: #606f7b;">@foreach($rows as $row){{ $row['place'] }} — {{ $row['message'] }} (Win App: {{ $row['app'] }})@if(! $loop->last)<br>@endif @endforeach</td>
</tr>
