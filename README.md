# XGProyect-FMJ

A fork of [XGProyect](https://github.com/XGProyect/XGProyect) v4 — an open-source OGame private server engine.

This fork adds a fully autonomous bot AI system, a rewritten expedition system, mobile-responsive CSS, a merchant exchange feature, and numerous bug fixes.

## What's New

### 🤖 Bot AI System

365 autonomous bots with dynamic decision-making, combat, and personality.

- **ROI-based decision engine** — Days of Investment Return (DOIR) calculates the best building or research at any point. No fixed build orders.
- **Full combat pipeline** — Espionage → intel gathering → battle simulation → attack → loot → debris harvesting.
- **Grudge system** — Bots remember who attacked them. Severity escalates: mild → annoyed → vendetta. Decays after 7 days.
- **Win/loss adaptation** — Bots skip targets they keep losing to.
- **Intel freshness** — Re-spies stale targets (>30 min) before attacking.
- **Resource pressure** — Desperate bots accept riskier fights.
- **Combat chat** — Personality-driven taunts after attacks.
- **Debris harvesting** — Bots recycle their own battle debris.
- **4 personalities** — Raider (42%), Balanced (21%), Turtle (21%), Passive (16%). Each has unique ship priorities, weights, and behaviour.
- **19 timezone patterns** — Bots sleep, wake, and play at different times.
- **Fleet save** — Dodges incoming attacks (moon → planet → skip).
- **Noob protection** — Won't attack players 5× weaker.

### 🚀 Expedition System

Complete rewrite of the expedition mission system.

- **Per-player shuffled deck** — 1000-card deck per player guarantees fair distribution. No more 30-expedition droughts.
- **Scaling ship finds** — Percentage based on YOUR fleet size (Scout 10-100% → Massive 3-20%).
- **Fleet-value resource loot** — Resources scale with your fleet value, not the #1 player's score.
- **System depletion** — Anti-spam mechanic. 20 visits = 100% depleted, recovers 2/hour.
- **Astrophysics limits** — Expedition slots tied to Astrophysics level.
- **Duration bonus** — Capped at 3× (8 hours).

### 📱 Mobile CSS

Full responsive design for all game pages. Purely additive — desktop is completely unaffected.

- Hamburger menu + slide-out drawer
- Building/research/shipyard card layouts
- All 3 fleet dispatch steps mobile-friendly
- Galaxy, empire, spy reports, overview — all responsive
- Touch-friendly inputs (44px min-height, 16px font to prevent iOS zoom)

Single file: `public/assets/css/mobile.css`

### ⚔️ Battle Simulator

Full in-game battle simulator accessible from the sidebar. Uses the OPBE battle engine under the hood.

- Configure attacker ships, research levels (weapons/shielding/armour)
- Configure defender ships, defenses, research levels, and resource stockpile
- Runs the actual battle engine with all 6 combat rounds
- Returns detailed results: fleet losses, debris field, loot, winner
- Can be pre-filled from espionage reports
- Available at `/game/battle-simulator`

### 🛒 Merchant Exchange

Resource-to-resource trading via the trader page.

- Two-step flow: select resource → enter amount → choose what to receive → confirm
- Exchange rates: Metal↔Crystal 2:1, Metal↔Deut 4:1, Crystal↔Deut 2:1
- 3,500 Dark Matter per trade
- Storage capacity validation

### 🛠️ Bug Fixes

- **Fleet cancel** — Built `FleetcancelController.php` (was missing from the fork entirely)
- **Colonization escorts** — Escort ships now land on the new planet instead of vanishing
- **Storage capacity** — Operator precedence bug gave 13-20% less storage than intended
- **Login sessions** — Double-hash bug, session regeneration, registration DM
- **Messenger** — SQL injection via string concatenation
- **Shipyard/Defense** — PHP 8.5 strict type casting
- **Galaxy** — AJAX navigation (no full page refresh)

## Stack

| Component | Version |
|---|---|
| PHP | 8.5 |
| Laravel | 12 |
| Database | MariaDB 12.3 / MySQL |
| Web Server | Nginx |
| SSL | Let's Encrypt |

## Setup

1. Clone the repo
2. Copy `.env.example` to `.env` and configure your database
3. Run `composer install`
4. Run `php artisan migrate`
5. Run `php artisan db:seed` (if available)
6. Configure Nginx/Laravel as per the [XGProyect docs](https://github.com/XGProyect/XGProyect)

### Bot Tick

Bots are processed via a Laravel artisan command:

```powershell
php artisan bot:tick              # Run one tick
php artisan bot:tick --dry-run    # Preview without saving
```

Set up a cron job or Windows Task Scheduler to run this every 15 minutes.

### Database

The bot AI system adds these tables:

| Table | Purpose |
|---|---|
| `bot_combat_log` | Tracks all bot battles (wins, losses, loot, debris) |
| `bot_grudges` | Grudge tracking between bots and players |
| `bot_intel` | Parsed spy report data |
| `expedition_deck` | Per-player shuffled expedition decks |
| `expedition_activity` | System depletion tracking |

## Upstream

This fork is based on [XGProyect/XGProyect](https://github.com/XGProyect/XGProyect). To pull upstream updates:

```powershell
git remote add upstream https://github.com/XGProyect/XGProyect.git
git fetch upstream
git merge upstream/master
```

## License

Same as [XGProyect](https://github.com/XGProyect/XGProyect).
