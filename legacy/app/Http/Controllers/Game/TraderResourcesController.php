<?php

declare(strict_types=1);

namespace Xgp\App\Http\Controllers\Game;

use App\Services\FormatService;
use App\Services\Game\Formulas\ProductionService;
use Illuminate\Routing\Controller as BaseController;
use Xgp\App\Core\Template;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\Game\ResourceMarket;
use App\Enums\Module;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Libraries\Users;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class TraderResourcesController extends BaseController
{
    use PreparesLegacySql;

    public const RESOURCES = ['metal', 'crystal', 'deuterium'];
    public const PERCENTAGES = [10, 50, 100];

    private const MERCHANT_EXCHANGE_DM_COST = 3500;

    private const RESOURCE_NAMES = [
        'metal' => 'Metal',
        'crystal' => 'Crystal',
        'deuterium' => 'Deuterium',
    ];

    /**
     * Exchange rates: EXCHANGE_RATES[sell][buy] = multiplier
     * Metal (cheapest) -> Crystal 2:1, Deuterium 4:1
     * Crystal (mid)    -> Metal 1:2, Deuterium 2:1
     * Deuterium (expensive) -> Metal 1:4, Crystal 1:2
     */
    private const EXCHANGE_RATES = [
        'metal'      => ['crystal' => 0.5, 'deuterium' => 0.25],
        'crystal'    => ['metal' => 2.0, 'deuterium' => 0.5],
        'deuterium'  => ['metal' => 4.0, 'crystal' => 2.0],
    ];

    private array $user = [];
    private array $planet = [];
    private ?ResourceMarket $trader;
    private string $error = '';
    private string $merchantView = '';

    public function __construct(private FormatService $formatService, private ProductionService $productionService)
    {
    }

    public function __invoke(): void
    {
        Functions::moduleMessage(Functions::isModuleAccesible(Module::Trader));

        $this->user = Users::getInstance()->getUserData();
        $this->planet = Users::getInstance()->getPlanetData();
        $this->trader = new ResourceMarket(
            $this->user,
            $this->planet,
            $this->productionService
        );

        $this->runAction();

        Template::legacyView(
            'trader.overview',
            array_merge(
                $this->setMessageDisplay(),
                $this->getPage()
            )
        );
    }

    private function runAction(): void
    {
        $post = filter_input_array(INPUT_POST);

        if (!$post) {
            return;
        }

        // Step 2: Confirm trade
        if (isset($post['action']) && $post['action'] === 'confirm_trade') {
            $this->executeTrade(
                (string) ($post['sell'] ?? ''),
                (string) ($post['buy'] ?? ''),
                (int) ($post['amount'] ?? 0)
            );
            return;
        }

        // Step 1: Call merchant
        if (isset($post['action']) && $post['action'] === 'call_merchant' && isset($post['sell'])) {
            $this->showMerchant((string) $post['sell']);
            return;
        }

        // Refill resources
        if (
            preg_match_all(
                '/(' . join('|', self::RESOURCES) . ')-(' . join('|', self::PERCENTAGES) . ')/',
                key($post)
            )
        ) {
            $parts = explode('-', key($post));
            $this->refillResource($parts[0], (int) $parts[1]);
        }
    }

    private function showMerchant(string $sell): void
    {
        if (!in_array($sell, self::RESOURCES, true)) {
            $this->error = __('game/trader.tr_invalid_exchange');
            return;
        }

        $dmAvailable = (int) ($this->user['premium_dark_matter'] ?? 0);
        if ($dmAvailable < self::MERCHANT_EXCHANGE_DM_COST) {
            $this->error = __('game/trader.tr_no_enough_dark_matter');
            return;
        }

        $sellAmount = (int) $this->planet['planet_' . $sell];
        if ($sellAmount <= 0) {
            $this->error = __('game/trader.tr_no_resource_to_sell');
            return;
        }

        // Build buy options (exclude the resource being sold)
        $buyOptions = [];
        $rates = [];
        $buyNames = [];
        foreach (self::RESOURCES as $res) {
            if ($res !== $sell) {
                $rate = self::EXCHANGE_RATES[$sell][$res];
                $buyOptions[$res] = [
                    'name' => self::RESOURCE_NAMES[$res],
                    'rate' => $rate,
                    'rateDisplay' => '1:' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'),
                ];
                $rates[$res] = $rate;
                $buyNames[$res] = self::RESOURCE_NAMES[$res];
            }
        }

        $this->merchantView = Template::render('trader.merchant', [
            'sell' => $sell,
            'sellName' => self::RESOURCE_NAMES[$sell],
            'buyOptions' => $buyOptions,
            'rates' => $rates,
            'buyNames' => $buyNames,
            'maxAmount' => $sellAmount,
            'dmCost' => self::MERCHANT_EXCHANGE_DM_COST,
            'dmCostFormatted' => $this->formatService->prettyNumber(self::MERCHANT_EXCHANGE_DM_COST),
            'available' => $this->formatService->shortlyNumber($sellAmount) . ' ' . self::RESOURCE_NAMES[$sell],
        ]);
    }

    private function executeTrade(string $sell, string $buy, int $amount): void
    {
        if (!in_array($sell, self::RESOURCES, true) || !in_array($buy, self::RESOURCES, true)) {
            $this->error = __('game/trader.tr_invalid_exchange');
            return;
        }

        if ($sell === $buy) {
            $this->error = __('game/trader.tr_same_resource');
            return;
        }

        $dmAvailable = (int) ($this->user['premium_dark_matter'] ?? 0);
        if ($dmAvailable < self::MERCHANT_EXCHANGE_DM_COST) {
            $this->error = __('game/trader.tr_no_enough_dark_matter');
            return;
        }

        $sellAmount = (int) $this->planet['planet_' . $sell];
        if ($amount <= 0 || $amount > $sellAmount) {
            $this->error = __('game/trader.tr_no_resource_to_sell');
            return;
        }

        $rate = self::EXCHANGE_RATES[$sell][$buy];
        $buyAmount = (int) floor($amount * $rate);

        if ($buyAmount <= 0) {
            $this->error = __('game/trader.tr_no_resource_to_sell');
            return;
        }

        $remainingSell = $sellAmount - $amount;

        // Check storage capacity for the resource being bought
        $storageColumn = 'building_' . $buy . ($buy === 'deuterium' ? '_tank' : '_store');
        $currentBuy = (int) $this->planet['planet_' . $buy];
        $buyStorage = $this->productionService->maxStorable(
            (int) ($this->planet[$storageColumn] ?? 0)
        );

        if (($currentBuy + $buyAmount) > $buyStorage) {
            $this->error = __('game/trader.tr_no_enough_storage');
            return;
        }

        DB::statement(
            $this->prepareSql(
                'UPDATE `' . PREMIUM . '` pr, `' . PLANETS . '` p SET
                    pr.`premium_dark_matter` = pr.`premium_dark_matter` - ' . self::MERCHANT_EXCHANGE_DM_COST . ',
                    p.`planet_' . $sell . '` = ' . $remainingSell . ',
                    p.`planet_' . $buy . '` = p.`planet_' . $buy . '` + ' . $buyAmount . '
                WHERE pr.`premium_user_id` = ' . (int) $this->user['id'] . '
                    AND p.`planet_id` = ' . (int) $this->planet['planet_id']
            )
        );

        Functions::redirect('game.php?page=traderResources');
    }

    private function refillResource(string $resource, int $percentage): void
    {
        if ($this->trader->{'is' . $resource . 'StorageFillable'}($percentage)) {
            if ($this->trader->isRefillPayable($resource, $percentage)) {
                $dark_matter = (int) $this->trader->{'getPriceToFill' . $percentage . 'Percent'}($resource);
                $amount = $this->trader->getProjectedResouces($resource, $percentage);
                DB::statement(
                    $this->prepareSql(
                        'UPDATE `' . PREMIUM . '` pr, `' . PLANETS . "` p SET
                        pr.`premium_dark_matter` = pr.`premium_dark_matter` - '" . $dark_matter . "',
                        p.`planet_" . $resource . "` = '" . $amount . "'
                        WHERE pr.`premium_user_id` = '" . $this->user['id'] . "'
                            AND p.`planet_id` = '" . $this->planet['planet_id'] . "';"
                    )
                );

                Functions::redirect('game.php?page=traderResources');
            } else {
                $this->error = __('game/trader.tr_no_enough_dark_matter');
            }
        } else {
            $this->error = __('game/trader.tr_no_enough_storage');
        }
    }

    private function setMessageDisplay(): array
    {
        $message = [
            'color' => '',
            'message' => '',
        ];

        if ($this->error != '') {
            $message = [
                'color' => '#ff0000',
                'message' => $this->error,
            ];
        }

        return $message;
    }

    private function getPage(): array
    {
        $currentMode = Template::render(
            'trader.resources',
            ['resourcesList' => $this->buildResourcesSection()]
        );

        if ($this->merchantView !== '') {
            $currentMode = $this->merchantView;
        }

        return ['currentMode' => $currentMode];
    }

    private function buildResourcesSection(): array
    {
        $resourcesList = [];

        foreach (self::RESOURCES as $resource) {
            $resourcesList[] = array_merge(
                [
                    'resource' => $resource,
                    'resourceName' => __('game/global.' . $resource),
                    'currentResource' => $this->formatService->shortlyNumber($this->planet['planet_' . $resource]),
                    'maxResource' => $this->formatService->shortlyNumber($this->planet['planet_' . $resource . '_max']),
                    'refillOptions' => $this->setRefillOptions($resource),
                ]
            );
        }

        return $resourcesList;
    }

    private function setRefillOptions(string $resource): array
    {
        $refillOptions = [];

        foreach (self::PERCENTAGES as $percentage) {
            $dmPrice = $this->trader->{'getPriceToFill' . $percentage . 'Percent'}($resource);

            if (
                !$this->trader->{'is' . ucfirst($resource) . 'StorageFillable'}($percentage) ||
                $dmPrice == 0
            ) {
                $price = $this->formatService->colorRed('-');
                $button = '';
            } else {
                $price = $this->formatService->customColor(
                    $this->formatService->prettyNumber((int) $dmPrice),
                    '#2cbef2'
                ) . ' ' . __('game/global.dark_matter_short');
                $button = '<input type="submit" name="' . $resource . '-' . $percentage . '" value="' . __('game/trader.tr_refill_button') . '">';
            }

            $refillOptions[] = [
                'label' => (self::PERCENTAGES == 100) ? __('game/trader.tr_refill_to') : __('game/trader.tr_refill_by'),
                'percentage' => $percentage,
                'tr_requires' => __('game/trader.tr_requires'),
                'price' => $price,
                'button' => $button,
            ];
        }

        return $refillOptions;
    }
}
