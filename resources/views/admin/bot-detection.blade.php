@extends('master.admin')

@section('content')
<div class="container-fluid">
    <x-admin.page-header
        title="Bot Detection"
        subtitle="{{ $totalSuspicious }} suspicious player(s) detected"
    />

    @if(empty($players))
        <div class="alert alert-success">
            <i class="fas fa-check-circle mr-2"></i>No suspicious players detected. Everyone looks human!
        </div>
    @else
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-robot mr-2"></i>Suspicious Players ({{ count($players) }} flagged)
                </h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" width="100%">
                        <thead>
                            <tr>
                                <th>Suspicion</th>
                                <th>Player</th>
                                <th>Email</th>
                                <th>Last Active</th>
                                <th>Hours Active (24h)</th>
                                <th>Longest Gap</th>
                                <th>Flags</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($players as $player)
                                @php
                                    $scoreClass = match(true) {
                                        $player['suspicion_score'] >= 70 => 'danger',
                                        $player['suspicion_score'] >= 40 => 'warning',
                                        default => 'info',
                                    };
                                    $lastActiveAgo = now()->timestamp - $player['onlinetime'];
                                    $lastActiveText = $lastActiveAgo < 60 ? 'Just now' :
                                        ($lastActiveAgo < 3600 ? round($lastActiveAgo / 60) . 'm ago' :
                                        round($lastActiveAgo / 3600, 1) . 'h ago');
                                @endphp
                                <tr class="table-{{ $scoreClass }}">
                                    <td class="text-center">
                                        <span class="badge badge-{{ $scoreClass }} badge-pill" style="font-size: 14px;">
                                            {{ $player['suspicion_score'] }}%
                                        </span>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.users.info', $player['id']) }}">
                                            {{ $player['name'] }}
                                        </a>
                                    </td>
                                    <td class="small">{{ $player['email'] }}</td>
                                    <td>{{ $lastActiveText }}</td>
                                    <td class="text-center">
                                        @if($player['hours_active_24h'] >= 20)
                                            <span class="text-danger font-weight-bold">{{ $player['hours_active_24h'] }}h</span>
                                        @else
                                            {{ $player['hours_active_24h'] }}h
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($player['longest_gap_minutes'] < 30)
                                            <span class="text-danger font-weight-bold">{{ $player['longest_gap_minutes'] }}m</span>
                                        @else
                                            {{ $player['longest_gap_minutes'] }}m
                                        @endif
                                    </td>
                                    <td>
                                        @foreach($player['flags'] as $flag)
                                            <span class="badge badge-{{ $scoreClass }} mr-1">{{ $flag }}</span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-info-circle mr-2"></i>How It Works
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Detection Signals</h6>
                        <ul class="small">
                            <li><strong>Always Online:</strong> Active 20+ hours in the last 24 hours</li>
                            <li><strong>No Gaps:</strong> Longest inactivity period under 30 minutes</li>
                            <li><strong>Regular Patterns:</strong> Actions at perfectly consistent intervals</li>
                            <li><strong>Resource Anomalies:</strong> Resources far above expected for account age</li>
                            <li><strong>Shared IP:</strong> Multiple accounts from the same IP address</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Score Guide</h6>
                        <ul class="small">
                            <li><span class="badge badge-danger">70-100%</span> Very likely bot — investigate immediately</li>
                            <li><span class="badge badge-warning">40-69%</span> Suspicious — monitor closely</li>
                            <li><span class="badge badge-info">1-39%</span> Minor flags — probably fine</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
