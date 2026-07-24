<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Xgp\App\Libraries\Template;

class ExpeditionguideController extends Controller
{
    public function __invoke(SettingsService $settingsService)
    {
        return view('expeditionguide.view', [
            'gameTitle' => $settingsService->getString('game_name') . ' - Expedition Guide',
        ]);
    }
}
