<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\models\TelegramBot;
use app\models\TIAccount;
use app\models\TIServices;
use Exception;
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
use Tinkoff\Invest\V1\Quotation;
use Yii;

/**
 * Консольный контроллер, обслуживающий стратегию автоматической ребалансировки и управления портфелем
 *
 * @package app\commands
 */
class RebalanceController extends BaseController
{
    use ProgressTrait;

    /**
     * Итерация попытки выставления заявки на покупку облигаций
     *
     * Метод вычисляет, хватает ли средств и если есть возможность - выставляет/обновляет заявки на покупку
     *
     * @param string $strategy_alies Алиас стратегии
     *
     * @throws Exception
     */
    public function actionAutoBuyBonds(string $strategy_alies): void
    {
        static::stdoutStart(__FUNCTION__, static::STRATEGY_MANAGE_LOG_TARGET);

        try {
            $all_manage_strategies = Yii::$app->params['strategies']['manage'] ?? [];
            $selected_strategy = $all_manage_strategies[$strategy_alies] ?? [];
            $buy_settings = $selected_strategy['bonds'] ?? [];

            if (empty($buy_settings)) {
                echo 'No bonds tasks' . PHP_EOL;

                throw new Exception('No bonds tasks');
            }

            $economy_buy = static::isValidTradingPeriod(15, 30, 22, 25);
            $force_buy = static::isValidTradingPeriod(22, 26, 22, 46);

            if (!$economy_buy && !$force_buy) {
                echo 'Bad period to buy' . PHP_EOL;

                throw new Exception('Bad period to buy');
            }

            $account = $selected_strategy['account'] ?? null;

            if (!$account) {
                echo 'Account is empty' . PHP_EOL;

                throw new Exception('Bad period to buy');
            }

            $account = TIAccount::create($account);
            $tinkoff_api = TIServices::apiClient($account->profile);
            $instruments = TIServices::instruments($account->profile);

            $money_etf_instrument = null;

            if ($money_etf_instrument_ticker = $selected_strategy['parking_money_etf'] ?? null) {
                $money_etf_instrument = $instruments->etfByTicker($money_etf_instrument_ticker);
            }

            $base_tasks_to_buy_bonds = static::prepareTaskInstruments($account, $buy_settings);

            if (!$base_tasks_to_buy_bonds) {
                echo 'Tasks list is empty' . PHP_EOL;

                throw new Exception('Tasks list is empty');
            }

            $tasks_related_orders = static::findTasksRelatedOrders($account, $base_tasks_to_buy_bonds);

            list($portfolio_money, $etf_money, $single_etf_price, $money_etf_quantity) = static::portfolioMoneyTotal($account->accountId, 'rub', $money_etf_instrument);
            $available_money = $portfolio_money + $etf_money;

            echo 'Total money:' . $available_money . PHP_EOL;

            $tasks_to_buy_bonds = static::prepareTaskPrices($account, $base_tasks_to_buy_bonds, $available_money, $portfolio_money);

            if (empty($tasks_to_buy_bonds) && !empty($tasks_related_orders)) {
                echo 'We should cancel some orders';

                shuffle($tasks_related_orders);

                $order_to_cancel = reset($tasks_related_orders);

                echo 'Cancel order: ' . ($order_to_cancel['ticker'] ?? 'Unknown') . '(' . $order_to_cancel['order_id'] ?? 'Unknown' . ')' . PHP_EOL;

                $cancel_order_request = new CancelOrderRequest();
                $cancel_order_request->setAccountId($account->accountId);
                $cancel_order_request->setOrderId($order_to_cancel['order_id']);

                /** @var GetOrdersResponse $response */
                list($response, $status) = $tinkoff_api->ordersServiceClient->CancelOrder($cancel_order_request)->wait();
                static::processRequestStatus($status, true);

                sleep(15);

                echo 'Order cancelled' . PHP_EOL;

                list($portfolio_money, $etf_money, $single_etf_price, $money_etf_quantity) = static::portfolioMoneyTotal($account->accountId, 'rub', $money_etf_instrument);
                $available_money = $portfolio_money + $etf_money;

                echo 'Total money:' . $available_money . PHP_EOL;

                $tasks_to_buy_bonds = static::prepareTaskPrices($account, $base_tasks_to_buy_bonds, $available_money, $portfolio_money);
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
                echo $ticker . ' -> ' . $task['limit'] . ' ' . ($task['prior'] ? 'Prior!' : '') . PHP_EOL;

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

                throw new Exception('Zero can buy');
            }

            $need_money_from_etf = ($current_buy_price_decimal * (1 + $commission) + $nkd_decimal) * $we_can_buy - $portfolio_money;
            $need_wait = false;

            if ($need_money_from_etf > 0 && $single_etf_price) {
                $need_sell_etf_lots = (int) ceil(1.02 * (1 + $commission) * $need_money_from_etf / $single_etf_price);

                echo 'We need to sell ' . $need_sell_etf_lots . ' LQDT lots' . PHP_EOL;

                if ($need_sell_etf_lots > $money_etf_quantity) {
                    echo 'There are not that many items for sale' . PHP_EOL;

                    throw new Exception('There are not that many ETF items for sale');
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

                throw new Exception('Impossible to buy');
            }

            echo 'Покупка доступна' . PHP_EOL;

            $top_prices = $marketdata->getOrderbookTopPrices($target_instrument);

            $top_ask_price = $top_prices['ask'] ?? null;
            $top_bid_price = $top_prices['bid'] ?? null;

            if (!$top_ask_price) {
                echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

                throw new Exception('Impossible to buy');
            }

            $current_buy_price = $top_ask_price;

            if ($price === null) {
                $current_buy_price_decimal = QuotationHelper::toCurrency($current_buy_price, $target_instrument);

                echo 'Целевая цена из стакана: ' . $current_buy_price_decimal . PHP_EOL;
            } else {
                $current_buy_price_decimal = $price;

                if (!QuotationHelper::isPriceValid($current_buy_price_decimal, $target_instrument)) {
                    echo 'Цена ' . $price . ' для инструмента не валидна, не подходящий шаг цены' . PHP_EOL;

                    throw new Exception('Buy order error');
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

                    throw new Exception('Buy order error');
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
    protected static function prepareTaskInstruments(TIAccount $account, array $buy_settings): array
    {
        $tinkoff_instruments = TIServices::instruments($account->profile);

        echo 'Base task to buy bonds: ' . Log::logSerialize($buy_settings) . PHP_EOL;

        $tasks_to_buy_bonds = [];

        foreach ($buy_settings as $ticker => $limit) {
            $instrument = $tinkoff_instruments->bondByTicker($ticker);

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
    protected static function prepareTaskPrices(TIAccount $account, array $tasks_to_buy_bonds, float $available_total_money = null, float $available_portfolio_money = null): array
    {
        $portfolio = TIServices::portfolio($account->profile);

        foreach ($tasks_to_buy_bonds as $ticker => $task) {
            /** @var Bond $task_instrument */
            $task_instrument = $task['instrument'];

            if (Yii::$app->cache->get('BOND_BUY_ENOUGH_' . $account->accountId . '_' . $task_instrument->getFigi())) {
                echo 'PRICE CHECK ' . $ticker . ' -> Cache buy enough';

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

                            TelegramBot::notifyTelegram($account->accountId, 'Задача по покупке облигаций [[' . $task_instrument->getTicker() . ']] завершена. Куплены ' . $quantity . ' шт.');
                        } else {
                            $tasks_to_buy_bonds[$ticker]['limit'] -= $quantity;
                        }

                        $position_price = QuotationHelper::toDecimal($position->getCurrentPrice()) * (1 + $commission);

//                        $nkd = $task_instrument->getAciValue();
//
//                        $nkd_decimal = 0;
//
//                        if ($nkd) {
//                            $nkd_decimal = QuotationHelper::toDecimal($nkd);
//                        }

                        if ($available_total_money && $position_price > $available_total_money) {
                            unset($tasks_to_buy_bonds[$ticker]);

                            echo 'Excluded, In portfolio, no money for (need ' . $position_price . ')' . PHP_EOL;
                        } elseif ($available_portfolio_money && $position_price <= $available_portfolio_money) {
                            $tasks_to_buy_bonds[$ticker]['prior'] = true;

                            echo 'Prior, In portfolio' . PHP_EOL;
                        }
                    }
                } catch (Throwable $e) {
                    unset($tasks_to_buy_bonds[$ticker]);

                    echo 'Exception: ' . $e->getMessage() . PHP_EOL;
                }
            }

            if ($not_found_in_portfolio) {
                $instrument_price = static::bondOrderbookPrice($account, $task['instrument']);

                if (!$instrument_price) {
                    unset($tasks_to_buy_bonds[$ticker]);

                    continue;
                }

                $position_price = QuotationHelper::toCurrency($instrument_price, $task['instrument']) * (1 + $commission);

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
     * @param Bond $target_instrument Целевой инструмент
     * @param bool $force Флаг форсированной покупки
     *
     * @return Quotation|null Вычисленная цена или <code>null</code>, если покупка не возможна
     *
     * @throws ValidateException
     * @throws Exception|Throwable
     */
    protected static function bondOrderbookPrice(TIAccount $account, Bond $target_instrument, bool $force = false): ?Quotation
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
}
