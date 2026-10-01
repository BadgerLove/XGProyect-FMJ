<?php

declare(strict_types=1);

namespace App\Http\Controllers\Home;

use App\Services\SessionService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\ViewErrorBag;

class WelcomeController extends BaseController
{
    public function __construct(
        private SettingsService $settingsService,
        private SessionService $sessionService,
    ) {
    }

    public function __invoke(Request $request): Response | RedirectResponse
    {
        // Already logged in: go straight into the game instead of asking for the password again
        // (2026-10-01). Same checks the game pages make (legacy Users lib), so a session the game
        // would reject can never bounce back and forth between here and the game.
        if ($this->hasValidGameSession($request)) {
            return redirect('game.php?page=overview');
        }

        // no-store: a browser must never show this page from its back/forward cache, because the
        // login form inside carries the security token of the session it was rendered for
        return response()->view(
            'home.welcome',
            array_merge(
                [
                    'servername' => __('home/welcome.hm_title', ['game' => $this->settingsService->getString('game_name')]),
                    'gameLogo' => $this->settingsService->getString('game_logo'),
                    'basePath' => url('/'),
                    'userName' => '',
                    'userEmail' => '',
                    'forumUrl' => $this->settingsService->getString('forum_url'),
                ],
                $this->getErrors($request)
            )
        )->header('Cache-Control', 'no-store, private');
    }

    private function hasValidGameSession(Request $request): bool
    {
        $user = Auth::user();
        $sessionUserId = $request->session()->get('user_id');

        if ($user === null || !$sessionUserId || Auth::id() !== $sessionUserId) {
            return false;
        }

        if (!$this->sessionService->checkPasswordToken((string) $user->password, $request->session()->get('user_password'))) {
            return false;
        }

        // the game also needs these rows (Users::setUserData joins them); without them it sends the
        // player back here, so only forward accounts the game will accept
        return DB::table('users AS u')
            ->join('preferences AS pr', 'pr.preference_user_id', '=', 'u.id')
            ->join('users_statistics AS us', 'us.user_statistic_user_id', '=', 'u.id')
            ->join('premium AS pre', 'pre.premium_user_id', '=', 'u.id')
            ->join('research AS r', 'r.research_user_id', '=', 'u.id')
            ->where('u.id', $user->id)
            ->exists();
    }

    /**
     * @return array{errors: list<array{divId: string, message: string}>, loginError: bool}
     */
    private function getErrors(Request $request): array
    {
        $errorsBlocks = [];
        $loginError = false;
        $errorsBags = $request->session()->get('errors');

        if ($errorsBags instanceof ViewErrorBag) {
            if ($errorsBags->hasBag('login')) {
                $loginError = true;

                foreach ($errorsBags->getBag('login')->getMessages() as $field => $errors) {
                    $errorsBlocks[] = [
                        'divId' => '#' . $field . 'Login',
                        'message' => $errors[0], // first error only
                    ];
                }
            }

            if ($errorsBags->hasBag('register')) {
                foreach ($errorsBags->getBag('register')->getMessages() as $field => $errors) {
                    $errorsBlocks[] = [
                        'divId' => '#' . $field,
                        'message' => $errors[0], // first error only
                    ];
                }
            }
        }

        return [
            'errors' => $errorsBlocks,
            'loginError' => $loginError,
        ];
    }
}
