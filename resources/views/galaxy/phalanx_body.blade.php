@extends('master.game', ['noTopnav' => true, 'noLeftMenu' => true])

@section('content')
<style>
    /* Standalone popup (opened by fenster() from galaxy view): no topnav and no
       left menu, so reclaim the 190px menu gutter and hide the mobile drawer
       controls, which would otherwise open an empty menu. Scoped to this page. */
    #menu {
        width: 0;
    }

    .hamburger-btn,
    .menu-overlay {
        display: none !important;
    }

    #content {
        width: 100%;
        padding: 10px;
        box-sizing: border-box;
    }

    #phalanx-scan {
        width: 100%;
        max-width: 519px;
        margin: 0 auto;
    }

    #phalanx-scan table {
        width: 100%;
        border-collapse: collapse;
    }

    #phalanx-scan .phalanx-error {
        display: block;
        padding-bottom: 6px;
        color: #f00;
    }
</style>

<div id="phalanx-scan">
    @if ($phl_er_deuter)
        <span class="phalanx-error">{{ $phl_er_deuter }}</span>
    @endif
    <table>
        <tr>
            <td class="c" colspan="4">{{ __('game/phalanx.px_scan_position') }} [{{ $phl_pl_galaxy }}:{{ $phl_pl_system }}:{{ $phl_pl_place }}] ({{ $phl_pl_name }})</td>
        </tr>
        <tr>
            <td class="c" colspan="4">{{ __('game/phalanx.px_fleet_movement') }}</td>
        </tr>
        {!! $phl_fleets_table !!}
    </table>
</div>
@endsection
