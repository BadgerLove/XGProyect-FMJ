@extends('master.game')

@section('content')
<style>
    /* Battle simulator in the game's own look (2026-10-01): the same #344566 cells, #415680
       borders, Tahoma and td.c header bars as every other page, 519px wide like them. It used
       to be a rounded, gradient "app" design that did not look like part of the game. */
    .sim-container { max-width: 519px; margin: 0 auto; font-family: Tahoma, sans-serif; font-size: 11px; }
    .sim-title {
        font-weight: bold;
        color: #E6EBFB;
        text-align: center;
        padding: 4px;
        border: 1px solid #415680;
        background: #344566 url({{ asset('assets/upload/skins/xgproyect/img/bg1.gif') }});
        margin-bottom: 4px;
    }
    .sim-section {
        border: 1px solid #415680;
        background: #344566;
        margin-bottom: 4px;
    }
    .sim-section h3 {
        font-size: 11px;
        font-weight: bold;
        color: #E6EBFB;
        margin: 0;
        padding: 4px 6px;
        border-bottom: 1px solid #415680;
        background: #344566 url({{ asset('assets/upload/skins/xgproyect/img/bg1.gif') }});
    }
    .sim-section h3 + .sim-grid, .sim-section h3 + .sim-research-grid, .sim-section .info-text + .sim-resources-grid { margin-top: 0; }
    .sim-section.attacker h3:first-child { color: #7CFC00; }
    .sim-section.defender h3:first-child { color: #ff6b6b; }
    .sim-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1px;
        background: #415680;
        border-bottom: 1px solid #415680;
    }
    /* odd count (13 ships): the last one spans the row instead of leaving a gap */
    .sim-grid > .sim-field:last-child:nth-child(odd) { grid-column: 1 / -1; }
    .sim-field {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 3px 6px;
        background: #344566;
    }
    .sim-field label {
        flex: 1;
        min-width: 0;
        color: #E6EBFB;
        font-weight: bold;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sim-field input,
    .sim-research-field input {
        width: 80px;
        box-sizing: border-box;
        background: #1a2a40;
        border: 1px solid #415680;
        color: #E6EBFB;
        padding: 2px 4px;
        font: 11px Tahoma, sans-serif;
        text-align: right;
    }
    .sim-field input:focus,
    .sim-research-field input:focus { border-color: #9ab7e0; outline: none; }
    .sim-research-grid,
    .sim-resources-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 1px;
        background: #415680;
    }
    .sim-research-field {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 3px;
        padding: 4px 6px;
        background: #344566;
    }
    .sim-research-field label { color: #E6EBFB; font-weight: bold; }
    .sim-research-field input { width: 100%; text-align: center; }
    .sim-btn {
        display: block;
        width: 100%;
        padding: 6px;
        background: #344566;
        color: #E6EBFB;
        border: 1px solid #415680;
        font: bold 12px Tahoma, sans-serif;
        cursor: pointer;
    }
    .sim-btn:hover { background: #415680; }
    .sim-btn:disabled { opacity: 0.5; cursor: not-allowed; }

    /* Results */
    #simResults { display: none; margin-top: 4px; }
    .result-banner {
        text-align: center;
        padding: 8px;
        margin-bottom: 4px;
        font-size: 14px;
        font-weight: bold;
        border: 1px solid #415680;
        background: #344566 url({{ asset('assets/upload/skins/xgproyect/img/bg1.gif') }});
    }
    .result-banner.attacker-wins { color: #7CFC00; }
    .result-banner.defender-wins { color: #ff6b6b; }
    .result-banner.draw { color: #ffb347; }
    .result-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 4px;
    }
    .result-box {
        border: 1px solid #415680;
        background: #344566;
        padding: 0 0 4px;
        min-width: 0;
    }
    .result-box h4 {
        font-size: 11px;
        margin: 0 0 4px;
        padding: 4px 6px;
        border-bottom: 1px solid #415680;
        background: #344566 url({{ asset('assets/upload/skins/xgproyect/img/bg1.gif') }});
    }
    .result-box > div { padding: 0 6px; }
    .result-stat {
        display: flex;
        justify-content: space-between;
        gap: 6px;
        padding: 3px 0;
        border-bottom: 1px solid #415680;
    }
    .result-stat:last-child { border-bottom: 0; }
    .result-stat .label { color: #b1daf2; }
    .result-stat .value { color: #E6EBFB; font-weight: bold; text-align: right; }
    .result-stat .value.loss { color: #ff6b6b; }
    .result-stat .value.win { color: #7CFC00; }
    .cost-total { margin-top: 6px; padding-top: 4px; border-top: 1px solid #415680; }
    .cost-total .label { color: #b1daf2; }
    .cost-total .value { color: #E6EBFB; font-weight: bold; }
    .loot-box {
        border: 1px solid #415680;
        background: #344566;
        margin-top: 4px;
        padding: 0 0 4px;
    }
    .loot-box h4 {
        color: #7CFC00;
        font-size: 11px;
        margin: 0 0 4px;
        padding: 4px 6px;
        border-bottom: 1px solid #415680;
        background: #344566 url({{ asset('assets/upload/skins/xgproyect/img/bg1.gif') }});
    }
    .loot-box > div { padding: 0 6px; }
    .info-text {
        color: #b1daf2;
        font-style: italic;
        margin: 0;
        padding: 4px 6px;
    }
    .detail-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 2px;
    }
    .detail-table th {
        text-align: left;
        color: #b1daf2;
        font-weight: bold;
        padding: 3px 4px;
        background: transparent;
        border: 0;
        border-bottom: 1px solid #415680;
    }
    .detail-table th.num { text-align: right; }
    .detail-table td {
        padding: 2px 4px;
        border-bottom: 1px solid #2a3a5c;
        color: #E6EBFB;
    }
    .detail-table td.num { text-align: right; font-weight: bold; }
    .detail-table td.lost,
    .detail-table td.destroyed { color: #ff6b6b; }
    .detail-table td.survived { color: #7CFC00; }
    .result-cost {
        font-size: 10px;
        color: #b1daf2;
        display: block;
        margin-top: 1px;
    }

    /* Phones: beat mobile.css's big touch-size inputs, one results column */
    @media (max-width: 768px) {
        #content .sim-container input[type="number"] {
            font-size: 14px !important;
            padding: 4px 6px !important;
            min-height: 32px;
        }
        #content .sim-container .sim-field input[type="number"] { width: 72px; }
        #content .sim-container .sim-btn {
            font-size: 14px !important;
            padding: 10px !important;
            min-height: 44px;
        }
        .sim-field { padding: 3px 4px; gap: 4px; }
        .sim-field label { font-size: 11px; }
        .result-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="sim-container">
    <div class="sim-title">{{ __('game/menu.lm_battlesimulator') }}</div>
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
                    <input type="number" id="atk_{{ $id }}" name="attacker_ships[{{ $id }}]" value="" min="0" placeholder="0">
                </div>
                @endforeach
            </div>
            <div class="sim-research-grid" style="margin-top:12px;">
                <div class="sim-research-field">
                    <label>Weapons Tech</label>
                    <input type="number" id="atk_weapons" name="attacker_weapons" value="" min="0" max="30" placeholder="0">
                </div>
                <div class="sim-research-field">
                    <label>Shielding Tech</label>
                    <input type="number" id="atk_shielding" name="attacker_shielding" value="" min="0" max="30" placeholder="0">
                </div>
                <div class="sim-research-field">
                    <label>Armour Tech</label>
                    <input type="number" id="atk_armour" name="attacker_armour" value="" min="0" max="30" placeholder="0">
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
                    <input type="number" id="def_{{ $id }}" name="defender_ships[{{ $id }}]" value="" min="0" placeholder="0">
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
                    <input type="number" id="defd_{{ $id }}" name="defender_defenses[{{ $id }}]" value="" min="0" placeholder="0">
                </div>
                @endforeach
            </div>
            <div class="sim-research-grid" style="margin-top:12px;">
                <div class="sim-research-field">
                    <label>Weapons Tech</label>
                    <input type="number" id="def_weapons" name="defender_weapons" value="" min="0" max="30" placeholder="0">
                </div>
                <div class="sim-research-field">
                    <label>Shielding Tech</label>
                    <input type="number" id="def_shielding" name="defender_shielding" value="" min="0" max="30" placeholder="0">
                </div>
                <div class="sim-research-field">
                    <label>Armour Tech</label>
                    <input type="number" id="def_armour" name="defender_armour" value="" min="0" max="30" placeholder="0">
                </div>
            </div>
            <h3 style="margin-top:12px;">Resources (for loot estimate)</h3>
            <p class="info-text">Optional — enter defender's current resources to estimate potential loot.</p>
            <div class="sim-resources-grid">
                <div class="sim-research-field">
                    <label>Metal</label>
                    <input type="number" id="def_metal" name="defender_metal" value="" min="0" placeholder="0">
                </div>
                <div class="sim-research-field">
                    <label>Crystal</label>
                    <input type="number" id="def_crystal" name="defender_crystal" value="" min="0" placeholder="0">
                </div>
                <div class="sim-research-field">
                    <label>Deuterium</label>
                    <input type="number" id="def_deuterium" name="defender_deuterium" value="" min="0" placeholder="0">
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
                <h4 style="color:#4CAF50;">⚔️ Attacker Fleet</h4>
                <div id="attackerDetail"></div>
                <div id="attackerTotals"></div>
            </div>
            <div class="result-box">
                <h4 style="color:#f44336;">🛡️ Defender</h4>
                <div id="defenderDetail"></div>
                <div id="defenderTotals"></div>
            </div>
        </div>
        <div id="lootBox" class="loot-box" style="display:none;">
            <h4>💰 Loot &amp; Aftermath</h4>
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

// Ship/defense name lookup
var SHIP_NAMES = {
    202: 'Small Cargo', 203: 'Large Cargo', 204: 'Light Fighter',
    205: 'Heavy Fighter', 206: 'Cruiser', 207: 'Battleship',
    208: 'Colony Ship', 209: 'Recycler', 210: 'Espionage Probe',
    211: 'Bomber', 212: 'Solar Satellite', 213: 'Destroyer',
    214: 'Deathstar', 215: 'Reaper'
};
var DEFENSE_NAMES = {
    401: 'Rocket Launcher', 402: 'Light Laser', 403: 'Heavy Laser',
    404: 'Gauss Cannon', 405: 'Ion Cannon', 406: 'Plasma Turret',
    502: 'Small Shield Dome', 503: 'Large Shield Dome'
};

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

    // --- Attacker fleet detail table ---
    var atkDetail = result.attacker_ships_detail || {};
    var atkTotalInitial = 0, atkTotalFinal = 0;
    var atkHtml = buildDetailTable(atkDetail, SHIP_NAMES, 'attacker_ships_detail');

    // Attacker loss %
    if (atkTotalInitial > 0) {
        var atkLossPct = Math.round(((atkTotalInitial - atkTotalFinal) / atkTotalInitial) * 100);
    } else {
        var atkLossPct = 0;
    }

    // Calculate total lost costs
    var atkMetalLost = 0, atkCrystalLost = 0;
    var atkCosts = result.attacker_lost_costs || {};
    Object.values(atkCosts).forEach(function(c) {
        atkMetalLost += (c.metal || 0);
        atkCrystalLost += (c.crystal || 0);
    });

    // Build totals HTML
    var atkTotalsHtml = buildTotalsRow(atkDetail, result.attacker_losses, 'attacker');

    // --- Defender detail ---
    var defShips = result.defender_ships_detail || {};
    var defDefenses = result.defender_defenses_detail || {};

    var defShipHtml = buildDetailTable(defShips, SHIP_NAMES, 'defender_ships_detail');
    var defDefHtml = buildDetailTable(defDefenses, DEFENSE_NAMES, 'defender_defenses_detail');

    // Defender totals
    var defLosses = result.defender_losses || 0;
    var defMetalLost = 0, defCrystalLost = 0;
    var defCosts = result.defender_lost_costs || {};
    Object.values(defCosts).forEach(function(c) {
        defMetalLost += (c.metal || 0);
        defCrystalLost += (c.crystal || 0);
    });

    // Combine all for loss %
    var allDefDetail = Object.assign({}, defShips, defDefenses);
    var defTotalsHtml = buildTotalsRow(allDefDetail, defLosses, 'defender');

    // --- Render ---
    document.getElementById('attackerDetail').innerHTML = atkHtml;
    document.getElementById('attackerTotals').innerHTML = atkTotalsHtml;

    var defFullHtml = defShipHtml;
    if (Object.keys(defDefenses).length > 0) {
        defFullHtml += '<h5 style="color:#848484;font-size:12px;margin:10px 0 4px;">🛡️ Defenses</h5>' + defDefHtml;
    }
    document.getElementById('defenderDetail').innerHTML = defFullHtml;
    document.getElementById('defenderTotals').innerHTML = defTotalsHtml;

    // --- Cost totals ---
    var costHtml = '';
    if (atkMetalLost + atkCrystalLost > 0) {
        costHtml += '<div class="cost-total"><span class="label">⚔️ Attacker Loss Cost</span> ';
        costHtml += '<span class="value">🔩 ' + formatNum(atkMetalLost) + '  💎 ' + formatNum(atkCrystalLost) + '</span></div>';
    }
    if (defMetalLost + defCrystalLost > 0) {
        costHtml += '<div class="cost-total"><span class="label">🛡️ Defender Loss Cost</span> ';
        costHtml += '<span class="value">🔩 ' + formatNum(defMetalLost) + '  💎 ' + formatNum(defCrystalLost) + '</span></div>';
    }

    // Rounds
    if (result.rounds) {
        costHtml += '<div class="cost-total"><span class="label">⚡ Rounds Fought</span> <span class="value">' + result.rounds + '</span></div>';
    }

    document.getElementById('attackerTotals').innerHTML += costHtml;

    // --- Loot & aftermath ---
    var lootBox = document.getElementById('lootBox');
    var lootHtml = '';

    // Plunder
    if (result.winner === 'attacker' && (result.loot_metal > 0 || result.loot_crystal > 0 || result.loot_deuterium > 0)) {
        lootHtml += '<h4>💰 Resources Plundered</h4>';
        lootHtml += '<div class="result-stat"><span class="label">🔩 Metal</span><span class="value">' + formatNum(result.loot_metal) + '</span></div>';
        lootHtml += '<div class="result-stat"><span class="label">💎 Crystal</span><span class="value">' + formatNum(result.loot_crystal) + '</span></div>';
        lootHtml += '<div class="result-stat"><span class="label">💧 Deuterium</span><span class="value">' + formatNum(result.loot_deuterium) + '</span></div>';
    }

    // Debris
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

function buildDetailTable(detail, nameMap, key) {
    if (!detail || Object.keys(detail).length === 0) {
        return '<p style="color:#848484;font-size:12px;">No units deployed.</p>';
    }

    var html = '<table class="detail-table"><thead><tr>';
    html += '<th>Unit</th>';
    html += '<th class="num">Initial</th>';
    html += '<th class="num">Final</th>';
    html += '</tr></thead><tbody>';

    var shipIds = Object.keys(detail).map(Number).sort(function(a,b) { return a - b; });
    shipIds.forEach(function(id) {
        var d = detail[id];
        var name = nameMap[id] || ('#' + id);
        var lost = d.initial - d.final;

        html += '<tr>';
        html += '<td>' + name + '</td>';
        html += '<td class="num">' + formatNum(d.initial) + '</td>';

        if (d.final === 0) {
            html += '<td class="num destroyed">0</td>';
        } else if (d.final >= d.initial) {
            html += '<td class="num survived">' + formatNum(d.final) + '</td>';
        } else {
            html += '<td class="num survived">' + formatNum(d.final) + '</td>';
        }
        html += '</tr>';
    });

    html += '</tbody></table>';
    return html;
}

function buildTotalsRow(detail, totalLost, side) {
    var totalInitial = 0, totalFinal = 0;
    Object.values(detail).forEach(function(d) {
        totalInitial += d.initial || 0;
        totalFinal += d.final || 0;
    });

    if (totalInitial === 0) return '';

    var lossPct = Math.round(((totalInitial - totalFinal) / totalInitial) * 100);
    var cls = side === 'attacker' ? 'win' : 'loss';

    return '<div class="result-stat" style="margin-top:8px;font-weight:bold;border-top:1px solid #415680;padding-top:6px;">' +
        '<span class="label">Units Lost</span>' +
        '<span class="value ' + cls + '">' + formatNum(totalLost) + ' (' + lossPct + '%)</span></div>' +
        '<div class="result-stat">' +
        '<span class="label">Units Remaining</span>' +
        '<span class="value">' + formatNum(totalFinal) + '</span></div>';
}

function formatNum(n) {
    return Number(n).toLocaleString();
}
</script>
@endsection
