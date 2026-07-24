<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Services\Bot\BattleSimulator;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class BattleSimulatorController extends Controller
{
    public function __invoke(SettingsService $settingsService)
    {
        return view('battlesimulator.view', [
            'gameTitle' => $settingsService->getString('game_name') . ' - Battle Simulator',
        ]);
    }

    public function simulate(Request $request)
    {
        // Load battle engine constants (not auto-loaded)
        require base_path('legacy/app/Libraries/BattleEngine/Utils/Includer.php');

        $validated = $request->validate([
            'attacker_ships' => 'required|array',
            'attacker_ships.*' => 'integer|min:0',
            'attacker_weapons' => 'required|integer|min:0|max:30',
            'attacker_shielding' => 'required|integer|min:0|max:30',
            'attacker_armour' => 'required|integer|min:0|max:30',
            'defender_ships' => 'required|array',
            'defender_ships.*' => 'integer|min:0',
            'defender_defenses' => 'required|array',
            'defender_defenses.*' => 'integer|min:0',
            'defender_weapons' => 'required|integer|min:0|max:30',
            'defender_shielding' => 'required|integer|min:0|max:30',
            'defender_armour' => 'required|integer|min:0|max:30',
            'defender_metal' => 'nullable|integer|min:0',
            'defender_crystal' => 'nullable|integer|min:0',
            'defender_deuterium' => 'nullable|integer|min:0',
        ]);

        $attackerShips = array_filter($validated['attacker_ships'], fn($v) => $v > 0);
        $defenderShips = array_filter($validated['defender_ships'], fn($v) => $v > 0);
        $defenderDefenses = array_filter($validated['defender_defenses'], fn($v) => $v > 0);

        // Map numeric IDs to database column names for the simulator
        $shipMap = [
            202 => 'ship_small_cargo_ship', 203 => 'ship_big_cargo_ship',
            204 => 'ship_light_fighter', 205 => 'ship_heavy_fighter',
            206 => 'ship_cruiser', 207 => 'ship_battleship',
            208 => 'ship_colony_ship', 209 => 'ship_recycler',
            210 => 'ship_espionage_probe', 211 => 'ship_bomber',
            212 => 'ship_solar_satellite', 213 => 'ship_destroyer',
            214 => 'ship_deathstar', 215 => 'ship_reaper',
        ];
        $defenseMap = [
            401 => 'defense_rocket_launcher', 402 => 'defense_light_laser',
            403 => 'defense_heavy_laser', 404 => 'defense_gauss_cannon',
            405 => 'defense_ion_cannon', 406 => 'defense_plasma_turret',
            502 => 'defense_small_shield_dome', 503 => 'defense_large_shield_dome',
        ];

        $defenderPlanet = ['planet_metal' => $validated['defender_metal'] ?? 0, 'planet_crystal' => $validated['defender_crystal'] ?? 0, 'planet_deuterium' => $validated['defender_deuterium'] ?? 0];
        foreach ($defenderShips as $id => $count) {
            if (isset($shipMap[$id])) $defenderPlanet[$shipMap[$id]] = $count;
        }
        foreach ($defenderDefenses as $id => $count) {
            if (isset($defenseMap[$id])) $defenderPlanet[$defenseMap[$id]] = $count;
        }

        $attackerUser = [
            'research_weapons_technology' => $validated['attacker_weapons'],
            'research_shielding_technology' => $validated['attacker_shielding'],
            'research_armour_technology' => $validated['attacker_armour'],
        ];

        $defenderUser = [
            'research_weapons_technology' => $validated['defender_weapons'],
            'research_shielding_technology' => $validated['defender_shielding'],
            'research_armour_technology' => $validated['defender_armour'],
        ];

        $simulator = new BattleSimulator();
        $result = $simulator->simulate($attackerShips, $defenderPlanet, $attackerUser, $defenderUser);

        return response()->json($result);
    }
}
