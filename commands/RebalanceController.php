<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\helpers\ArrayHelper;
use app\helpers\NumbersHelper;
use app\models\TelegramBot;
use app\models\TIAccount;
use app\models\TIServices;
use Exception;
use Metaseller\TinkoffInvestApi2\dto\Price;
use Metaseller\TinkoffInvestApi2\dto\Quantity;
use Metaseller\TinkoffInvestApi2\exceptions\InstrumentNotFoundException;
use Metaseller\TinkoffInvestApi2\exceptions\ValidateException;
use Metaseller\TinkoffInvestApi2\helpers\InstrumentsHelper;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use Throwable;
use Tinkoff\Invest\V1\Bond;
use Tinkoff\Invest\V1\CancelOrderRequest;
use Tinkoff\Invest\V1\GetOrdersRequest;
use Tinkoff\Invest\V1\GetOrdersResponse;
use Tinkoff\Invest\V1\OrderState;
use Tinkoff\Invest\V1\PortfolioPosition;
use Tinkoff\Invest\V1\PortfolioResponse;
use Tinkoff\Invest\V1\Quotation;
use Tinkoff\Invest\V1\Share;
use Yii;
use yii\helpers\Console;

/**
 * Консольный контроллер, обслуживающий стратегию автоматической ребалансировки и управления портфелем
 *
 * @package app\commands
 */
class RebalanceController extends BaseController
{
    use ProgressTrait;

    /**
     * @var string Режим работы "Автопокупка акций"
     */
    public const MODE_AUTO_BUY_SHARES = 'shares';

    /**
     * @var string Режим работы "Автопокупка облигаций"
     */
    public const MODE_AUTO_BUY_BONDS = 'bonds';

    /**
     * @var array Настройки временного периода, когда стратегия работает в экономичном режиме
     */
    public const ECONOMY_STRATEGY_WORK_HOURS = [
        'start' => [
            'h' => 15,
            'm' => 30,
        ],
        'end' => [
            'h' => 22,
            'm' => 25,
        ],
    ];

    /**
     * @var array Настройки временного периода, когда стратегия работает в форсированном режиме
     */
    public const FORCE_STRATEGY_WORK_HOURS = [
        'start' => [
            'h' => 22,
            'm' => 26,
        ],
        'end' => [
            'h' => 22,
            'm' => 46,
        ],
    ];

    /**
     * Выбор режима работы стратегии и запуск логики, соответствующей режиму
     *
     * @param string $strategy_alias Алиас стратегии
     *
     * @throws Exception
     *
     * @see RebalanceController::actionAutoBuyShares()
     * @see RebalanceController::actionAutoBuyBonds()
     */
    public function actionProcessing(string $strategy_alias): void
    {
        static::stdoutStart(__FUNCTION__, static::STRATEGY_MANAGE_LOG_TARGET);

        $current_mode = static::MODE_AUTO_BUY_BONDS;

        try {
            list ($economy_buy, $force_buy) = static::strategyWorkType();

            if (!$economy_buy && !$force_buy) {
                echo 'Sleep period' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $all_manage_strategies = Yii::$app->params['strategies']['manage'] ?? [];
            $selected_strategy = $all_manage_strategies[$strategy_alias] ?? [];

            if (!$selected_strategy) {
                throw new Exception('Strategy not found');
            }

            $account = $selected_strategy['account'] ?? null;

            if (!$account) {
                echo 'Account is empty' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $account = TIAccount::create($account);

            $current_mode = Yii::$app->cache->get(static::cacheKeyRebalanceMode($strategy_alias));

            echo 'Текущее состояние стратегии: ' . $current_mode . PHP_EOL;

            if (static::getForceRecalculateSharesTask($strategy_alias)) {
                try {
                    echo 'Выставлен флаг пересчета задания на покупку акций в рамках стратегии' . PHP_EOL;

                    static::setForceRecalculateSharesTaskFlag($strategy_alias, false);

                    $this->calculateSharesState($strategy_alias);
                } catch (Throwable $e) {
                    echo 'Возникла ошибка в ходе пересчета: ' . $e->getMessage() . PHP_EOL;
                }
            }

            $shares_task = Yii::$app->cache->get(static::cacheKeyStrategySharesTask($strategy_alias));

            if (!empty($shares_task)) {
                if ($current_mode !== self::MODE_AUTO_BUY_SHARES) {
                    $shares_percentage = Yii::$app->cache->get(static::cacheKeyStrategySharesPercentage($strategy_alias));

                    TelegramBot::notifyTelegram($account->accountId, static::escapeMarkdown('Появилось новое задание на покупку акций и режим работы переключен на АКЦИИ'));

                    $task_string = '';

                    if ($shares_percentage) {
                        foreach ($shares_percentage as $ticker => $target) {
                            if (!array_key_exists($ticker, $shares_task)) {
                                continue;
                            }

                            $task_string .= mb_sprintf("%-8s | %6s | %5s | %3s",
                                    $ticker,
                                    $target['target_quantity'],
                                    $target['target_quantity_to_buy'],
                                    $target['target_lots_to_buy'],

                                ) . PHP_EOL;
                        }
                    } else {
                        foreach ($shares_task as $ticker => $target) {
                            $task_string .= mb_sprintf("%-8s | %6s",
                                    $ticker,
                                    $target
                                ) . PHP_EOL;
                        }
                    }

                    if ($task_string) {
                        TelegramBot::notifyTelegram($account->accountId, '``` ' . static::escapeMarkdown('Состав задания:') . PHP_EOL . static::escapeMarkdown($task_string) . ' ```');
                    }
                }

                $current_mode = static::MODE_AUTO_BUY_SHARES;
            } else {
                if ($current_mode !== self::MODE_AUTO_BUY_BONDS) {
                    TelegramBot::notifyTelegram($account->accountId, static::escapeMarkdown('Режим работы переключен на ОБЛИГАЦИИ'));
                }

                $current_mode = static::MODE_AUTO_BUY_BONDS;
            }

            echo 'Cледущее состояние стратегии: ' . $current_mode . PHP_EOL;

            Yii::$app->cache->set(static::cacheKeyRebalanceMode($strategy_alias), $current_mode);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::STRATEGY_MANAGE_LOG_TARGET);
        }

        if ($current_mode === static::MODE_AUTO_BUY_SHARES) {
            $this->actionAutoBuyShares($strategy_alias);
        } else {
            $this->actionAutoBuyBonds($strategy_alias);
        }

        static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);
    }

