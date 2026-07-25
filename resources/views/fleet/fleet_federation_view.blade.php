@extends('master.game')

@section('content')
<form action="game.php?page=federationlayer&fleet={{ request()->get('fleet') }}" method="POST" role="form">
    <input type="hidden" value="{{ request()->get('fleet') }}" name="federation_invited">
    <table role="presentation" width="519" border="0" cellpadding="0" cellspacing="1">
        <tr height="20">
            <td class="c" colspan="3">{{ __('game/fleet.fl_fleet_union') }}</td>
        </tr>
        <tr height="20">
            <th colspan="3">
                <div style="text-align:left">
                    {{ __('game/fleet.fl_fleet_union_name') }}
                    <input name="name_acs" type="text" id="txt_name_acs" value="{{ $acs_code }}" minlength="3" maxlength="20"/>
                    <a href="#" onclick="document.getElementById('search').style.display = 'block'; return false;">{{ __('game/fleet.fl_search_user') }}</a>
                </div>
            </th>
        </tr>
        <tr height="20">
            <th width="150px">
                {{ __('game/fleet.fl_friends_list') }}
                <br>
                <select size="5" style="width:150px;" name="friends_list">
                    @foreach ($buddies_list as $buddy)
                    <option value="{{ $buddy['value'] }}">{{ $buddy['title'] }}</option>
                    @endforeach
                </select>
            </th>
            <th width="219px">
                <input type="submit" value="{{ __('game/fleet.fl_invite_acs') }}" name="add">
                &nbsp;
                <input type="submit" value="{{ __('game/fleet.fl_remove_acs') }}" name="remove">
            </th>
            <th width="150px">
                {{ __('game/fleet.fl_union_members') }} ({{ $invited_count }}/5)
                <br>
                <select size="5" style="width:150px;" name="members_list">
                    @foreach ($members_list as $member)
                    <option value="{{ $member['value'] }}">{{ $member['title'] }}</option>
                    @endforeach
                </select>
            </th>
        </tr>
        <tr height="20">
            <th colspan="3">
                @if (!empty($add_error_messages))
                {!! $add_error_messages !!}<br>
                @endif
                <div id="search" style="display: none; text-align: left;">
                    {{ __('game/fleet.fl_search_user') }}
                    <input name="addtogroup" type="text" />
                    <input type="submit" value="{{ __('game/fleet.fl_search_user_btn') }}" name="search"/>
                </div>
            </th>
        </tr>
        <tr height="20">
            <th colspan="3">
                <div style="text-align:right;">
                    <input type="submit" value="{{ __('game/fleet.fl_save_all') }}" name="save"/>
                    &nbsp;
                    <a href="game.php?page=movement">{{ __('game/fleet.fl_back') }}</a>
                </div>
            </th>
        </tr>
    </table>
</form>
@endsection
