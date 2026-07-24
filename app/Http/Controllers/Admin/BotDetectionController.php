<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\Admin\BotDetectionService;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\View\View;

class BotDetectionController extends BaseController
{
    public function __construct(private readonly BotDetectionService $detectionService)
    {
    }

    public function index(): View
    {
        $suspiciousPlayers = $this->detectionService->analyzePlayers();

        return view('admin.bot-detection', [
            'players' => $suspiciousPlayers,
            'totalSuspicious' => count(array_filter($suspiciousPlayers, fn ($p) => $p['suspicion_score'] >= 50)),
        ]);
    }
}