    /**
     * Итерация попытки выставления заявки на покупку облигаций
     *
     * Метод вычисляет, хватает ли средств и если есть возможность - выставляет/обновляет заявки на покупку
     *
     * @param string $strategy_alias Алиас стратегии
     *
     * @throws Exception
     */
    public function actionAutoBuyBonds(string $strategy_alias): void
    {
        static::stdoutStart(__FUNCTION__, static::STRATEGY_MANAGE_LOG_TARGET);

        try {
            list ($economy_buy, $force_buy) = static::strategyWorkType();

            if (!$economy_buy && !$force_buy) {
                echo 'Sleep period' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $all_manage_strategies = Yii::$app->params['strategies']['manage'] ?? [];
            $selected_strategy = $all_manage_strategies[$strategy_alias] ?? [];
            $buy_settings = $selected_strategy['bonds'] ?? [];

            if (empty($buy_settings)) {
                echo 'No bonds tasks' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $account = $selected_strategy['account'] ?? null;

            if (!$account) {
                echo 'Account is empty' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $account = TIAccount::create($account);
            $tinkoff_api = TIServices::apiClient($account->profile);
            $instruments = TIServices::instruments($account->profile);

            $money_etf_instrument = null;

            if ($money_etf_instrument_ticker = $selected_strategy['parking_money_etf'] ?? null) {
                $money_etf_instrument = $instruments->etfByTicker($money_etf_instrument_ticker);
            }

            $base_tasks_to_buy_bonds = static::prepareTaskInstruments($account, 'bond', $buy_settings);

            if (!$base_tasks_to_buy_bonds) {
                echo 'Tasks list is empty' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $tasks_related_orders = static::findTasksRelatedOrders($account, $base_tasks_to_buy_bonds);

            list($portfolio_money, $etf_money, $single_etf_price, $money_etf_quantity) = static::portfolioMoneyTotal($account->accountId, 'rub', $money_etf_instrument);
            $available_money = $portfolio_money + $etf_money;

            echo 'Total money:' . $available_money . PHP_EOL;

            $tasks_to_buy_bonds = static::prepareBondTaskPrices($account, $base_tasks_to_buy_bonds, $available_money, $portfolio_money);

            if (!empty($tasks_related_orders)) {
                foreach ($tasks_to_buy_bonds as $ticker => $task) {
                    foreach ($tasks_related_orders as $tro_i => $tasks_related_order) {
                        if (($tasks_related_order['ticker'] ?? 'Unknown') === $ticker) {
                            echo 'Task for ticker ' . $ticker . ' already sent. Skip' . PHP_EOL;

                            unset($tasks_to_buy_bonds[$ticker]);

                            break;
                        }
                    }
                }
            }

            if (empty($tasks_to_buy_bonds) && !empty($tasks_related_orders)) {
                echo 'We should cancel some orders' . PHP_EOL;

                shuffle($tasks_related_orders);

                $order_to_cancel = reset($tasks_related_orders);

                echo 'Cancel order: ' . ($order_to_cancel['ticker'] ?? 'Unknown') . '(' . ($order_to_cancel['order_id'] ?? 'Unknown') . ')' . PHP_EOL;

                $cancel_order_request = new CancelOrderRequest();
                $cancel_order_request->setAccountId($account->accountId);
                $cancel_order_request->setOrderId($order_to_cancel['order_id']);

                /** @var GetOrdersResponse $response */
                list($response, $status) = $tinkoff_api->ordersServiceClient->CancelOrder($cancel_order_request)->wait();
                static::processRequestStatus($status, true);

                sleep(15);

                echo 'Order cancelled' . PHP_EOL;

                foreach ($tasks_related_orders as $tro_i => $tasks_related_order) {
                    if (($tasks_related_order['ticker'] ?? 'Unknown') === ($order_to_cancel['ticker'] ?? 'Unknown')) {
                        unset($tasks_related_orders[$tro_i]);
                    }
                }

                list($portfolio_money, $etf_money, $single_etf_price, $money_etf_quantity) = static::portfolioMoneyTotal($account->accountId, 'rub', $money_etf_instrument);
                $available_money = $portfolio_money + $etf_money;

                echo 'Total money:' . $available_money . PHP_EOL;

                $tasks_to_buy_bonds = static::prepareBondTaskPrices($account, $base_tasks_to_buy_bonds, $available_money, $portfolio_money);

                if (!empty($tasks_related_orders)) {
                    foreach ($tasks_to_buy_bonds as $ticker => $task) {
                        foreach ($tasks_related_orders as $tro_i => $tasks_related_order) {
                            if (($tasks_related_order['ticker'] ?? 'Unknown') === $ticker) {
                                echo 'Task for ticker ' . $ticker . ' already sent. Skip' . PHP_EOL;

                                unset($tasks_to_buy_bonds[$ticker]);

                                break;
                            }
                        }
                    }
                }
            }

            if (empty($tasks_to_buy_bonds)) {
                echo 'Nothing to buy after prepare' . PHP_EOL;

                $stdout_data = ob_get_contents();
                ob_end_clean();

                if ($stdout_data) {
                    Log::info($stdout_data, static::STRATEGY_MANAGE_LOG_TARGET);

                    echo $stdout_data;
                }

                return;
            }

            $commission = static::COMMISSION;

            echo 'After Prepare Tasks List:' . PHP_EOL;

            $prior_tasks_to_buy_bonds = [];

            foreach ($tasks_to_buy_bonds as $ticker => $task) {
                echo $ticker . ' -> ';
                echo $task['limit'] . ' ' . ($task['prior'] ? 'Prior!' : '') . PHP_EOL;

                if ($task['prior'] && $task['limit'] > 0) {
                    $prior_tasks_to_buy_bonds[$ticker] = $task;
                }
            }

            if (!empty($prior_tasks_to_buy_bonds)) {
                $tasks_to_buy_bonds = $prior_tasks_to_buy_bonds;
            }

            shuffle($tasks_to_buy_bonds);

            /** @var Bond $target_instrument */
            $task = reset($tasks_to_buy_bonds);

            $target_instrument = $task['instrument'];
            $target_limit = $task['limit'];

            echo 'Target instrument located: ' . $target_instrument->getTicker() . '(' . $target_limit . ')' . PHP_EOL;

            $instrument_top_prices = TIServices::marketdata($account->profile)->getOrderbookTopPrices($target_instrument, false);

            if (!$instrument_top_prices) {
                echo 'Error on getting instrument price' . PHP_EOL;

                throw new Exception('Error on getting instrument price');
            }

            $top_ask_price = $instrument_top_prices['ask'];
            $top_bid_price = $instrument_top_prices['bid'];

            if ($economy_buy) {
                $current_buy_price = $top_bid_price;
                $current_buy_price_decimal = QuotationHelper::toCurrency($current_buy_price, $target_instrument) + QuotationHelper::toDecimal($target_instrument->getMinPriceIncrement());
            } elseif ($force_buy) {
                $current_buy_price = $top_ask_price;
                $current_buy_price_decimal = QuotationHelper::toCurrency($current_buy_price, $target_instrument);
            } else {
                echo 'Error on buy scheme selection' . PHP_EOL;

                $stdout_data = ob_get_contents();
                ob_end_clean();

                if ($stdout_data) {
                    Log::info($stdout_data, static::STRATEGY_MANAGE_LOG_TARGET);

                    echo $stdout_data;
                }

                return;
            }

            echo 'Target instrument price: ' . $current_buy_price_decimal . PHP_EOL;

            $nkd = $target_instrument->getAciValue();

            $nkd_decimal = 0;

            if ($nkd) {
                $nkd_decimal = QuotationHelper::toDecimal($nkd);
            }

            echo 'NKD correction: ' . $nkd_decimal . PHP_EOL;

            $we_can_buy = (int)floor($available_money / ($current_buy_price_decimal * (1 + $commission) + $nkd_decimal));

            echo 'We can buy ' . $we_can_buy . ' lots' . PHP_EOL;

            $we_can_buy = min($we_can_buy, $target_limit);

            echo 'But we should buy ' . $we_can_buy . ' lots' . PHP_EOL;

            if ($we_can_buy <= 0) {
                echo 'Zero can buy' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $need_money_from_etf = ($current_buy_price_decimal * (1 + $commission) + $nkd_decimal) * $we_can_buy - $portfolio_money;
            $need_wait = false;

            if ($need_money_from_etf > 0 && $single_etf_price) {
                $need_sell_etf_lots = (int) ceil(1.02 * (1 + $commission) * $need_money_from_etf / $single_etf_price);

                echo 'We need to sell ' . $need_sell_etf_lots . ' LQDT lots' . PHP_EOL;

                if ($need_sell_etf_lots > $money_etf_quantity) {
                    echo 'There are not that many ETF items for sale' . PHP_EOL;

                    static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                    return;
                }

                if ($need_sell_etf_lots > 0) {
                    $this->sellTaskLogic($account->accountId, 'etf', $money_etf_instrument->getTicker(), $need_sell_etf_lots);

                    $need_wait = true;
                }
            } else {
                echo 'Money is enough' . PHP_EOL;
            }

            if ($need_wait) {
                sleep(15);
            }

            $this->buyTaskLogic($account->accountId, 'bond', $target_instrument->getTicker(), $we_can_buy, $force_buy ? null : $current_buy_price_decimal);

            TelegramBot::notifyTelegram($account->accountId, 'Инициирована покупка облигации [[' . $target_instrument->getTicker() . ']] ' . static::escapeMarkdown($target_instrument->getName()) . ' по цене ' . ($force_buy ? 'лучшей' : $current_buy_price_decimal));
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::STRATEGY_MANAGE_LOG_TARGET);
        }

        static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);
    }

    /**
     * Итерация попытки выставления заявки на покупку акций в соответствии с предварительно вычисленным заданием
     *
     * Метод вычисляет, хватает ли средств и если есть возможность - выставляет/обновляет заявки на покупку
     *
     * @param string $strategy_alias Алиас стратегии
     *
     * @throws Exception
     */
    public function actionAutoBuyShares(string $strategy_alias): void
    {
        static::stdoutStart(__FUNCTION__, static::STRATEGY_MANAGE_LOG_TARGET);

        try {
            list ($economy_buy, $force_buy) = static::strategyWorkType();

            if (!$economy_buy && !$force_buy) {
                echo 'Sleep period' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $all_manage_strategies = Yii::$app->params['strategies']['manage'] ?? [];
            $selected_strategy = $all_manage_strategies[$strategy_alias] ?? [];

            $buy_settings = Yii::$app->cache->get(static::cacheKeyStrategySharesTask($strategy_alias));

            if (empty($buy_settings)) {
                echo 'No shares tasks' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $account = $selected_strategy['account'] ?? null;

            if (!$account) {
                echo 'Account is empty' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $account = TIAccount::create($account);
            $tinkoff_api = TIServices::apiClient($account->profile);
            $instruments = TIServices::instruments($account->profile);

            $money_etf_instrument = null;

            if ($money_etf_instrument_ticker = $selected_strategy['parking_money_etf'] ?? null) {
                $money_etf_instrument = $instruments->etfByTicker($money_etf_instrument_ticker);
            }

            $base_tasks_to_buy_shares = static::prepareTaskInstruments($account, 'share', $buy_settings);

            if (!$base_tasks_to_buy_shares) {
                echo 'Tasks list is empty' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $tasks_related_orders = static::findTasksRelatedOrders($account, $base_tasks_to_buy_shares);

            list($portfolio_money, $etf_money, $single_etf_price, $money_etf_quantity) = static::portfolioMoneyTotal($account->accountId, 'rub', $money_etf_instrument);
            $available_money = $portfolio_money + $etf_money;

            echo 'Total money:' . $available_money . PHP_EOL;

            $tasks_to_buy_shares = static::prepareSharesTaskPrices($account, $base_tasks_to_buy_shares, $available_money, $portfolio_money);

            if (!empty($tasks_related_orders)) {
                foreach ($tasks_to_buy_shares as $ticker => $task) {
                    foreach ($tasks_related_orders as $tro_i => $tasks_related_order) {
                        if (($tasks_related_order['ticker'] ?? 'Unknown') === $ticker) {
                            echo 'Task for ticker ' . $ticker . ' already sent. Skip' . PHP_EOL;

                            unset($tasks_to_buy_shares[$ticker]);

                            break;
                        }
                    }
                }
            }

            if (empty($tasks_to_buy_shares) && !empty($tasks_related_orders)) {
                echo 'We should cancel some orders' . PHP_EOL;

                shuffle($tasks_related_orders);

                $order_to_cancel = reset($tasks_related_orders);

                echo 'Cancel order: ' . ($order_to_cancel['ticker'] ?? 'Unknown') . '(' . ($order_to_cancel['order_id'] ?? 'Unknown') . ')' . PHP_EOL;

                $cancel_order_request = new CancelOrderRequest();
                $cancel_order_request->setAccountId($account->accountId);
                $cancel_order_request->setOrderId($order_to_cancel['order_id']);

                /** @var GetOrdersResponse $response */
                list($response, $status) = $tinkoff_api->ordersServiceClient->CancelOrder($cancel_order_request)->wait();
                static::processRequestStatus($status, true);

                sleep(15);

                echo 'Order cancelled' . PHP_EOL;

                foreach ($tasks_related_orders as $tro_i => $tasks_related_order) {
                    if (($tasks_related_order['ticker'] ?? 'Unknown') === ($order_to_cancel['ticker'] ?? 'Unknown')) {
                        unset($tasks_related_orders[$tro_i]);
                    }
                }

                list($portfolio_money, $etf_money, $single_etf_price, $money_etf_quantity) = static::portfolioMoneyTotal($account->accountId, 'rub', $money_etf_instrument);
                $available_money = $portfolio_money + $etf_money;

                echo 'Total money:' . $available_money . PHP_EOL;

                $tasks_to_buy_shares = static::prepareSharesTaskPrices($account, $base_tasks_to_buy_shares, $available_money, $portfolio_money);

                if (!empty($tasks_related_orders)) {
                    foreach ($tasks_to_buy_shares as $ticker => $task) {
                        foreach ($tasks_related_orders as $tro_i => $tasks_related_order) {
                            if (($tasks_related_order['ticker'] ?? 'Unknown') === $ticker) {
                                echo 'Task for ticker ' . $ticker . ' already sent. Skip' . PHP_EOL;

                                unset($tasks_to_buy_shares[$ticker]);

                                break;
                            }
                        }
                    }
                }
            }

            if (empty($tasks_to_buy_shares)) {
                echo 'Nothing to buy after prepare' . PHP_EOL;

                $stdout_data = ob_get_contents();
                ob_end_clean();

                if ($stdout_data) {
                    Log::info($stdout_data, static::STRATEGY_MANAGE_LOG_TARGET);

                    echo $stdout_data;
                }

                return;
            }

            $commission = static::COMMISSION;

            echo 'After Prepare Tasks List:' . PHP_EOL;

            $prior_tasks_to_buy_shares = [];

            foreach ($tasks_to_buy_shares as $ticker => $task) {
                echo $ticker . ' -> ';
                echo $task['limit'] . ' ' . ($task['prior'] ? 'Prior!' : '') . PHP_EOL;

                if ($task['prior'] && $task['limit'] > 0) {
                    $prior_tasks_to_buy_shares[$ticker] = $task;
                }
            }

            if (!empty($prior_tasks_to_buy_shares)) {
                $tasks_to_buy_shares = $prior_tasks_to_buy_shares;
            }

            shuffle($tasks_to_buy_shares);

            /** @var Share $target_instrument */
            $task = reset($tasks_to_buy_shares);

            $target_instrument = $task['instrument'];
            $target_limit = $task['limit'];

            echo 'Target instrument located: ' . $target_instrument->getTicker() . '(' . $target_limit . ')' . PHP_EOL;

            $instrument_top_prices = TIServices::marketdata($account->profile)->getOrderbookTopPrices($target_instrument, false);

            if (!$instrument_top_prices) {
                echo 'Error on getting instrument price' . PHP_EOL;

                throw new Exception('Error on getting instrument price');
            }

            $top_ask_price = $instrument_top_prices['ask'];
            $top_bid_price = $instrument_top_prices['bid'];

            if ($economy_buy) {
                $current_buy_price = $top_bid_price;
                $current_buy_price_decimal = QuotationHelper::toCurrency($current_buy_price, $target_instrument) + QuotationHelper::toDecimal($target_instrument->getMinPriceIncrement());
            } elseif ($force_buy) {
                $current_buy_price = $top_ask_price;
                $current_buy_price_decimal = QuotationHelper::toCurrency($current_buy_price, $target_instrument);
            } else {
                echo 'Error on buy scheme selection' . PHP_EOL;

                $stdout_data = ob_get_contents();
                ob_end_clean();

                if ($stdout_data) {
                    Log::info($stdout_data, static::STRATEGY_MANAGE_LOG_TARGET);

                    echo $stdout_data;
                }

                return;
            }

            $current_buy_lot_price_decimal = $current_buy_price_decimal * $target_instrument->getLot();

            echo 'Target instrument price: ' . $current_buy_price_decimal . PHP_EOL;
            echo 'Target instrument lot price: ' . $current_buy_lot_price_decimal . PHP_EOL;

            $we_can_buy = (int) floor($available_money / ($current_buy_lot_price_decimal * (1 + $commission)));

            echo 'We can buy ' . $we_can_buy . ' lots' . PHP_EOL;

            $we_can_buy = min($we_can_buy, (int) ($target_limit / $target_instrument->getLot()));

            echo 'But we should buy ' . $we_can_buy . ' lots' . PHP_EOL;

            if ($we_can_buy <= 0) {
                echo 'Zero can buy' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                return;
            }

            $need_money_from_etf = ($current_buy_lot_price_decimal * (1 + $commission)) * $we_can_buy - $portfolio_money;
            $need_wait = false;

            if ($need_money_from_etf > 0 && $single_etf_price) {
                $need_sell_etf_lots = (int) ceil(1.02 * (1 + $commission) * $need_money_from_etf / $single_etf_price);

                echo 'We need to sell ' . $need_sell_etf_lots . ' LQDT lots' . PHP_EOL;

                if ($need_sell_etf_lots > $money_etf_quantity) {
                    echo 'There are not that many ETF items for sale' . PHP_EOL;

                    static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                    return;
                }

                if ($need_sell_etf_lots > 0) {
                    $this->sellTaskLogic($account->accountId, 'etf', $money_etf_instrument->getTicker(), $need_sell_etf_lots);

                    $need_wait = true;
                }
            } else {
                echo 'Money is enough' . PHP_EOL;
            }

            if ($need_wait) {
                sleep(15);
            }

            $this->buyTaskLogic($account->accountId, 'share', $target_instrument->getTicker(), $we_can_buy, $force_buy ? null : $current_buy_price_decimal);

            TelegramBot::notifyTelegram($account->accountId, 'Инициирована покупка акции [[' . $target_instrument->getTicker() . ']] ' . static::escapeMarkdown($target_instrument->getName()) . ' по цене ' . ($force_buy ? 'лучшей' : $current_buy_price_decimal));

            static::setForceRecalculateSharesTaskFlag($strategy_alias);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::STRATEGY_MANAGE_LOG_TARGET);
        }

        static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);
    }

    /**
     * Автопарковка денежных средств портфеля в фонд денежных средств
     *
     * @param string $account Алиас или идентификатор аккаунта
     * @param string $type Тип инструмента
     * @param string $ticker Тикер инструмента
     * @param int|null $lots Целевое количество лотов или <code>null</code> для авто вычисления
     * @param float|null $price Объем средств к парковке
     */
    public function actionParkFreeMoney(string $account, string $type, string $ticker, int $lots = null, float $price = null): void
    {
        static::stdoutStart(__FUNCTION__, static::STRATEGY_MANAGE_LOG_TARGET);

        try {
            $account = TIAccount::create($account);
            $tinkoff_instruments = TIServices::instruments($account->profile);
            $marketdata = TIServices::marketdata($account->profile);

            echo 'Ищем инструмент' . PHP_EOL;

            switch ($type) {
                case 'etf':
                    $target_instrument = $tinkoff_instruments->etfByTicker($ticker);

                    break;
                case 'share':
                    $target_instrument = $tinkoff_instruments->shareByTicker($ticker);

                    break;
                case 'bond':
                    $target_instrument = $tinkoff_instruments->bondByTicker($ticker);

                    break;
                default:
                    throw new Exception('Not supported type');
            }

            echo 'Найден инструмент: ' . $target_instrument->serializeToJsonString() . PHP_EOL;

            if (!InstrumentsHelper::isReadyToTrade($target_instrument, true, true, true, false)) {
                echo 'Покупка не доступна' . PHP_EOL;

                throw new Exception('Покупка не доступна');
            }

            echo 'Покупка доступна' . PHP_EOL;

            $top_prices = $marketdata->getOrderbookTopPrices($target_instrument);

            $top_ask_price = $top_prices['ask'] ?? null;
            $top_bid_price = $top_prices['bid'] ?? null;

            if (!$top_ask_price) {
                echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

                throw new Exception('Стакан пуст или биржа закрыта');
            }

            $current_buy_price = $top_ask_price;

            if ($price === null) {
                $current_buy_price_decimal = QuotationHelper::toCurrency($current_buy_price, $target_instrument);

                echo 'Целевая цена из стакана: ' . $current_buy_price_decimal . PHP_EOL;
            } else {
                $current_buy_price_decimal = $price;

                if (!QuotationHelper::isPriceValid($current_buy_price_decimal, $target_instrument)) {
                    echo 'Цена ' . $price . ' для инструмента не валидна, не подходящий шаг цены' . PHP_EOL;

                    static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);

                    return;
                }

                echo 'Целевая цена: ' . $current_buy_price_decimal . PHP_EOL;
            }

            /** @var Quotation $current_buy_price */
            $current_buy_price = QuotationHelper::toQuotation($current_buy_price_decimal);

            echo 'Сконвертированная цена: ' . $current_buy_price->serializeToJsonString() . PHP_EOL;

            $portfolio_currency = $this->portfolioMoney($account->accountId)[$target_instrument->getCurrency()] ?? null;
            $currency_decimal = $portfolio_currency ? $portfolio_currency['available']->asDecimal() : 0;

            echo 'Свободно средств на счете: ' . $currency_decimal . PHP_EOL;

            $commission = static::COMMISSION;

            $can_buy_lots = (int) floor(($currency_decimal / ($current_buy_price_decimal * (1 + $commission))) / $target_instrument->getLot());
            $can_buy_lots = max(0, $can_buy_lots - (int) ceil(($can_buy_lots / (100 * $current_buy_price_decimal))));

            $can_buy_lots = min($lots ?? $can_buy_lots, $can_buy_lots);

            echo 'Вычислено к покупке ' . $can_buy_lots . ' лотов' . PHP_EOL;

            $commission_estimate = (int) floor($can_buy_lots * $current_buy_price_decimal * $commission * 100);

            echo '$commission_estimate = ' . $commission_estimate . PHP_EOL;

            if ($commission_estimate <= 1) {
                $commission_correction = 1.5;

                $optimal_buy_lots = (int) floor($commission_correction / ($current_buy_price_decimal * $commission * 100));

                echo '$commission_correction = ' . $commission_correction . PHP_EOL;
                echo '$optimal_buy_lots = ' . $optimal_buy_lots . PHP_EOL;
            } else {
                $commission_correction = (float) $commission_estimate + 0.5;
                $optimal_buy_lots = (int) floor($commission_correction / ($current_buy_price_decimal * $commission * 100));

                echo '$commission_correction = ' . $commission_correction . PHP_EOL;
                echo '$optimal_buy_lots = ' . $optimal_buy_lots . PHP_EOL;

                if ($optimal_buy_lots > $can_buy_lots) {
                    $commission_correction = (float) $commission_estimate - 0.5;
                    $optimal_buy_lots = (int) floor($commission_correction / ($current_buy_price_decimal * $commission * 100));

                    echo '$commission_correction = ' . $commission_correction . PHP_EOL;
                    echo '$optimal_buy_lots = ' . $optimal_buy_lots . PHP_EOL;
                }
            }

            echo 'Оптимально по комиссии к покупке ' . $optimal_buy_lots . ' лотов' . PHP_EOL;

            if (($optimal_buy_lots > 0) && ($can_buy_lots >= $optimal_buy_lots)) {
                $order_id = static::placeBuyOrder($account, $target_instrument, $optimal_buy_lots, $current_buy_price);

                if (!$order_id) {
                    echo 'Ошибка отправки торговой заявки' . PHP_EOL;

                    throw new Exception('Ошибка отправки торговой заявки');
                }

                echo 'Заявка с идентификатором ' . $order_id . ' отправлена' . PHP_EOL;
            }
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::STRATEGY_MANAGE_LOG_TARGET);
        }

        static::stdoutEnd(static::STRATEGY_MANAGE_LOG_TARGET);
    }

    /**
     * Метод инициирует расчет баланса акций/облигаций портфеля и выбранной стратегии
     *
     *  Метод выводит данные в STDOUT и сохраняет лимит акций в кеш (если он больше минимально установленного лимита)
     *
     * @param string $strategy_alias Алиас стратегии
     * @param bool $notify Отправлять уведомление в телеграм
     */
    public function actionBalanceState(string $strategy_alias, bool $notify = false): void
    {
        $all_manage_strategies = Yii::$app->params['strategies']['manage'] ?? [];
        $selected_strategy = $all_manage_strategies[$strategy_alias] ?? [];
        $min_shares_money_limit = $selected_strategy['shares_money_limit'] ?? 0;

        try {
            $account = $selected_strategy['account'] ?? null;
            $account = TIAccount::create($account);

            list($portfolios_data, $total_data, $statistic, $balance_shares_percentage, $optimal_limit_for_shares) = $this->calculateBalanceState($strategy_alias);

            $message = 'Оценка содержимого портфелей в соответствии со стратегией: ' . PHP_EOL . PHP_EOL;

            if (!$portfolios_data) {
                echo 'Данные по указанной стратегии отсутствуют';

                return;
            }

            $template = "%-10s";
            $columns = ['Instrument'];
            $line = ['=========='];

            foreach ($portfolios_data as $account_alias => $data) {
                $template .= " | %10s";

                $columns[] = $account_alias;
                $line[] = '==========';
            }

            $template .= " | %10s | %10s";
            $columns[] = 'Total';
            $columns[] = 'Percentage';
            $line[] = '==========';
            $line[] = '==========';

            $message .= mb_sprintf($template, ...$columns) . PHP_EOL;
            $message .= mb_sprintf($template, ...$line) . PHP_EOL;

            foreach (['shares', 'bonds', 'money', 'ETFmoney'] as $type) {
                $columns = [$type];

                foreach ($portfolios_data as $data) {
                    $columns[] = NumbersHelper::printFloat($data[$type], 2, false);
                }

                $columns[] = NumbersHelper::printFloat($total_data[$type], 2, false);
                $columns[] = NumbersHelper::printFloat($statistic[$type], 2, false) . '%';

                $message .= mb_sprintf($template, ...$columns) . PHP_EOL;
            }

            $message .= mb_sprintf($template, ...$line) . PHP_EOL;

            $columns = ['total'];

            foreach ($portfolios_data as $data) {
                $columns[] = NumbersHelper::printFloat($data['total'], 2, false);
            }

            $columns[] = NumbersHelper::printFloat($total_data['total'], 2, false);
            $columns[] = NumbersHelper::printFloat($statistic['total'], 2, false) . '%';

            $message .= mb_sprintf($template, ...$columns) . PHP_EOL . PHP_EOL;

            echo PHP_EOL . $message;

            echo 'Процент выделенный на акции в рамках стратегии управления портфелем: ' . $balance_shares_percentage . '%' . PHP_EOL;
            echo 'Установленный минимальный объем акций для портфеля стратегии: ' . $min_shares_money_limit . ' rub' . PHP_EOL . PHP_EOL;

            echo 'Рассчитанный объема акций для портфеля стратегии: ' .
                NumbersHelper::printFloat($optimal_limit_for_shares, 2, false) . ' rub' . PHP_EOL . PHP_EOL;

            $message .= PHP_EOL . 'Минимальный объем акций: ' . $min_shares_money_limit . ' rub';
            $message .= PHP_EOL . 'Рассчитанный целевой объем акций (' . $balance_shares_percentage . '%): ' . NumbersHelper::printFloat($optimal_limit_for_shares, 2, false) . ' rub';

            if ($balance_shares_percentage && $optimal_limit_for_shares < $total_data['shares']) {
                echo 'В настоящее время акций в портфеле очень много. Необходимо докупить облигаций на сумму: ' .
                    NumbersHelper::printFloat(100 * $total_data['shares'] / $balance_shares_percentage  -  $total_data['total'], 2, false) . ' rub' . PHP_EOL . PHP_EOL;
            }

            if ($notify) {
                TelegramBot::notifyTelegram($account->accountId, '``` ' . static::escapeMarkdown($message) . '```');
            }
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }
    }

    /**
     * Метод инициирует расчет таблицы соответствия акций портфеля и выбранной стратегии
     *
     * Метод выводит данные в STDOUT и сохраняет стратегию в кеш
     *
     * @param string $strategy_alias Алиас стратегии
     */
    public function actionSharesState(string $strategy_alias): void
    {
        $all_manage_strategies = Yii::$app->params['strategies']['manage'] ?? [];
        $selected_strategy = $all_manage_strategies[$strategy_alias] ?? [];

        $shares_money_limit = max(
            $selected_strategy['shares_money_limit'] ?? 0,
            Yii::$app->cache->get(static::cacheKeyPortfolioSharesLimit($strategy_alias)) ?? 0
        );

        try {
            $account = $selected_strategy['account'] ?? null;
            $account = TIAccount::create($account);

            $portfolio = TIServices::portfolio($account->profile);

            /**
             * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
             */
            $response = $portfolio->getPortfolio($account->accountId);

            $shares_price = Price::createFromMoneyValue($response->getTotalAmountShares());
            $bonds_price = Price::createFromMoneyValue($response->getTotalAmountBonds());
            $etf_price = Price::createFromMoneyValue($response->getTotalAmountETF());
            $futures_price = Price::createFromMoneyValue($response->getTotalAmountFutures());
            $currencies = Price::createFromMoneyValue($response->getTotalAmountCurrencies());

            $total_portfolio_volume = $shares_price->asDecimal() + $bonds_price->asDecimal() + $etf_price->asDecimal() + $futures_price->asDecimal() + $currencies->asDecimal();

            echo 'Общая оценка стоимости портфеля: ' . NumbersHelper::printFloat($total_portfolio_volume) . PHP_EOL . PHP_EOL;

            echo 'Акции: ' . $shares_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $shares_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Облигации: ' . $bonds_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $bonds_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Фонды: ' . $etf_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $etf_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Фьючерсы: ' . $futures_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $futures_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Деньги: ' . $currencies->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $currencies->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL . PHP_EOL;

            $shares_portfolio_volume = $shares_price->asDecimal();
            $bond_portfolio_volume = $bonds_price->asDecimal();

            $main_portfolio_volume = $shares_portfolio_volume + $bonds_price->asDecimal();

            echo 'Соотношение Акций / Облигаций: ' . ($main_portfolio_volume > 0 ? NumbersHelper::printFloat(100 * $shares_portfolio_volume / $main_portfolio_volume) . '% / ' . NumbersHelper::printFloat(100 * $bond_portfolio_volume / $main_portfolio_volume) . '%' : '- / -') . PHP_EOL . PHP_EOL;

            echo PHP_EOL;

            echo 'Анализ акций в портфеле ' . $account->accountId . ' на предмет соответствия стратегии ' . $strategy_alias . PHP_EOL;
            echo 'В рамках стратегии на акции выделен лимит средств: ' . $shares_money_limit . PHP_EOL;
            echo 'В настоящее время акции в портфеле стоят: ' . $shares_price->asString(2) . PHP_EOL;

            $free_strategy_limit = max(0, $shares_money_limit - $shares_price->asDecimal());

            echo ($free_strategy_limit > 0 ? 'Необходимо докупить акции на сумму: ' . NumbersHelper::printFloat($free_strategy_limit) : 'Стоимость акций выше лимита в настоящее время') . PHP_EOL;

            echo PHP_EOL . 'Оценка долей акций в соответствии с заданием стратегии: ' . PHP_EOL . PHP_EOL;

            list ($positions_percentage, $shares_task, $strategy_need_money) = $this->calculateSharesState($strategy_alias);

            printf("%-10s | %-16s | %10s | %10s | %10s | %10s | %10s | %10s | %10s | %10s | %10s | %10s",
                'TICKER',
                'NAME',
                'TAR, %',
                'CUR, %',
                'TAR, COUNT',
                'CUR, COUNT',
                'BUY COUNT',
                'BUY LOTS',
                'INC, PRICE',
                'TAR, PRICE',
                'CUR, PRICE',
                'NEED MONEY'
            );

            echo PHP_EOL;
            printf("===============================================================================================================================================================");

            echo PHP_EOL;

            foreach ($positions_percentage as $ticker => $value) {
                $color = Console::FG_GREEN;

                if ($value['target_lots_to_buy'] > 0) {
                    $color = Console::FG_BLUE;
                } elseif ($value['target_quantity'] === 0) {
                    $color = Console::FG_RED;
                } elseif (($value['target_quantity'] ?? 0) < ($value['current_quantity'] ?? 0)) {
                    $color = Console::FG_CYAN;
                }

                $this->stdout(
                    mb_sprintf("%-10s | %-16s | %10s | %10s | %10s | %10s | %10s | %10s | %10s | %10s | %10s | %10s",
                        $ticker,
                        mb_substr($value['name'], 0, 16),

                        NumbersHelper::printFloat($value['target_percentage'], 2, false) . '%',
                        NumbersHelper::printFloat($value['current_percentage'], 2, false) . '%',

                        NumbersHelper::printFloat($value['target_quantity'], 0, false),
                        NumbersHelper::printFloat($value['current_quantity'], 0, false),

                        NumbersHelper::printFloat($value['target_quantity_to_buy'], 0, false),
                        NumbersHelper::printFloat($value['target_lots_to_buy'], 0, false),

                        NumbersHelper::printFloat($value['current_lot_size'] * $value['current_price'], 2, false),

                        NumbersHelper::printFloat($value['target_position_price'], 2, false),
                        NumbersHelper::printFloat($value['current_position_price'], 2, false),

                        NumbersHelper::printFloat($value['position_need_money'], 2, false),
                    ),
                    $color
                );

                echo PHP_EOL;
            }

            printf("===============================================================================================================================================================" . PHP_EOL);

            echo mb_sprintf("%-10s   %-16s   %10s   %10s   %10s   %10s   %10s   %10s   %10s   %10s   %10s | %10s",
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                'Нужно:',
                NumbersHelper::printFloat($strategy_need_money, 2, false)
            );

            echo PHP_EOL;
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }
    }

    /**
     * Метод инициирует расчет таблицы соответствия облигаций портфеля и выбранной стратегии
     *
     * Метод выводит данные в STDOUT
     *
     * @param string $strategy_alias Алиас стратегии
     */
    public function actionBondsState(string $strategy_alias): void
    {
        $all_manage_strategies = Yii::$app->params['strategies']['manage'] ?? [];
        $selected_strategy = $all_manage_strategies[$strategy_alias] ?? [];

        try {
            $account = $selected_strategy['account'] ?? null;
            $account = TIAccount::create($account);

            $portfolio = TIServices::portfolio($account->profile);

            /**
             * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
             */
            $response = $portfolio->getPortfolio($account->accountId);

            $shares_price = Price::createFromMoneyValue($response->getTotalAmountShares());
            $bonds_price = Price::createFromMoneyValue($response->getTotalAmountBonds());
            $etf_price = Price::createFromMoneyValue($response->getTotalAmountETF());
            $futures_price = Price::createFromMoneyValue($response->getTotalAmountFutures());
            $currencies = Price::createFromMoneyValue($response->getTotalAmountCurrencies());

            $total_portfolio_volume = $shares_price->asDecimal() + $bonds_price->asDecimal() + $etf_price->asDecimal() + $futures_price->asDecimal() + $currencies->asDecimal();

            echo 'Общая оценка стоимости портфеля: ' . NumbersHelper::printFloat($total_portfolio_volume) . PHP_EOL . PHP_EOL;

            echo 'Акции: ' . $shares_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $shares_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Облигации: ' . $bonds_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $bonds_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Фонды: ' . $etf_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $etf_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Фьючерсы: ' . $futures_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $futures_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Деньги: ' . $currencies->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $currencies->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL . PHP_EOL;

            $shares_portfolio_volume = $shares_price->asDecimal();
            $bond_portfolio_volume = $bonds_price->asDecimal();

            $main_portfolio_volume = $shares_portfolio_volume + $bonds_price->asDecimal();

            echo 'Соотношение Акций / Облигаций: ' . ($main_portfolio_volume > 0 ? NumbersHelper::printFloat(100 * $shares_portfolio_volume / $main_portfolio_volume) . '% / ' . NumbersHelper::printFloat(100 * $bond_portfolio_volume / $main_portfolio_volume) . '%' : '- / -') . PHP_EOL . PHP_EOL;

            echo 'Анализ облигаций в портфеле ' . $account->accountId . ' на предмет соответствия стратегии ' . $strategy_alias . PHP_EOL;

            echo PHP_EOL . 'Оценка выполнения задания стратегии: ' . PHP_EOL . PHP_EOL;

            list ($positions_quantity, $bonds_task, $strategy_need_money) = $this->calculateBondsState($strategy_alias);

            printf("%-16s | %-16s | %10s | %10s | %10s | %10s | %10s | %10s | %10s",
                'TICKER',
                'NAME',
                'TAR, COUNT',
                'CUR, COUNT',
                'BUY COUNT',
                'INC, PRICE',
                'TAR, PRICE',
                'CUR, PRICE',
                'NEED MONEY'
            );

            echo PHP_EOL;
            printf("==============================================================================================================================");

            echo PHP_EOL;

            foreach ($positions_quantity as $ticker => $value) {
                $color = Console::FG_GREEN;

                if ($value['target_quantity_to_buy'] > 0) {
                    $color = Console::FG_BLUE;
                } elseif ($value['target_quantity'] === 0) {
                    $color = Console::FG_RED;
                } elseif (($value['target_quantity'] ?? 0) < ($value['current_quantity'] ?? 0)) {
                    $color = Console::FG_CYAN;
                }

                $this->stdout(
                    mb_sprintf("%-16s | %-16s | %10s | %10s | %10s | %10s | %10s | %10s | %10s",
                        $ticker,
                        mb_substr($value['name'], 0, 16),

                        NumbersHelper::printFloat($value['target_quantity'], 0, false),
                        NumbersHelper::printFloat($value['current_quantity'], 0, false),

                        NumbersHelper::printFloat($value['target_quantity_to_buy'], 0, false),

                        NumbersHelper::printFloat($value['current_price'], 2, false),
                        NumbersHelper::printFloat($value['target_position_price'], 2, false),
                        NumbersHelper::printFloat($value['current_position_price'], 2, false),

                        NumbersHelper::printFloat($value['position_need_money'], 2, false),
                    ),
                    $color
                );

                echo PHP_EOL;
            }

            printf("==============================================================================================================================" . PHP_EOL);

            echo mb_sprintf("%-16s   %-16s   %10s   %10s   %10s   %10s   %10s   %10s | %10s",
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                'Нужно:',
                NumbersHelper::printFloat($strategy_need_money, 2, false)
            );

            echo PHP_EOL;
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }
    }

    /**
     * Метод расчета соответствия баланса портфелей и задания стратегии
     *
     * @param string $strategy_alias Алиас стратегии
     *
     * @return array Массив вида <code>[(array) $portfolios_data, (array) $total_data, (array) $statistic, (float) $balance_shares_percentage, (float) $optimal_limit_for_shares];</code>
     */
    public static function calculateBalanceState(string $strategy_alias): array
    {
        $all_manage_strategies = Yii::$app->params['strategies']['manage'] ?? [];
        $selected_strategy = $all_manage_strategies[$strategy_alias] ?? [];

        $balance_control_accounts = $selected_strategy['balance_control_accounts'] ?? [];
        $balance_shares_percentage = $selected_strategy['balance_shares_percentage'] ?? 0;

        $target_account_alias = $selected_strategy['account'] ?? null;

        $parking_money_etf = $selected_strategy['parking_money_etf'] ?? null;

        $total_data = [
            'shares' => 0,
            'bonds' => 0,
            'money' => 0,
            'ETFmoney' => 0,
            'total' => 0,
        ];

        $portfolios_data = [];

        $optimal_limit_for_shares = 0;

        try {
            foreach ($balance_control_accounts as $account_alias) {
                $account = TIAccount::create($account_alias);
                $portfolio = TIServices::portfolio($account->profile);

                /**
                 * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
                 */
                $response = $portfolio->getPortfolio($account->accountId);

                $shares = Price::createFromMoneyValue($response->getTotalAmountShares())->asDecimal();
                $bonds = Price::createFromMoneyValue($response->getTotalAmountBonds())->asDecimal();
                $money = Price::createFromMoneyValue($response->getTotalAmountCurrencies())->asDecimal();

                $money_etf = 0;

                if ($parking_money_etf) {
                    $portfolio_money_etf = static::portfolioMoneyETF($account->accountId, static::tickersInstruments($account->profile, 'etf', [$parking_money_etf]));

                    foreach ($portfolio_money_etf as $money_etf_data) {
                        $money_etf += $money_etf_data['price'] ?? 0;
                    }
                }

                $portfolio_total = $shares + $bonds + $money + $money_etf;

                $portfolios_data[$account_alias] = [
                    'shares' => $shares,
                    'bonds' => $bonds,
                    'money' => $money,
                    'ETFmoney' => $money_etf,
                    'total' => $portfolio_total,
                ];

                $total_data['shares'] += $shares;
                $total_data['bonds'] += $bonds;
                $total_data['money'] += $money;
                $total_data['ETFmoney'] += $money_etf;
                $total_data['total'] += $portfolio_total;

                if ($account_alias !== $target_account_alias) {
                    $optimal_limit_for_shares -= $shares;
                }
            }

            $total_money = $total_data['total'] ?? 0;

            foreach ($total_data as $key => $value) {
                $statistic[$key] = $total_money > 0 ? 100 * $value / $total_money : 0;
            }

            $optimal_limit_for_shares += $total_money * $balance_shares_percentage / 100;

            if ($balance_shares_percentage) {
                if ($optimal_limit_for_shares >= ($portfolios_data[$target_account_alias]['shares'] ?? 0)) {
                    Yii::$app->cache->set(static::cacheKeyPortfolioSharesLimit($strategy_alias), $optimal_limit_for_shares, 20 * 60 * 60);
                } elseif ($optimal_limit_for_shares < Yii::$app->cache->get(static::cacheKeyPortfolioSharesLimit($strategy_alias))) {
                    Yii::$app->cache->set(static::cacheKeyPortfolioSharesLimit($strategy_alias), $selected_strategy['shares_money_limit'] ?? $optimal_limit_for_shares, 20 * 60 * 60);
                }
            }
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        return [$portfolios_data ?? [], $total_data ?? [], $statistic ?? [], $balance_shares_percentage ?? 0, $optimal_limit_for_shares ?? 0];
    }

    /**
     * Метод расчета соответствия акций портфеля и задания стратегии
     *
     * @param string $strategy_alias Алиас стратегии
     *
     * @return array Массив вида <code>[(array) $positions_percentage, (array) $shares_task, (float) $strategy_need_money];</code>
     */
    protected function calculateSharesState(string $strategy_alias): array
    {
        $all_manage_strategies = Yii::$app->params['strategies']['manage'] ?? [];
        $selected_strategy = $all_manage_strategies[$strategy_alias] ?? [];
        $strategy_weights = $selected_strategy['shares'] ?? [];

        $shares_money_limit = max(
            $selected_strategy['shares_money_limit'] ?? 0,
            Yii::$app->cache->get(static::cacheKeyPortfolioSharesLimit($strategy_alias)) ?? 0
        );

        $positions_percentage = [];
        $shares_task = [];
        $strategy_need_money = 0;

        try {
            $account = $selected_strategy['account'] ?? null;
            $account = TIAccount::create($account);

            $portfolio = TIServices::portfolio($account->profile);
            $instruments = TIServices::instruments($account->profile);
            $marketdata = TIServices::marketdata($account->profile);

            /**
             * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
             */
            $response = $portfolio->getPortfolio($account->accountId);

            $shares_price = Price::createFromMoneyValue($response->getTotalAmountShares());

            $shares_portfolio_volume = $shares_price->asDecimal();
            $positions = ArrayHelper::repeatedFieldToArray($response->getPositions());

            /** @var PortfolioPosition $position */
            foreach ($positions as $position) {
                if ($position->getInstrumentType() === 'share') {
                    $instrument = $instruments->shareByFigi($position->getFigi());

                    $current_price = Price::createFromMoneyValue($position->getCurrentPrice());
                    $current_quantity = Quantity::createFromQuotation($position->getQuantity());

                    $current_position_price = $current_price->asDecimal() * $current_quantity->asDecimal();
                    $current_percentage = $shares_portfolio_volume > 0 ? 100 * $current_position_price / $shares_portfolio_volume : -1;

                    $target_percentage = $strategy_weights[$position->getTicker()] ?? 0;
                    $target_position_price = $shares_money_limit * $target_percentage / 100;

                    $target_quantity = (int) floor($target_position_price / $current_price->asDecimal());
                    $target_quantity_to_buy = (int) max($target_quantity - $current_quantity->asDecimal(), 0);
                    $target_lots_to_buy = $target_quantity_to_buy;

                    if ($instrument_lot_size = $instrument->getLot()) {
                        $target_quantity = (int) ((floor($target_quantity / $instrument_lot_size)) * $instrument_lot_size);
                        $target_quantity_to_buy = (int) max($target_quantity - $current_quantity->asDecimal(), 0);
                        $target_lots_to_buy = (int) ($target_quantity_to_buy / $instrument_lot_size);
                    }

                    $position_need_money = $target_quantity_to_buy * $current_price->asDecimal();
                    $strategy_need_money += $position_need_money;

                    $positions_percentage[$position->getTicker()] = [
                        'name' => $instrument->getName(),

                        'current_price' => $current_price->asDecimal(),
                        'current_lot_size' => $instrument_lot_size,

                        'target_percentage' => $target_percentage,
                        'current_percentage' => $current_percentage,

                        'target_quantity' => $target_quantity,
                        'current_quantity' => $current_quantity->asDecimal(),

                        'target_quantity_to_buy' => $target_quantity_to_buy,
                        'target_lots_to_buy' => $target_lots_to_buy,

                        'target_position_price' => $target_position_price,
                        'current_position_price' => $current_position_price,

                        'position_need_money' => $position_need_money,
                    ];

                    unset($strategy_weights[$position->getTicker()]);
                }
            }

            uasort($positions_percentage, function ($a, $b) {
                return $b['target_percentage'] <=> $a['target_percentage'];
            });

            arsort($strategy_weights);

            foreach ($strategy_weights as $ticker => $weight) {
                $instrument = $instruments->shareByTicker($ticker);
                $top_prices = $marketdata->getOrderbookTopPrices($instrument);

                $current_price = $top_prices['ask'] ?? null;
                $current_price = $current_price ? Price::createFromQuotation($current_price) : null;

                $target_percentage = $weight;
                $target_position_price = $shares_money_limit * $target_percentage / 100;

                $target_quantity = $current_price && $current_price->asDecimal() > 0 ? $target_position_price / $current_price->asDecimal() : 0;
                $target_quantity_to_buy = (int) $target_quantity;
                $target_lots_to_buy = $target_quantity_to_buy;

                if ($instrument_lot_size = $instrument->getLot()) {
                    $target_quantity = (int) ((floor($target_quantity / $instrument_lot_size)) * $instrument_lot_size);
                    $target_quantity_to_buy = (int) max($target_quantity, 0);
                    $target_lots_to_buy = (int) ($target_quantity_to_buy / $instrument_lot_size);
                }

                $position_need_money = $current_price ? $target_quantity_to_buy * $current_price->asDecimal() : 0;
                $strategy_need_money += $position_need_money;

                $positions_percentage[$instrument->getTicker()] = [
                    'name' => $instrument->getName(),

                    'current_price' => $current_price ? $current_price->asDecimal() : 0,
                    'target_percentage' => $target_percentage,
                    'current_percentage' => 0,

                    'target_quantity' => $target_quantity,
                    'current_quantity' => 0,

                    'target_quantity_to_buy' => $target_quantity_to_buy,
                    'target_lots_to_buy' => $target_lots_to_buy,

                    'target_position_price' => $target_position_price,
                    'current_position_price' => 0,

                    'position_need_money' => $position_need_money,
                ];
            }

            $shares_task = [];

            foreach ($positions_percentage as $ticker => $value) {
                if (($value['target_lots_to_buy'] ?? 0) > 0) {
                    $shares_task[$ticker] = $value['target_quantity'];
                }
            }

            if ($shares_task) {
                if (count($shares_task) > 5) {
                    $shares_task = array_slice($shares_task, 0, 5, true);
                }

                echo 'Сформировано и подготовлено задание на покупку акций: ' . PHP_EOL . PHP_EOL;

                printf("%-10s | %10s",
                    'TICKER', 'TARGET'
                );

                echo PHP_EOL;

                printf("=======================" . PHP_EOL);

                foreach ($shares_task as $ticker => $target) {
                    $this->stdout(
                        mb_sprintf("%-10s | %10s",
                            $ticker,
                            $target
                        ),
                        Console::FG_CYAN
                    );

                    echo PHP_EOL;
                }
                printf("=======================" . PHP_EOL . PHP_EOL);
            } else {
                echo 'Активных подготовленных заданий на покупку акций пока нет' . PHP_EOL . PHP_EOL;
            }

            Yii::$app->cache->set(static::cacheKeyStrategySharesTask($strategy_alias), $shares_task, 20 * 60 * 60);
            Yii::$app->cache->set(static::cacheKeyStrategySharesPercentage($strategy_alias), $positions_percentage, 20 * 60 * 60);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        return [$positions_percentage, $shares_task, $strategy_need_money];
    }

    /**
     * Метод расчета соответствия облигаций портфеля и задания стратегии
     *
     * @param string $strategy_alias Алиас стратегии
     *
     * @return array Массив вида <code>[(array) $positions_percentage, (array) $shares_task, (float) $strategy_need_money];</code>
     */
    protected function calculateBondsState(string $strategy_alias): array
    {
        $all_manage_strategies = Yii::$app->params['strategies']['manage'] ?? [];
        $selected_strategy = $all_manage_strategies[$strategy_alias] ?? [];
        $strategy_quantity = $selected_strategy['bonds'] ?? [];

        $positions_quantity = [];
        $bonds_task = [];
        $strategy_need_money = 0;

        try {
            $account = $selected_strategy['account'] ?? null;
            $account = TIAccount::create($account);

            $portfolio = TIServices::portfolio($account->profile);
            $instruments = TIServices::instruments($account->profile);
            $marketdata = TIServices::marketdata($account->profile);

            /**
             * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
             */
            $response = $portfolio->getPortfolio($account->accountId);

            $positions = ArrayHelper::repeatedFieldToArray($response->getPositions());

            /** @var PortfolioPosition $position */
            foreach ($positions as $position) {
                if ($position->getInstrumentType() === 'bond') {
                    if (!array_key_exists($position->getTicker(), $strategy_quantity)) {
                        continue;
                    }

                    $instrument = $instruments->bondByFigi($position->getFigi());

                    $current_price = Price::createFromMoneyValue($position->getCurrentPrice());

                    $current_quantity = Quantity::createFromQuotation($position->getQuantity());
                    $target_quantity = $strategy_quantity[$position->getTicker()] ?? 0;

                    $current_position_price = $current_price->asDecimal() * $current_quantity->asDecimal();
                    $target_position_price = $current_price->asDecimal() * $target_quantity;

                    $target_quantity_to_buy = (int) max($target_quantity - $current_quantity->asDecimal(), 0);

                    $position_need_money = $target_quantity_to_buy * $current_price->asDecimal();
                    $strategy_need_money += $position_need_money;

                    $positions_quantity[$position->getTicker()] = [
                        'name' => $instrument->getName(),

                        'current_price' => $current_price->asDecimal(),

                        'target_quantity' => $target_quantity,
                        'current_quantity' => $current_quantity->asDecimal(),

                        'target_quantity_to_buy' => $target_quantity_to_buy,

                        'target_position_price' => $target_position_price,
                        'current_position_price' => $current_position_price,

                        'position_need_money' => $position_need_money,
                    ];

                    unset($strategy_quantity[$position->getTicker()]);
                }
            }

            uasort($positions_quantity, function ($a, $b) {
                return $b['current_position_price'] <=> $a['current_position_price'];
            });

            arsort($strategy_quantity);

            foreach ($strategy_quantity as $ticker => $quantity) {
                $instrument = $instruments->bondByTicker($ticker);
                $top_prices = $marketdata->getOrderbookTopPrices($instrument);

                $current_price = $top_prices['ask'] ?? null;
                $current_price = $current_price ? QuotationHelper::toCurrency($current_price, $instrument) : null;

                $target_quantity = $quantity;
                $target_quantity_to_buy = (int) $target_quantity;

                $target_position_price = $position_need_money = $current_price ? $target_quantity_to_buy * $current_price : 0;
                $strategy_need_money += $position_need_money;

                $positions_quantity[$instrument->getTicker()] = [
                    'name' => $instrument->getName(),

                    'current_price' => $current_price ?: 0,

                    'target_quantity' => $target_quantity,
                    'current_quantity' => 0,

                    'target_quantity_to_buy' => $target_quantity_to_buy,

                    'target_position_price' => $target_position_price,
                    'current_position_price' => 0,

                    'position_need_money' => $position_need_money,
                ];
            }

            $bonds_task = [];

            foreach ($positions_quantity as $ticker => $value) {
                if (($value['target_quantity_to_buy'] ?? 0) > 0) {
                    $bonds_task[$ticker] = $value['target_quantity'];
                }
            }

            if ($bonds_task) {
                echo 'Задание на покупку облигаций сейчас выглядит так: ' . PHP_EOL . PHP_EOL;

                printf("%-16s | %10s",
                    'TICKER', 'TARGET'
                );

                echo PHP_EOL;

                printf("=============================" . PHP_EOL);

                foreach ($bonds_task as $ticker => $target) {
                    $this->stdout(
                        mb_sprintf("%-16s | %10s",
                            $ticker,
                            $target
                        ),
                        Console::FG_CYAN
                    );

                    echo PHP_EOL;
                }
                printf("=============================" . PHP_EOL . PHP_EOL);
            } else {
                echo 'Активных подготовленных заданий на покупку облигаций пока нет' . PHP_EOL . PHP_EOL;
            }
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        return [$positions_quantity, $bonds_task, $strategy_need_money];
    }

    /**
     * Метод первичной подготовки данных об инструментах задания
     *
     * @param TIAccount $account DTO кредов аккаунта
     * @param array $buy_settings Настройки задания на покупку облигаций
     *
     * @return array Массив подготовленных данных
     *
     * @throws InstrumentNotFoundException
     * @throws Exception
     */
    protected static function prepareTaskInstruments(TIAccount $account, string $type, array $buy_settings): array
    {
        $tinkoff_instruments = TIServices::instruments($account->profile);

        echo 'Base task to buy bonds: ' . Log::logSerialize($buy_settings) . PHP_EOL;

        $tasks_to_buy_bonds = [];

        foreach ($buy_settings as $ticker => $limit) {
            $instrument = null;

            switch ($type) {
                case 'bond':
                    $instrument = $tinkoff_instruments->bondByTicker($ticker);

                    break;
                case 'share':
                    $instrument = $tinkoff_instruments->shareByTicker($ticker);

                    break;
            }


            if (!$instrument) {
                echo 'INSTRUMENTS CHECK ' . $ticker . ' -> Not found' . PHP_EOL;

                continue;
            }

            if (!InstrumentsHelper::isReadyToTrade($instrument, true, true, true, false)) {
                echo 'INSTRUMENTS CHECK ' . $ticker . ' -> Buy not available' . PHP_EOL;

                continue;
            }

            $tasks_to_buy_bonds[$ticker] = [
                'instrument' => $instrument,
                'limit' => $limit,
                'prior' => false,
            ];
        }

        return $tasks_to_buy_bonds;
    }

    /**
     * Метод подготовки заданий на покупку облигаций с проверкой цен и средств в наличии в портфеле
     *
     * @param TIAccount $account DTO кредов аккаунта
     * @param array $tasks_to_buy_bonds Предварительно подготовленное задание
     * @param float|null $available_total_money Общее количество денег в портфеле
     * @param float|null $available_portfolio_money Количество денег в портфеле без учета фонда денежных средств
     *
     * @return array
     *
     * @throws ValidateException
     * @throws Exception|Throwable
     */
    protected static function prepareBondTaskPrices(TIAccount $account, array $tasks_to_buy_bonds, float $available_total_money = null, float $available_portfolio_money = null): array
    {
        $portfolio = TIServices::portfolio($account->profile);

        foreach ($tasks_to_buy_bonds as $ticker => $task) {
            /** @var Bond $task_instrument */
            $task_instrument = $task['instrument'];

            if (Yii::$app->cache->get('BOND_BUY_ENOUGH_' . $account->accountId . '_' . $task_instrument->getFigi())) {
                echo 'PRICE CHECK ' . $ticker . ' -> Cache buy enough' . PHP_EOL;

                unset($tasks_to_buy_bonds[$ticker]);
            }
        }

        if (empty($tasks_to_buy_bonds)) {
            return [];
        }

        $commission = static::COMMISSION;

        /** @var PortfolioPosition $portfolio_positions [] */
        $portfolio_positions = $portfolio->getPortfolioPositions($account->accountId);

        foreach ($tasks_to_buy_bonds as $ticker => $task) {
            echo 'PRICE CHECK ' . $ticker . ' -> ';

            $not_found_in_portfolio = true;

            foreach ($portfolio_positions as $position) {
                try {
                    /** @var Bond $task_instrument */
                    $task_instrument = $task['instrument'];

                    if ($task_instrument->getFigi() === $position->getFigi()) {
                        $not_found_in_portfolio = false;

                        $quantity = (int)QuotationHelper::toDecimal($position->getQuantity());

                        if ($quantity >= $task['limit']) {
                            unset($tasks_to_buy_bonds[$ticker]);

                            Yii::$app->cache->set('BOND_BUY_ENOUGH_' . $account->accountId . '_' . $position->getFigi(), 1, 12 * 60 * 60);

                            echo ' -> Buy enough. Cache set' . PHP_EOL;

                            TelegramBot::notifyTelegram($account->accountId, 'Задача по покупке облигаций [[' . $task_instrument->getTicker() . ']] завершена. Куплены ' . $quantity . ' шт.');

                            $not_found_in_portfolio = false;

                            break;
                        } else {
                            $tasks_to_buy_bonds[$ticker]['limit'] -= $quantity;
                        }

                        $position_price = QuotationHelper::toDecimal($position->getCurrentPrice());

                        $nkd = $task_instrument->getAciValue();

                        if ($nkd) {
                            $nkd_decimal = QuotationHelper::toDecimal($nkd);

                            $position_price += $nkd_decimal;
                        }

                        $position_price = $position_price * (1 + $commission);

                        if ($available_total_money && $position_price > $available_total_money) {
                            unset($tasks_to_buy_bonds[$ticker]);

                            echo 'Excluded, In portfolio, no money for (need ' . $position_price . ')' . PHP_EOL;
                        } elseif ($available_portfolio_money && $position_price <= $available_portfolio_money) {
                            $tasks_to_buy_bonds[$ticker]['prior'] = true;

                            echo 'Prior, In portfolio' . PHP_EOL;
                        } else {
                            echo 'Not prior, but possible' . PHP_EOL;
                        }
                    }
                } catch (Throwable $e) {
                    unset($tasks_to_buy_bonds[$ticker]);

                    echo 'Exception: ' . $e->getMessage() . PHP_EOL;
                }
            }

            if ($not_found_in_portfolio) {
                $task_instrument = $task['instrument'] ?? null;

                if (!$task_instrument) {
                    echo 'Excluded, no instrument' . PHP_EOL;

                    continue;
                }

                $instrument_price = static::orderbookPrice($account, $task_instrument);

                if (!$instrument_price) {
                    unset($tasks_to_buy_bonds[$ticker]);

                    echo 'Excluded, unknown price' . PHP_EOL;

                    continue;
                }

                $position_price = QuotationHelper::toCurrency($instrument_price, $task_instrument) * (1 + $commission);

                if ($available_total_money && $position_price > $available_total_money) {
                    unset($tasks_to_buy_bonds[$ticker]);

                    echo 'Excluded, Not in portfolio, no money for (need ' . $position_price . ')' . PHP_EOL;
                } elseif ($available_portfolio_money && $position_price <= $available_portfolio_money) {
                    $tasks_to_buy_bonds[$ticker]['prior'] = true;

                    echo 'Prior, Not in portfolio' . PHP_EOL;
                }
            }
        }

        return $tasks_to_buy_bonds;
    }

    /**
     * Метод подготовки заданий на покупку акций с проверкой цен и средств в наличии в портфеле
     *
     * @param TIAccount $account DTO кредов аккаунта
     * @param array $tasks_to_buy_shares Предварительно подготовленное задание
     * @param float|null $available_total_money Общее количество денег в портфеле
     * @param float|null $available_portfolio_money Количество денег в портфеле без учета фонда денежных средств
     *
     * @return array
     *
     * @throws ValidateException
     * @throws Exception|Throwable
     */
    protected static function prepareSharesTaskPrices(TIAccount $account, array $tasks_to_buy_shares, float $available_total_money = null, float $available_portfolio_money = null): array
    {
        $portfolio = TIServices::portfolio($account->profile);

        foreach ($tasks_to_buy_shares as $ticker => $task) {
            /** @var Share $task_instrument */
            $task_instrument = $task['instrument'];

            if (Yii::$app->cache->get('SHARE_BUY_ENOUGH_' . $account->accountId . '_' . $task_instrument->getFigi())) {
                echo 'PRICE CHECK ' . $ticker . ' -> Cache buy enough' . PHP_EOL;

                unset($tasks_to_buy_shares[$ticker]);
            }
        }

        if (empty($tasks_to_buy_shares)) {
            return [];
        }

        $commission = static::COMMISSION;

        /** @var PortfolioPosition $portfolio_positions [] */
        $portfolio_positions = $portfolio->getPortfolioPositions($account->accountId);

        foreach ($tasks_to_buy_shares as $ticker => $task) {
            echo 'PRICE CHECK ' . $ticker . ' -> ';

            $not_found_in_portfolio = true;

            foreach ($portfolio_positions as $position) {
                try {
                    /** @var Share $task_instrument */
                    $task_instrument = $task['instrument'];

                    if ($task_instrument->getFigi() === $position->getFigi()) {
                        $not_found_in_portfolio = false;

                        $quantity = (int) QuotationHelper::toDecimal($position->getQuantity());

                        if ($quantity >= $task['limit']) {
                            unset($tasks_to_buy_shares[$ticker]);

                            Yii::$app->cache->set('SHARE_BUY_ENOUGH_' . $account->accountId . '_' . $position->getFigi(), 1, 12 * 60 * 60);

                            echo ' -> Buy enough. Cache set' . PHP_EOL;

                            TelegramBot::notifyTelegram($account->accountId, 'Задача по покупке акции [[' . $task_instrument->getTicker() . ']] завершена. Куплены ' . $quantity . ' лотов.');

                            $not_found_in_portfolio = false;

                            break;
                        } else {
                            $tasks_to_buy_shares[$ticker]['limit'] -= $quantity;
                        }

                        $position_price = $task_instrument->getLot() * QuotationHelper::toDecimal($position->getCurrentPrice()) * (1 + $commission);

                        if ($available_total_money && $position_price > $available_total_money) {
                            unset($tasks_to_buy_shares[$ticker]);

                            echo 'Excluded, In portfolio, no money for (need ' . $position_price . ')' . PHP_EOL;
                        } elseif ($available_portfolio_money && $position_price <= $available_portfolio_money) {
                            $tasks_to_buy_shares[$ticker]['prior'] = true;

                            echo 'Prior, In portfolio' . PHP_EOL;
                        } else {
                            echo 'Not prior, but possible' . PHP_EOL;
                        }
                    }
                } catch (Throwable $e) {
                    unset($tasks_to_buy_shares[$ticker]);

                    echo 'Exception: ' . $e->getMessage() . PHP_EOL;
                }
            }

            if ($not_found_in_portfolio) {
                $task_instrument = $task['instrument'] ?? null;

                if (!$task_instrument) {
                    echo 'Excluded, no instrument' . PHP_EOL;

                    continue;
                }

                $instrument_price = static::orderbookPrice($account, $task_instrument);

                if (!$instrument_price) {
                    unset($tasks_to_buy_shares[$ticker]);

                    echo 'Excluded, unknown price' . PHP_EOL;

                    continue;
                }

                $instrument_lot_size = $task_instrument->getLot();

                $position_price = $instrument_lot_size * QuotationHelper::toCurrency($instrument_price, $task_instrument) * (1 + $commission);

                if ($available_total_money && $position_price > $available_total_money) {
                    unset($tasks_to_buy_shares[$ticker]);

                    echo 'Excluded, Not in portfolio, no money for (need ' . $position_price . ')' . PHP_EOL;
                } elseif ($available_portfolio_money && $position_price <= $available_portfolio_money) {
                    $tasks_to_buy_shares[$ticker]['prior'] = true;

                    echo 'Prior, Not in portfolio' . PHP_EOL;
                }
            }
        }

        return $tasks_to_buy_shares;
    }

    /**
     * Метод поиска активных заявок на покупку инструментов из списка задания
     *
     * @param TIAccount $account DTO кредов аккаунта
     * @param array $tasks_to_buy_bonds Предварительно подготовленное задание
     *
     * @return array Список активных заявок
     *
     * @throws ValidateException
     * @throws Exception
     */
    protected static function findTasksRelatedOrders(TIAccount $account, array $tasks_to_buy_bonds): array
    {
        $tinkoff_api = TIServices::apiClient($account->profile);

        $tasks_related_orders = [];

        echo 'Lets check current orders!' . PHP_EOL;

        $order_request = new GetOrdersRequest();
        $order_request->setAccountId($account->accountId);

        /** @var GetOrdersResponse $response */
        list($response, $status) = $tinkoff_api->ordersServiceClient->GetOrders($order_request)->wait();
        static::processRequestStatus($status, true);

        /** @var OrderState $order */
        foreach ($response->getOrders() as $order) {
            foreach ($tasks_to_buy_bonds as $ticker => $task) {
                if ($order->getFigi() === $task['instrument']->getFigi()) {
                    $order_id = $order->getOrderId();

                    echo 'Found order "' . $order_id . '" for instrument ' . $task['instrument']->getTicker() . PHP_EOL;

                    $tasks_related_orders[] = [
                        'ticker' => $ticker,
                        'order_id' => $order_id,
                        'order' => $order,
                    ];
                }
            }
        }

        return $tasks_related_orders;
    }

    /**
     * Вычисление стоимости инструмента по стакану
     *
     * @param TIAccount $account DTO кредов аккаунта
     * @param Bond|Share $target_instrument Целевой инструмент
     * @param bool $force Флаг форсированной покупки
     *
     * @return Quotation|null Вычисленная цена или <code>null</code>, если покупка не возможна
     *
     * @throws ValidateException
     * @throws Exception|Throwable
     */
    protected static function orderbookPrice(TIAccount $account, $target_instrument, bool $force = false): ?Quotation
    {
        $marketdata = TIServices::marketdata($account->profile);

        $top_prices = $marketdata->getOrderbookTopPrices($target_instrument);

        $top_ask_price = $top_prices['ask'] ?? null;
        $top_bid_price = $top_prices['bid'] ?? null;

        if (!$top_ask_price || !$top_bid_price) {
            echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

            return null;
        }

        if ($force) {
            return $top_ask_price;
        } else {
            return $top_bid_price;
        }
    }

    /**
     * Получение ключа кеша, где хранится задание для покупки акций
     *
     * @param string $strategy_alias Имя-алиас стратегии
     *
     * @return string Ключ кеша
     */
    protected static function cacheKeyStrategySharesTask(string $strategy_alias): string
    {
        return 'shares_task@strategy:' . $strategy_alias;
    }

    /**
     * Получение ключа кеша, где хранится задание для покупки акций
     *
     * @param string $strategy_alias Имя-алиас стратегии
     *
     * @return string Ключ кеша
     */
    protected static function cacheKeyStrategySharesPercentage(string $strategy_alias): string
    {
        return 'shares_percentage@strategy:' . $strategy_alias;
    }

    /**
     * Получение ключа кеша, где хранится режим работы стратегии
     *
     * @param string $strategy_alias Имя-алиас стратегии
     *
     * @return string Ключ кеша
     */
    protected static function cacheKeyPortfolioSharesLimit(string $strategy_alias): string
    {
        return 'portfolio_shares_limit@strategy:' . $strategy_alias;
    }

    /**
     * Получение ключа кеша, где хранится режим работы стратегии
     *
     * @param string $strategy_alias Имя-алиас стратегии
     *
     * @return string Ключ кеша
     */
    protected static function cacheKeyRebalanceMode(string $strategy_alias): string
    {
        return 'rebalance_mode@strategy:' . $strategy_alias;
    }

    /**
     * Получение ключа кеша, где хранится флаг форсировать пересчет задания на покупку акций в рамках стратегии
     *
     * @param string $strategy_alias Имя-алиас стратегии
     *
     * @return string Ключ кеша
     */
    protected static function cacheKeyForceRecalculateShareTask(string $strategy_alias): string
    {
        return 'force_recalculate_shares_task@strategy:' . $strategy_alias;
    }

    /**
     * Установка значения флага необходимости форсировано пересчитать задание на покупку акций
     *
     * @param string $strategy_alias Алиас стратегии
     * @param bool $force Устанавливаемое значение флага необходимости форсировано пересчитать задание на покупку акций
     */
    public static function setForceRecalculateSharesTaskFlag(string $strategy_alias, bool $force = true): void
    {
        Yii::$app->cache->set(static::cacheKeyForceRecalculateShareTask($strategy_alias), $force ? 1 : 0, 60 * 60);
    }

    /**
     * Установка значения флага необходимости форсировано пересчитать задание на покупку акций по всем стратегиям аккаунта
     *
     * @param string $account Алиас или идентификатор аккаунта
     * @throws Exception
     */
    public static function forceRecalculateSharesTask(string $account): void
    {
        $account = TIAccount::create($account);

        $all_manage_strategies = Yii::$app->params['strategies']['manage'] ?? [];

        foreach ($all_manage_strategies as $strategy_alias => $strategy) {
            if ($strategy['active'] ?? false) {
                if ($account->accountId === TIAccount::create($strategy['account'])->accountId) {
                    if (!empty($strategy['shares'])) {
                        static::setForceRecalculateSharesTaskFlag($strategy_alias);
                    }
                }
            }
        }
    }

    /**
     * Получение флага необходимости форсировано пересчитать задание на покупку акций
     *
     * @param string $strategy_alias Алиас стратегии
     *
     * @return bool Флага необходимости форсировано пересчитать задание на покупку акций
     */
    protected static function getForceRecalculateSharesTask(string $strategy_alias): bool
    {
        return (bool) Yii::$app->cache->get(static::cacheKeyForceRecalculateShareTask($strategy_alias));
    }

    /**
     * Получение флагов режима работы стратегии
     *
     * @return array Массив содержащий флаги <code>[$economy_buy, $force_buy]</code>
     *
     * @throws Exception
     */
    protected static function strategyWorkType(): array
    {
        $economy_buy = static::isValidTradingPeriod(
            static::ECONOMY_STRATEGY_WORK_HOURS['start']['h'],
            static::ECONOMY_STRATEGY_WORK_HOURS['start']['m'],
            static::ECONOMY_STRATEGY_WORK_HOURS['end']['h'],
            static::ECONOMY_STRATEGY_WORK_HOURS['end']['m']
        );

        $force_buy = static::isValidTradingPeriod(
            static::FORCE_STRATEGY_WORK_HOURS['start']['h'],
            static::FORCE_STRATEGY_WORK_HOURS['start']['m'],
            static::FORCE_STRATEGY_WORK_HOURS['end']['h'],
            static::FORCE_STRATEGY_WORK_HOURS['end']['m']
        );

        return [$economy_buy, $force_buy];
    }
}

/**
 * @param $format
 * @param ...$args
 *
 * @return string
 */
function mb_sprintf($format, ...$args): string
{
    $params = $args;

    $callback = function ($length) use (&$params) {
        $value = array_shift($params);
        return strlen($value) - mb_strlen($value) + $length[0];
    };

    $format = preg_replace_callback('/(?<=%|%-)\d+(?=s)/', $callback, $format);

    return sprintf($format, ...$args);
}
