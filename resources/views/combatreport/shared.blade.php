@extends('master.game', ['noTopnav' => true, 'noLeftMenu' => true])

@section('content')
<div style="background:#1a1a2e; border:1px solid #4e73df; border-radius:4px; padding:8px 12px; margin-bottom:10px; text-align:center;">
    <strong style="color:#4e73df;">&#128279; Shared Battle Report</strong>
    <br>
    <small style="color:#858796;">This report was shared by another player.</small>
</div>
{!! $report !!}
@endsection
