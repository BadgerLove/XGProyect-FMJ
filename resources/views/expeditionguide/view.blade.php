@extends('master.game')

@section('content')
<style>
    .guide-container { max-width: 800px; margin: 0 auto; text-align: left; }
    .guide-section {
        background: #1a2a40;
        border: 1px solid #415680;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        color: #c9d0df;
        line-height: 1.6;
    }
    .guide-section h2 {
        color: #ff9d00;
        font-size: 18px;
        margin: 0 0 16px;
        padding-bottom: 8px;
        border-bottom: 1px solid #415680;
    }
    .guide-section h3 {
        color: #b1daf2;
        font-size: 15px;
        margin: 16px 0 8px;
    }
    .guide-section ul {
        margin: 8px 0 16px 20px;
        padding: 0;
    }
    .guide-section li {
        margin-bottom: 6px;
    }
    .guide-section strong {
        color: #ffffff;
    }
    .highlight-box {
        background: rgba(255, 157, 0, 0.1);
        border-left: 3px solid #ff9d00;
        padding: 12px 16px;
        margin: 16px 0;
        border-radius: 0 4px 4px 0;
    }
</style>

<div class="guide-container">
    <div class="guide-section">
        <h2>The Official Guide to Expeditions</h2>
        <p>Expeditions are a great way to find resources, dark matter, and even free ships. But space is vast, dangerous, and resources aren't infinite. Here is exactly how expeditions work so you can maximize your gains and avoid getting your fleet wiped out.</p>

        <h3>1. Expedition Space (Check Before You Send!)</h3>
        <p>Every system only has room for a limited number of good expeditions, and it's shared by everyone: other players and the bots too.</p>
        <ul>
            <li>Each system has a <strong>hidden number of expedition spaces</strong> for every 6-hour period (00:00, 06:00, 12:00 and 18:00 UK time). The number is random each period: usually <strong>1 to 6</strong>, very rarely up to 15.</li>
            <li>Your expedition takes a space when it <strong>arrives</strong>. If the system is under 100% when your fleet gets there, you have a space, even if the system fills up while you are exploring.</li>
            <li>If the system is <strong>full (100%)</strong> when you arrive, your fleet finds the area <strong>picked clean</strong> and comes home with nothing.</li>
            <li>Fleet size doesn't matter: one ship or a thousand, one expedition uses one space.</li>
            <li><strong>How to check:</strong> The galaxy view shows <strong>"Expedition space: N% used"</strong> at the top right: <span style="color: #2ea043;">green</span> under 50%, <span style="color: #d69614;">amber</span> 50-99%, <span style="color: #c83737;">red</span> when full. Take at least <strong>1 Espionage Probe</strong> with your expedition and it also sends you a report with the system's %.</li>
            <li>The % is only a hint: one expedition can fill a system that only had one space. Every system starts fresh at the next period.</li>
        </ul>

        <div class="highlight-box">
            <strong>Pro Tip:</strong> Don't just send to your home system every time. Flick through the neighbouring systems with the galaxy arrows and send your expedition to a green one!
        </div>

        <h3>2. Expedition Limits</h3>
        <p>You can't send an unlimited number of expeditions at once. The maximum number of expedition fleets you can have flying is based on your <strong>Astrophysics</strong> research level.</p>
        <ul>
            <li>Level 1: 1 expedition</li>
            <li>Level 4: 2 expeditions</li>
            <li>Level 9: 3 expeditions</li>
            <li>Level 16: 4 expeditions</li>
            <li>Level 25: 5 expeditions</li>
        </ul>

        <h3>3. Finding Ships (Bring It to Find It)</h3>
        <p>You have a ~22% chance to stumble upon an abandoned fleet. However, the system is strict: <strong>you can only find copies of ships you already brought with you.</strong></p>
        <ul>
            <li>If you only send Light Fighters, you will only find Light Fighters.</li>
            <li>If you want to find a Destroyer or a Reaper, your fleet <strong>must</strong> include at least one Destroyer or Reaper.</li>
            <li><em>Note: Colony Ships, Recyclers, and Deathstars cannot be found on expeditions.</em></li>
        </ul>

        <h3>4. The Dangers of Deep Space</h3>
        <p>Expeditions are not entirely safe. There is a risk of encountering hostile forces or cosmic anomalies.</p>
        <ul>
            <li><strong>Pirates (~5.8% chance):</strong> A weak faction. You will lose between 5% and 50% of your fleet depending on the severity of the ambush, but you'll escape with the rest.</li>
            <li><strong>Aliens (~2.6% chance):</strong> A much stronger faction. An alien ambush will destroy between 10% and 80% of your expedition fleet.</li>
            <li><strong>Black Holes (0.33% chance):</strong> Very rare, but devastating. A black hole will swallow a random chunk of your fleet (1% to 99%). If you are extremely unlucky, the entire fleet will be lost forever.</li>
        </ul>

        <h3>5. Maximizing Your Haul</h3>
        <ul>
            <li><strong>Expedition Points:</strong> Your total haul is based on the metal and crystal value of the ships you send. Sending a massive fleet means massive rewards.</li>
            <li><strong>Cargo Space:</strong> Always bring plenty of Large or Small Cargos. You can't bring home 2 million Metal if you only have 500k cargo capacity!</li>
            <li><strong>Duration Bonus:</strong> The time you spend on the expedition massively multiplies your resource and ship hauls. Prolonging your stay in deep space provides an exponential increase in goods and finds the longer the journey. However, staying out longer also means tying up your fleet and carrying more risk!</li>
        </ul>
    </div>
</div>
@endsection
