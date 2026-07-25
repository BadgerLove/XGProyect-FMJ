<tr class="{{$fleet_status}}">
    {!! $fleet_javai !!}
    <th scope="row">
        <div id="bxx{{$fleet_order}}" class="z">-</div>
        <font color="lime">{{$fleet_time}}</font>
    </th><th role="cell" colspan="2">
        <span class="{{$fleet_status}} {{$fleet_prefix}}{{$fleet_style}}">{!! $fleet_descr !!}</span>
    </th>
    <th role="cell" style="white-space:nowrap;">
        {!! $fleet_cancel ?? '' !!}
        {!! $fleet_acs ?? '' !!}
    </th>
    {!! $fleet_javas !!}
</tr>