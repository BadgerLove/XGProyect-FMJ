@extends('master.game', ['noTopnav' => true, 'noLeftMenu' => true])

@section('content')
@if ($shareUrl)
    <div style="background:#1a1a2e; border:1px solid #4e73df; border-radius:4px; padding:8px 12px; margin-bottom:10px; text-align:center;">
        <strong style="color:#4e73df;">&#128279; Report shared!</strong>
        <br>
        <input type="text" value="{{ $shareUrl }}" readonly onclick="this.select();"
               style="width:100%; max-width:600px; margin-top:4px; padding:4px; border:1px solid #333; background:#0d0d1a; color:#858796; font-size:12px; text-align:center;" />
        <br>
        <small style="color:#858796;">Anyone with this link can view the battle report.</small>
    </div>
@else
    <div style="background:#1a1a2e; border:1px solid #333; border-radius:4px; padding:6px 12px; margin-bottom:10px; text-align:center;">
        <a href="?report={{ request()->query('report') }}&share=1" style="color:#4e73df;">&#128279; Share this battle report</a>
    </div>
@endif
{!! $report !!}
@endsection
