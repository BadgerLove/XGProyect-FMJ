{{--
    New-universe countdown on the front page (Dale, 2026-10-01).
    Change LAUNCH to move it. Before launch: live countdown. After launch: "the new universe is
    live" for a day, then the banner hides itself, so it never needs taking down by hand.
    The time is sent as a UTC timestamp, so every visitor counts down to the same moment
    whatever their own clock's time zone.
--}}
@php
    $launch = \Illuminate\Support\Carbon::parse('2026-10-02 18:00', 'Europe/London');
    $now = \Illuminate\Support\Carbon::now();
    $isLive = $now->greaterThanOrEqualTo($launch);
    $left = max(0, $launch->getTimestamp() - $now->getTimestamp());
    $parts = [
        'days' => intdiv($left, 86400),
        'hours' => intdiv($left % 86400, 3600),
        'mins' => intdiv($left % 3600, 60),
        'secs' => $left % 60,
    ];
@endphp
@if ($now->lessThan($launch->copy()->addDay()))
<style type="text/css">
    #nu-countdown {
        width: 718px;
        margin: 0 auto 14px;
        box-sizing: border-box;
        padding: 14px 18px 16px;
        border: 1px solid #415680;
        border-radius: 4px;
        background: rgba(8, 20, 36, 0.88);
        box-shadow: 0 0 18px rgba(97, 159, 200, 0.25);
        text-align: center;
        font-family: Helvetica, Arial, sans-serif;
        color: #c9d6e6;
    }
    #nu-countdown .nu-title {
        margin: 0 0 4px;
        font-size: 20px;
        font-weight: bold;
        letter-spacing: 3px;
        text-transform: uppercase;
        color: #ffb347;
    }
    #nu-countdown .nu-sub {
        margin: 0 0 12px;
        font-size: 13px;
    }
    #nu-countdown .nu-sub strong { color: #fff; }
    #nu-countdown .nu-clock {
        display: flex;
        justify-content: center;
        gap: 10px;
    }
    #nu-countdown .nu-unit {
        min-width: 74px;
        padding: 8px 6px 6px;
        border: 1px solid #415680;
        border-radius: 4px;
        background: #0d1b2a;
    }
    #nu-countdown .nu-num {
        display: block;
        font-size: 30px;
        font-weight: bold;
        line-height: 1.1;
        color: #fff;
        font-variant-numeric: tabular-nums;
    }
    #nu-countdown .nu-label {
        display: block;
        margin-top: 2px;
        font-size: 10px;
        letter-spacing: 2px;
        text-transform: uppercase;
        color: #619fc8;
    }
    #nu-countdown .nu-live {
        font-size: 15px;
        color: #7cfc00;
        font-weight: bold;
    }
    @media (max-width: 768px) {
        #nu-countdown { width: auto; margin: 0 8px 12px; padding: 12px 8px 14px; }
        #nu-countdown .nu-title { font-size: 16px; letter-spacing: 2px; }
        #nu-countdown .nu-sub { font-size: 12px; }
        #nu-countdown .nu-clock { gap: 6px; }
        #nu-countdown .nu-unit { min-width: 0; flex: 1 1 0; padding: 6px 2px 5px; }
        #nu-countdown .nu-num { font-size: 24px; }
        #nu-countdown .nu-label { font-size: 9px; letter-spacing: 1px; }
    }
</style>
<div id="nu-countdown" data-launch="{{ $launch->getTimestamp() * 1000 }}">
    <p class="nu-title">{{ $isLive ? 'The new universe is live' : 'A new universe is coming' }}</p>
    <p class="nu-sub nu-when"@if ($isLive) style="display:none"@endif>
        Everything resets on <strong>Friday 2 October at 6pm</strong> (UK time). Everyone starts again from scratch.
    </p>
    <div class="nu-clock"@if ($isLive) style="display:none"@endif>
        @foreach ($parts as $key => $value)
        <div class="nu-unit">
            <span class="nu-num" data-part="{{ $key }}">{{ str_pad((string) $value, 2, '0', STR_PAD_LEFT) }}</span>
            <span class="nu-label">{{ $key }}</span>
        </div>
        @endforeach
    </div>
    <p class="nu-live"@unless ($isLive) style="display:none"@endunless>Everyone starts fresh. Register or log in now!</p>
</div>
<script type="text/javascript">
    (function () {
        var box = document.getElementById('nu-countdown');
        var launch = parseInt(box.getAttribute('data-launch'), 10);
        var nums = {};
        box.querySelectorAll('[data-part]').forEach(function (el) { nums[el.getAttribute('data-part')] = el; });
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        function tick() {
            var left = Math.max(0, Math.floor((launch - Date.now()) / 1000));
            nums.days.textContent = pad(Math.floor(left / 86400));
            nums.hours.textContent = pad(Math.floor(left % 86400 / 3600));
            nums.mins.textContent = pad(Math.floor(left % 3600 / 60));
            nums.secs.textContent = pad(left % 60);
            if (left === 0) {
                box.querySelector('.nu-title').textContent = 'The new universe is live';
                box.querySelector('.nu-when').style.display = 'none';
                box.querySelector('.nu-clock').style.display = 'none';
                box.querySelector('.nu-live').style.display = '';
                clearInterval(timer);
            }
        }
        var timer = setInterval(tick, 1000);
        tick();
    })();
</script>
@endif
