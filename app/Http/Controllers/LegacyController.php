<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\LegacyView;
use App\Models\Planets;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class LegacyController extends BaseController
{
    /**
     * Game pages promoted to Laravel controllers.
     * These bypass the legacy bootstrap entirely.
     *
     * @var array<string, class-string>
     */
    private const PROMOTED_PAGES = [
        'banned' => Game\BannedController::class,
        'battlesimulator' => Game\BattleSimulatorController::class,
        'changelog' => Game\ChangelogController::class,
        'changenick' => Game\ChangenickController::class,
        'combatreport' => Game\CombatreportController::class,
        'empire' => Game\EmpireController::class,
        'expeditionguide' => Game\ExpeditionguideController::class,
        'facilities' => Game\FacilitiesController::class,
        'logout' => Game\LogoutController::class,
        'notices' => Game\NoticesController::class,
        'playerprofile' => Game\PlayerprofileController::class,
        'preferences' => Game\PreferencesController::class,
        'research' => Game\ResearchController::class,
        'supplies' => Game\SuppliesController::class,
        'technologydetails' => Game\TechnologydetailsController::class,
        'technologytree' => Game\TechnologytreeController::class,
    ];

    public function __invoke(Request $request): BaseResponse
    {
        $file = strtr($request->getPathInfo(), ['/' => '', '.php' => '']);

        if ($file === 'game') {
            $page = $request->query('page');

            if (is_string($page) && isset(self::PROMOTED_PAGES[$page])) {
                $this->switchPlanet($request);

                $result = app()->call(self::PROMOTED_PAGES[$page]);

                return $result instanceof BaseResponse ? $result : new Response($result);
            }
        }

        try {
            ob_start();

            if (empty($file)) {
                $file = 'index';
            }

            if (in_array($file, ['game'])) {
                require app_path('Http') . '/' . $file . '.php';
            }

            $output = ob_get_clean();
        } catch (LegacyView $e) {
            $output = $e->getView();
        }

        return new Response($output);
    }

    /**
     * Apply the planet dropdown (&cp=<planet id>) for promoted pages.
     * The legacy bootstrap does this in Users::setPlanet(); promoted pages skip it.
     */
    private function switchPlanet(Request $request): void
    {
        $planetId = (int) $request->query('cp', '0');
        $user = Auth::user();

        if ($planetId === 0 || (int) $request->query('re', '0') !== 0 || !$user instanceof User) {
            return;
        }

        $owned = Planets::where('planet_id', $planetId)
            ->where('planet_user_id', $user->id)
            ->where('planet_destroyed', 0)
            ->exists();

        if ($owned && $user->current_planet !== $planetId) {
            $user->current_planet = $planetId;
            $user->save();
        }
    }
}
