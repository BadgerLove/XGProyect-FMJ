@extends('master.game')

@section('content')
<style>
    .sim-container { max-width: 800px; margin: 0 auto; }
    .sim-section {
        background: #1a2a40;
        border: 1px solid #415680;
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 16px;
    }
    .sim-section h3 {
        color: #b1daf2;
        font-size: 15px;
        margin: 0 0 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid #415680;
    }
    .sim-section.attacker { border-left: 3px solid #4CAF50; }
    .sim-section.defender { border-left: 3px solid #f44336; }
    .sim-section.research { border-left: 3px solid #2196F3; }
    .sim-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .sim-field {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 4px 0;
    }
    .sim-field label {
        flex: 1;
        font-size: 12px;
        color: #b1daf2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sim-field input {
        width: 80px;
        background: #0d1b2a;
        border: 1px solid #415680;
        color: #E6EBFB;
        padding: 6px 8px;
        font-size: 13px;
        text-align: right;
        border-radius: 4px;
    }
    .sim-field input:focus {
        border-color: #619fc8;
        outline: none;
    }
    .sim-research-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 8px;
    }
    .sim-research-field {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .sim-research-field label {
        font-size: 12px;
        color: #b1daf2;
    }
    .sim-research-field input {
        width: 100%;
        background: #0d1b2a;
        border: 1px solid #415680;
        color: #E6EBFB;
        padding: 6px 8px;
        font-size: 13px;
        text-align: center;
        border-radius: 4px;
    }
    .sim-btn {
        display: block;
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #415680, #2a3a5c);
        color: #fff;
        border: 1px solid #5a7aaa;
        border-radius: 8px;
        font-size: 16px;
        font-weight: bold;
        cursor: pointer;
        text-transform: uppercase;
        letter-spacing: 1px;
        transition: background 0.2s;
    }
    .sim-btn:hover { background: linear-gradient(135deg, #5a7aaa, #415680); }
    .sim-btn:disabled { opacity: 0.5; cursor: not-allowed; }

    /* Results */
    #simResults { display: none; }
    .result-banner {
        text-align: center;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 16px;
        font-size: 20px;
        font-weight: bold;
    }
    .result-banner.attacker-wins { background: #1b3a1b; border: 2px solid #4CAF50; color: #4CAF50; }
    .result-banner.defender-wins { background: #3a1b1b; border: 2px solid #f44336; color: #f44336; }
    .result-banner.draw { background: #2a2a1b; border: 2px solid #ff9800; color: #ff9800; }
    .result-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .result-box {
        background: #1a2a40;
        border: 1px solid #415680;
        border-radius: 8px;
        padding: 16px;
    }
    .result-box h4 {
        color: #b1daf2;
        font-size: 14px;
        margin: 0 0 8px;
    }
    .result-stat {
        display: flex;
        justify-content: space-between;
        padding: 4px 0;
        font-size: 13px;
        border-bottom: 1px solid #0d1b2a;
    }
    .result-stat:last-child { border-bottom: 0; }
    .result-stat .label { color: #848484; }
    .result-stat .value { color: #E6EBFB; font-weight: bold; }
    .result-stat .value.loss { color: #f44336; }
    .result-stat .value.win { color: #4CAF50; }
    .loot-box {
        background: #1b2a1b;
        border: 1px solid #4CAF50;
        border-radius: 8px;
        padding: 16px;
        margin-top: 16px;
    }
    .loot-box h4 { color: #4CAF50; margin: 0 0 8px; font-size: 14px; }
    .sim-resources-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 8px;
    }
    .info-text {
        color: #5b6a76;
        font-size: 11px;
        font-style: italic;
        margin: 8px 0;
    }
</style>

<div class="sim-container">
    <form id="simForm" onsubmit="runSimulation(event)">
        <!-- ATTACKER -->
        <div class="sim-section attacker">
            <h3>⚔️ Attacker Fleet</h3>
            <div class="sim-grid">
                @foreach([
                    202 => 'Small Cargo', 203 => 'Large Cargo', 204 => 'Light Fighter',
                    205 => 'Heavy Fighter', 206 => 'Cruiser', 207 => 'Battleship',
                    208 => 'Colony Ship', 209 => 'Recycler', 210 => 'Espionage Probe',
                    211 => 'Bomber', 213 => 'Destroyer', 214 => 'Deathstar', 215 => 'Reaper'
                ] as $id => $name)
                <div class="sim-field">
                    <label for="atk_{{ $id }}">{{ $name }}</label>
                    <input type="number" id="atk_{{ $id }}" name="attacker_ships[{{ $id }}]" value="0" min="0">
                </div>
                @endforeach
            </div>
            <div class="sim-research-grid" style="margin-top:12px;">
                <div class="sim-research-field">
                    <label>Weapons Tech</label>
                    <input type="number" id="atk_weapons" name="attacker_weapons" value="0" min="0" max="30">
                </div>
                <div class="sim-research-field">
                    <label>Shielding Tech</label>
                    <input type="number" id="atk_shielding" name="attacker_shielding" value="0" min="0" max="30">
                </div>
                <div class="sim-research-field">
                    <label>Armour Tech</label>
                    <input type="number" id="atk_armour" name="attacker_armour" value="0" min="0" max="30">
                </div>
            </div>
        </div>

        <!-- DEFENDER -->
        <div class="sim-section defender">
            <h3>🛡️ Defender Fleet & Defenses</h3>
            <div class="sim-grid">
                @foreach([
                    202 => 'Small Cargo', 203 => 'Large Cargo', 204 => 'Light Fighter',
                    205 => 'Heavy Fighter', 206 => 'Cruiser', 207 => 'Battleship',
                    208 => 'Colony Ship', 209 => 'Recycler', 210 => 'Espionage Probe',
                    211 => 'Bomber', 213 => 'Destroyer', 214 => 'Deathstar', 215 => 'Reaper'
                ] as $id => $name)
                <div class="sim-field">
                    <label for="def_{{ $id }}">{{ $name }}</label>
                    <input type="number" id="def_{{ $id }}" name="defender_ships[{{ $id }}]" value="0" min="0">
                </div>
                @endforeach
            </div>
            <h3 style="margin-top:12px;">Defenses</h3>
            <div class="sim-grid">
                @foreach([
                    401 => 'Rocket Launcher', 402 => 'Light Laser', 403 => 'Heavy Laser',
                    404 => 'Gauss Cannon', 405 => 'Ion Cannon', 406 => 'Plasma Turret',
                    502 => 'Small Shield Dome', 503 => 'Large Shield Dome'
                ] as $id => $name)
                <div class="sim-field">
                    <label for="defd_{{ $id }}">{{ $name }}</label>
                    <input type="number" id="defd_{{ $id }}" name="defender_defenses[{{ $id }}]" value="0" min="0">
                </div>
                @endforeach
            </div>
            <div class="sim-research-grid" style="margin-top:12px;">
                <div class="sim-research-field">
                    <label>Weapons Tech</label>
                    <input type="number" id="def_weapons" name="defender_weapons" value="0" min="0" max="30">
                </div>
                <div class="sim-research-field">
                    <label>Shielding Tech</label>
                    <input type="number" id="def_shielding" name="defender_shielding" value="0" min="0" max="30">
                </div>
                <div class="sim-research-field">
                    <label>Armour Tech</label>
                    <input type="number" id="def_armour" name="defender_armour" value="0" min="0" max="30">
                </div>
            </div>
            <h3 style="margin-top:12px;">Resources (for loot estimate)</h3>
            <p class="info-text">Optional — enter defender's current resources to estimate potential loot.</p>
            <div class="sim-resources-grid">
                <div class="sim-research-field">
                    <label>Metal</label>
                    <input type="number" id="def_metal" name="defender_metal" value="0" min="0">
                </div>
                <div class="sim-research-field">
                    <label>Crystal</label>
                    <input type="number" id="def_crystal" name="defender_crystal" value="0" min="0">
                </div>
                <div class="sim-research-field">
                    <label>Deuterium</label>
                    <input type="number" id="def_deuterium" name="defender_deuterium" value="0" min="0">
                </div>
            </div>
        </div>

        <button type="submit" class="sim-btn" id="simBtn">⚔️ Simulate Battle</button>
    </form>

    <!-- RESULTS -->
    <div id="simResults">
        <div id="resultBanner" class="result-banner"></div>
        <div class="result-grid">
            <div class="result-box">
                <h4 style="color:#4CAF50;">⚔️ Attacker</h4>
                <div id="attackerStats"></div>
            </div>
            <div class="result-box">
                <h4 style="color:#f44336;">🛡️ Defender</h4>
                <div id="defenderStats"></div>
            </div>
        </div>
        <div id="lootBox" class="loot-box" style="display:none;">
            <h4>💰 Estimated Loot</h4>
            <div id="lootStats"></div>
        </div>
    </div>
</div>

<script>
// Pre-fill from URL parameters (from espionage report)
document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);

    // Attacker tech
    if (params.get('atk_weapons')) document.getElementById('atk_weapons').value = params.get('atk_weapons');
    if (params.get('atk_shielding')) document.getElementById('atk_shielding').value = params.get('atk_shielding');
    if (params.get('atk_armour')) document.getElementById('atk_armour').value = params.get('atk_armour');

    // Defender tech
    if (params.get('def_weapons')) document.getElementById('def_weapons').value = params.get('def_weapons');
    if (params.get('def_shielding')) document.getElementById('def_shielding').value = params.get('def_shielding');
    if (params.get('def_armour')) document.getElementById('def_armour').value = params.get('def_armour');

    // Defender resources
    if (params.get('def_metal')) document.getElementById('def_metal').value = params.get('def_metal');
    if (params.get('def_crystal')) document.getElementById('def_crystal').value = params.get('def_crystal');
    if (params.get('def_deuterium')) document.getElementById('def_deuterium').value = params.get('def_deuterium');

    // Ships: atk_202=10&atk_204=50 etc.
    params.forEach(function(value, key) {
        var el = document.getElementById(key);
        if (el && parseInt(value) > 0) {
            el.value = value;
        }
    });
});

function runSimulation(e) {
    e.preventDefault();

    var btn = document.getElementById('simBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Simulating...';

    // Collect form data
    var shipIds = [202,203,204,205,206,207,208,209,210,211,213,214,215];
    var defenseIds = [401,402,403,404,405,406,502,503];

    var attackerShips = {};
    shipIds.forEach(function(id) {
        var el = document.getElementById('atk_' + id);
        attackerShips[id] = parseInt(el ? el.value : 0) || 0;
    });

    var defenderShips = {};
    shipIds.forEach(function(id) {
        var el = document.getElementById('def_' + id);
        defenderShips[id] = parseInt(el ? el.value : 0) || 0;
    });

    var defenderDefenses = {};
    defenseIds.forEach(function(id) {
        var el = document.getElementById('defd_' + id);
        defenderDefenses[id] = parseInt(el ? el.value : 0) || 0;
    });

    var data = {
        attacker_ships: attackerShips,
        attacker_weapons: parseInt(document.getElementById('atk_weapons').value) || 0,
        attacker_shielding: parseInt(document.getElementById('atk_shielding').value) || 0,
        attacker_armour: parseInt(document.getElementById('atk_armour').value) || 0,
        defender_ships: defenderShips,
        defender_defenses: defenderDefenses,
        defender_weapons: parseInt(document.getElementById('def_weapons').value) || 0,
        defender_shielding: parseInt(document.getElementById('def_shielding').value) || 0,
        defender_armour: parseInt(document.getElementById('def_armour').value) || 0,
        defender_metal: parseInt(document.getElementById('def_metal').value) || 0,
        defender_crystal: parseInt(document.getElementById('def_crystal').value) || 0,
        defender_deuterium: parseInt(document.getElementById('def_deuterium').value) || 0
    };

    fetch('/game/battle-simulator/simulate', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]') ?
                document.querySelector('meta[name="csrf-token"]').content : '',
            'Accept': 'application/json'
        },
        body: JSON.stringify(data)
    })
    .then(function(response) {
        if (!response.ok) {
            return response.text().then(function(text) {
                throw new Error('Server error ' + response.status + ': ' + text.substring(0, 200));
            });
        }
        return response.json();
    })
    .then(function(result) {
        btn.disabled = false;
        btn.textContent = '⚔️ Simulate Battle';
        showResults(result, data);
    })
    .catch(function(error) {
        btn.disabled = false;
        btn.textContent = '⚔️ Simulate Battle';
        alert('Simulation error: ' + error.message);
    });
}

function showResults(result, data) {
    var resultsDiv = document.getElementById('simResults');
    resultsDiv.style.display = 'block';

    // Banner
    var banner = document.getElementById('resultBanner');
    if (result.winner === 'attacker') {
        banner.className = 'result-banner attacker-wins';
        banner.textContent = '⚔️ Attacker Wins!';
    } else if (result.winner === 'defender') {
        banner.className = 'result-banner defender-wins';
        banner.textContent = '🛡️ Defender Wins!';
    } else {
        banner.className = 'result-banner draw';
        banner.textContent = '🤝 Draw — Both Destroyed';
    }

    // Calculate totals
    var attackerTotal = 0;
    Object.values(data.attacker_ships).forEach(function(v) { attackerTotal += v; });
    var defenderTotal = 0;
    Object.values(data.defender_ships).forEach(function(v) { defenderTotal += v; });
    Object.values(data.defender_defenses).forEach(function(v) { defenderTotal += v; });

    var attackerLossPct = attackerTotal > 0 ? Math.round((result.attacker_losses / attackerTotal) * 100) : 0;
    var defenderLossPct = defenderTotal > 0 ? Math.round((result.defender_losses / defenderTotal) * 100) : 0;

    // Attacker stats
    document.getElementById('attackerStats').innerHTML =
        '<div class="result-stat"><span class="label">Units Lost</span><span class="value loss">' + formatNum(result.attacker_losses) + '</span></div>' +
        '<div class="result-stat"><span class="label">Losses</span><span class="value loss">' + attackerLossPct + '%</span></div>' +
        '<div class="result-stat"><span class="label">Units Remaining</span><span class="value win">' + formatNum(result.attacker_ships_remaining) + '</span></div>';

    // Defender stats
    document.getElementById('defenderStats').innerHTML =
        '<div class="result-stat"><span class="label">Units Lost</span><span class="value loss">' + formatNum(result.defender_losses) + '</span></div>' +
        '<div class="result-stat"><span class="label">Losses</span><span class="value loss">' + defenderLossPct + '%</span></div>' +
        '<div class="result-stat"><span class="label">Units Remaining</span><span class="value win">' + formatNum(result.defender_ships_remaining) + '</span></div>';

    // Rounds
    var rounds = result.rounds || '?';
    document.getElementById('attackerStats').innerHTML +=
        '<div class="result-stat"><span class="label">Rounds Fought</span><span class="value">' + rounds + '</span></div>';

    // Loot
    var lootBox = document.getElementById('lootBox');
    var lootHtml = '';
    if (result.winner === 'attacker' && (result.loot_metal > 0 || result.loot_crystal > 0 || result.loot_deuterium > 0)) {
        lootHtml += '<h4>💰 Resources Plundered</h4>';
        lootHtml += '<div class="result-stat"><span class="label">🔩 Metal</span><span class="value">' + formatNum(result.loot_metal) + '</span></div>';
        lootHtml += '<div class="result-stat"><span class="label">💎 Crystal</span><span class="value">' + formatNum(result.loot_crystal) + '</span></div>';
        lootHtml += '<div class="result-stat"><span class="label">💧 Deuterium</span><span class="value">' + formatNum(result.loot_deuterium) + '</span></div>';
    }

    // Debris field
    if (result.debris_metal > 0 || result.debris_crystal > 0) {
        lootHtml += '<h4 style="margin-top:12px;">🌀 Debris Field</h4>';
        lootHtml += '<div class="result-stat"><span class="label">🔩 Metal</span><span class="value">' + formatNum(result.debris_metal) + '</span></div>';
        lootHtml += '<div class="result-stat"><span class="label">💎 Crystal</span><span class="value">' + formatNum(result.debris_crystal) + '</span></div>';
        if (result.moon_chance > 0) {
            lootHtml += '<div class="result-stat"><span class="label">🌙 Moon Chance</span><span class="value">' + result.moon_chance + '%</span></div>';
        }
    }

    if (lootHtml) {
        lootBox.style.display = 'block';
        lootBox.innerHTML = lootHtml;
    } else {
        lootBox.style.display = 'none';
    }

    // Scroll to results
    resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function formatNum(n) {
    return Number(n).toLocaleString();
}
</script>
@endsection
