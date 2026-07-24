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

        <h3>1. System Depletion (Don't Spam Slot 16!)</h3>
        <p>If you keep sending expeditions to the exact same system over and over, you will deplete the sector.</p>
        <ul>
            <li>A heavily visited system has a drastically higher chance of returning <strong>"Nothing."</strong></li>
            <li><strong>How to check:</strong> Send at least <strong>1 Espionage Probe</strong> with your expedition fleet. When the fleet arrives, the probe will scan the sector and send you a message with the system's "Depletion Level" (0% is fresh, 100% is empty).</li>
            <li>Systems naturally recover over time (regenerating around 2 visits worth of resources per hour), so rotate your fleets to different systems!</li>
        </ul>

        <div class="highlight-box">
            <strong>Pro Tip:</strong> Don't just blindly send to your home system. Fly to neighboring systems to find fresh expedition space!
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
