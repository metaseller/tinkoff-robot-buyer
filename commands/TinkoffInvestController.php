<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\helpers\ArrayHelper;
use app\helpers\DateTimeHelper;
use app\helpers\NumbersHelper;
use app\models\TIServices;
use DateInterval;
use DatePeriod;
use DateTime;
use DateTimeZone;
use Exception;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Timestamp;
use Metaseller\TinkoffInvestApi2\dto\Price;
use Metaseller\TinkoffInvestApi2\exceptions\InstrumentNotFoundException;
use Metaseller\TinkoffInvestApi2\exceptions\ValidateException;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use Metaseller\TinkoffInvestApi2\providers\InstrumentsProvider;
use SonkoDmitry\Yii\TelegramBot\Component as TelegramBotApi;
use stdClass;
use Throwable;
use Tinkoff\Invest\V1\Account;
use Tinkoff\Invest\V1\Bond;
use Tinkoff\Invest\V1\CancelOrderRequest;
use Tinkoff\Invest\V1\CandleInstrument;
use Tinkoff\Invest\V1\Etf;
use Tinkoff\Invest\V1\EtfsResponse;
use Tinkoff\Invest\V1\GetAccountsRequest;
use Tinkoff\Invest\V1\GetAccountsResponse;
use app\components\console\Controller;
use Tinkoff\Invest\V1\GetInfoRequest;
use Tinkoff\Invest\V1\GetInfoResponse;
use Tinkoff\Invest\V1\GetOrderBookRequest;
use Tinkoff\Invest\V1\GetOrderBookResponse;
use Tinkoff\Invest\V1\GetOrdersRequest;
use Tinkoff\Invest\V1\GetOrdersResponse;
use Tinkoff\Invest\V1\InstrumentsRequest;
use Tinkoff\Invest\V1\InstrumentStatus;
use Tinkoff\Invest\V1\InstrumentType;
use Tinkoff\Invest\V1\LastPriceInstrument;
use Tinkoff\Invest\V1\MarketDataRequest;
use Tinkoff\Invest\V1\MarketDataResponse;
use Tinkoff\Invest\V1\MoneyValue;
use Tinkoff\Invest\V1\Operation;
use Tinkoff\Invest\V1\OperationsRequest;
use Tinkoff\Invest\V1\OperationsResponse;
use Tinkoff\Invest\V1\OperationState;
use Tinkoff\Invest\V1\OperationType;
use Tinkoff\Invest\V1\Order;
use Tinkoff\Invest\V1\OrderDirection;
use Tinkoff\Invest\V1\OrderState;
use Tinkoff\Invest\V1\OrderType;
use Tinkoff\Invest\V1\PortfolioPosition;
use Tinkoff\Invest\V1\PortfolioRequest;
use Tinkoff\Invest\V1\PortfolioResponse;
use Tinkoff\Invest\V1\PositionsMoney;
use Tinkoff\Invest\V1\PositionsRequest;
use Tinkoff\Invest\V1\PositionsResponse;
use Tinkoff\Invest\V1\PostOrderRequest;
use Tinkoff\Invest\V1\PostOrderResponse;
use Tinkoff\Invest\V1\Quotation;
use Tinkoff\Invest\V1\SecurityTradingStatus;
use Tinkoff\Invest\V1\SubscribeCandlesRequest;
use Tinkoff\Invest\V1\SubscribeLastPriceRequest;
use Tinkoff\Invest\V1\SubscribeOrderBookRequest;
use Tinkoff\Invest\V1\SubscriptionAction;
use Tinkoff\Invest\V1\SubscriptionInterval;
use Yii;

/**
 * Консольный контроллер, предназначенный для демонстрации работы с Tinkoff Invest Api и реализации базовой стратегии робота-покупателя ETF
 *
 * @package app\commands
 */
class TinkoffInvestController extends BaseController
{
    use ProgressTrait;







    /**
     * Метод проверяет выполнение условий трейлинг-стратегии покупки и выставляет ордер на покупку по лучшей доступной цене
     * в стакане
     *
     * @param string $account_id Идентификатор аккаунта, для которого работает стратегия
     * @param string $ticker Тикер ETF инструмента
     * @param int $buy_step Минимальный накопленный лимит лотов, после достижения которого выставляется заявка на покупку
     * @param float $trailing_sensitivity Величина в процентах, на которую текущая цена должна превысить трейлинг цену для
     * совершения покупки. По умолчанию равно <code>0</code>
     *
     * @return void
     * @throws Exception
     */
    public function actionBuyEtfTrailing(string $account_id, string $ticker, int $buy_step, float $trailing_sensitivity = 0): void
    {
        Log::info('Start action ' . __FUNCTION__, static::STRATEGY_ETF_LOG_TARGET);

        ob_start();

        if (
            !$this->isValidTradingPeriod(14, 0, 23, 59) &&
            !$this->isValidTradingPeriod(0, 0, 3, 45)
        ) {
            return;
        }

        try {
            $tinkoff_api = TIServices::clientByAccount($account_id);
            $tinkoff_instruments = TIServices::instrumentsForAccount($account_id);

            echo 'Ищем ETF инструмент' . PHP_EOL;

            $target_instrument = $tinkoff_instruments->etfByTicker($ticker);

            echo 'Инструмент найден' . PHP_EOL;

            if ($target_instrument->getBuyAvailableFlag() === false) {
                echo 'Покупка не доступна' . PHP_EOL;

                return;
            } else {
                echo 'Покупка доступна' . PHP_EOL;
            }

            $trading_status = $target_instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING) {
                echo 'Не подходящий Trading Status: ' . SecurityTradingStatus::name($trading_status) . PHP_EOL;

                return;
            }

            echo 'Получаем стакан' . PHP_EOL;

            $orderbook_request = new GetOrderBookRequest();
            $orderbook_request->setDepth(1);
            $orderbook_request->setInstrumentId($target_instrument->getFigi());

            /** @var GetOrderBookResponse $response */
            list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
            static::processRequestStatus($status);

            if (!$response) {
                echo 'Ошибка получения стакана заявок' . PHP_EOL;

                return;
            }

            /** @var RepeatedField|Order[] $asks */
            $asks = $response->getAsks();

            if ($asks->count() === 0) {
                echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

                return;
            }

            $top_ask_price = $asks[0]->getPrice();

            $bids = $response->getBids();
            $top_bid_price = $bids[0]->getPrice();

            $current_price = $top_ask_price;
            $current_price_decimal = QuotationHelper::toDecimal($current_price);

            $current_bid_decimal = $top_bid_price ? QuotationHelper::toDecimal($top_bid_price) : '-';

            $cache_trailing_count_key = $account_id . '@etf@' . $ticker . '_count';
            $cache_trailing_events_key = $account_id . '@etf@' . $ticker . '_events';
            $cache_trailing_price_key = $account_id . '@etf@' . $ticker . '_price';

            $cache_trailing_count_value = Yii::$app->cache->get($cache_trailing_count_key) ?: 0;
            $cache_trailing_price_value = Yii::$app->cache->get($cache_trailing_price_key) ?: $current_price_decimal;
            $cache_trailing_events_value = Yii::$app->cache->get($cache_trailing_events_key) ?: 0;

            $buy_step_reached = ($cache_trailing_count_value >= $buy_step);

            $place_order = false;

            $sensitivity_price = $cache_trailing_price_value * (1 + $trailing_sensitivity / 100);

            if ($buy_step_reached) {
                if ($current_price_decimal >= $sensitivity_price) {
                    $cache_trailing_events_value++;

                    if ($cache_trailing_events_value >= 3) {
                        $place_order = true;
                        $cache_trailing_events_value = 0;
                    }
                } else {
                    $cache_trailing_events_value = 0;
                }
            } else {
                $cache_trailing_events_value = 0;
            }

            Yii::$app->cache->set($cache_trailing_events_key, $cache_trailing_events_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);

            if (!$place_order) {
                /** Не переносим накопленные остатки на следующий день */
                $final_day_action = ($cache_trailing_count_value > ($buy_step / 2)) && $this->isValidTradingPeriod(3, 40, 3, 44);

                if ($final_day_action) {
                    echo 'Форсируем покупку для завершения торгового дня на объем ' . $cache_trailing_count_value . ' лотов' . PHP_EOL;

                    $place_order = true;
                }
            }

            echo 'Данные к расчету: ' . Log::logSerialize([
                    'current_price' => '[' . $current_bid_decimal . ' - ' . $current_price_decimal . ']',
                    'cache_trailing_price_value' => $cache_trailing_price_value,
                    'sensitivity_price' => $sensitivity_price,

                    'cache_trailing_count_value' => $cache_trailing_count_value,
                    'buy_step' => $buy_step,
                    'sensitivity' => $trailing_sensitivity . '%',

                    'buy_step_reached' => $buy_step_reached,
                    'final_day_action' => $final_day_action ?? false,
                    'place_order_action' => $place_order,
                    'stability' => $cache_trailing_events_value,
                ]) . PHP_EOL
            ;

            if ($place_order) {
                echo 'Событие покупки. Попытаемся купить ' . $cache_trailing_count_value . ' лотов' . PHP_EOL;

                $post_order_request = new PostOrderRequest();
                $post_order_request->setInstrumentId($target_instrument->getFigi());
                $post_order_request->setQuantity($cache_trailing_count_value);
                $post_order_request->setPrice($current_price);
                $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_BUY);
                $post_order_request->setAccountId($account_id);
                $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

                $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

                $post_order_request->setOrderId($order_id);

                /** @var PostOrderResponse $response */
                list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
                static::processRequestStatus($status);

                if (!$response) {
                    echo 'Ошибка отправки торговой заявки' . PHP_EOL;

                    return;
                }

                echo 'Заявка с идентификатором ' . $response->getOrderId() . ' отправлена' . PHP_EOL;

                Yii::$app->cache->set($cache_trailing_count_key, 0, 6 * DateTimeHelper::SECONDS_IN_HOUR);

                $cache_trailing_count_value = 0;
                $cache_trailing_price_value = $current_price_decimal;
            } else {
                if ($buy_step_reached) {
                    echo 'Событие покупки не наступило, цена не достигнута' . PHP_EOL;

                    $cache_trailing_price_value = min($cache_trailing_price_value, $current_price_decimal);
                } else {
                    echo 'Событие покупки не наступило, мало накоплено' . PHP_EOL;

                    $cache_trailing_price_value = $current_price_decimal;
                }
            }

            echo 'Помещаем в кэш: ' . Log::logSerialize([
                    'cache_trailing_count_value' => $cache_trailing_count_value,
                    'cache_trailing_price_value' => $cache_trailing_price_value,
                ]) . PHP_EOL
            ;

            Yii::$app->cache->set($cache_trailing_price_key, $cache_trailing_price_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);

            $this->storeCurrentPrice($account_id, $ticker, $current_price_decimal);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::STRATEGY_ETF_LOG_TARGET);
        }

        $stdout_data = ob_get_contents();
        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, static::STRATEGY_ETF_LOG_TARGET);

            echo $stdout_data;
        }
    }


    /**
     * @param string $account_id
     * @param array $buy_settings
     * @return array
     * @throws InstrumentNotFoundException
     * @throws ValidateException
     */
    protected static function autoBondsBuyPrepareInstruments(string $account_id, array $buy_settings): array
    {
        $tinkoff_instruments = TIServices::instrumentsForAccount($account_id);

        echo 'Base task to buy bonds: ' . Log::logSerialize($buy_settings) . PHP_EOL;

        $tasks_to_buy_bonds = [];

        foreach ($buy_settings as $ticker => $limit) {
            $instrument = $tinkoff_instruments->bondByTicker($ticker);

            if (!$instrument) {
                echo 'INSTRUMENTS CHECK ' . $ticker . ' -> Not found' . PHP_EOL;

                continue;
            }

            if ($instrument->getBuyAvailableFlag() === false) {
                echo 'INSTRUMENTS CHECK ' . $ticker . ' -> Buy not available' . PHP_EOL;

                var_dump($instrument->serializeToJsonString());

                continue;
            }

            if ($instrument->getApiTradeAvailableFlag() === false) {
                echo 'INSTRUMENTS CHECK ' . $ticker . ' -> Api trade not available' . PHP_EOL;

                var_dump($instrument->serializeToJsonString());

                continue;
            }

            $trading_status = $instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING) {
                echo 'INSTRUMENTS CHECK ' . $ticker . ' -> Trading status not normal' . PHP_EOL;

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
     * @param string $account_id
     * @param array $tasks_to_buy_bonds
     *
     * @return array
     *
     * @throws ValidateException
     * @throws Exception
     */
    protected static function autoBondsBuyGetOrders(string $account_id, array $tasks_to_buy_bonds): array
    {
        $tinkoff_api = TIServices::clientByAccount($account_id);

        $tasks_related_orders = [];

        echo 'Lets check current orders!' . PHP_EOL;

        $order_request = new GetOrdersRequest();
        $order_request->setAccountId($account_id);

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
     * @param $account_id
     * @param array $tasks_to_buy_bonds
     * @param float|null $available_total_money
     * @param float|null $available_portfolio_money
     *
     * @return array
     *
     * @throws ValidateException
     * @throws Exception
     */
    protected function autoBondsBuyCheckPrices($account_id, array $tasks_to_buy_bonds, float $available_total_money = null, float $available_portfolio_money = null): array
    {
        $tinkoff_api = TIServices::clientByAccount($account_id);
        $tinkoff_instruments = TIServices::instrumentsForAccount($account_id);

        foreach ($tasks_to_buy_bonds as $ticker => $task) {
            /** @var Bond $task_instrument */
            $task_instrument = $task['instrument'];

            if (Yii::$app->cache->get('BOND_BUY_ENOUGH_' . $account_id . '_' . $task_instrument->getFigi())) {
                echo 'PRICE CHECK ' . $ticker . ' -> Cache buy enough';

                unset($tasks_to_buy_bonds[$ticker]);
            }
        }

        if (empty($tasks_to_buy_bonds)) {
            return [];
        }

        $client = $tinkoff_api->operationsServiceClient;

        $request = new PortfolioRequest();
        $request->setAccountId($account_id);

        /**
         * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
         */
        list($response, $status) = $client->GetPortfolio($request)->wait();
        static::processRequestStatus($status, true);

        $commission = static::COMMISSION;

        /** @var PortfolioPosition $portfolio_positions[] */
        $portfolio_positions = ArrayHelper::repeatedFieldToArray($response->getPositions());

        foreach ($tasks_to_buy_bonds as $ticker => $task) {
            echo 'PRICE CHECK ' . $ticker . ' -> ';

            $not_found_in_portfolio = true;

            foreach ($portfolio_positions as $position) {
                try {
                    /** @var Bond $task_instrument */
                    $task_instrument = $task['instrument'];

                    if ($task_instrument->getFigi() === $position->getFigi()) {
                        $not_found_in_portfolio = false;

                        $quantity = (int) QuotationHelper::toDecimal($position->getQuantity());

                        if ($quantity >= $task['limit']) {
                            unset($tasks_to_buy_bonds[$ticker]);

                            Yii::$app->cache->set('BOND_BUY_ENOUGH_' . $account_id . '_' . $position->getFigi(), 1, 12 * 60 * 60);

                            static::notifyTelegram($account_id, 'Задача по покупке облигаций [[' . $task_instrument->getTicker() . ']] завершена. Куплены ' . $quantity . ' шт.');
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
                $instrument_price = $this->bondOrderbookPrice($account_id, $task['instrument']);

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
     * @param string $account_id
     * @return array
     * @throws ValidateException
     */
    protected static function portfolioTotalAvailableMoney(string $account_id): array
    {
        echo 'Calculate portfolio money' . PHP_EOL;

        list($portfolio_currency, $portfolio_currency_decimal) = static::portfolioMoney($account_id);
        $portfolio_money_etf = static::portfolioMoneyETF($account_id);

        $commission = static::COMMISSION;

        $portfolio_money = $portfolio_currency_decimal['rub']['available'] ?? 0;
        $etf_money = 0;
        $single_etf_price = 0;

        $lqdt_quantity = 0;

        if (!empty($portfolio_money_etf['LQDT'])) {
            $lqdt_quantity = $portfolio_money_etf['LQDT']['quantity'] ?? 0;

            if ($lqdt_quantity > 0) {
                $single_etf_price = $portfolio_money_etf['LQDT']['price'] * (1 - $commission) / $lqdt_quantity;
                $etf_money += $portfolio_money_etf['LQDT']['price'] * (1 - $commission);
            }
        }

        echo 'Portfolio money:' . $portfolio_money . PHP_EOL;
        echo 'Etf money parked:' . $etf_money . PHP_EOL;

        return [$portfolio_money, $etf_money, $single_etf_price, $lqdt_quantity];
    }

    /**
     * @param string $account_id
     * @return void
     * @throws Exception
     */
    public function actionAutoBuyBonds(string $account_id): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        ob_start();

        $buy_settings = Yii::$app->params['auto_buy_bonds'] ?? [];

        if (empty($buy_settings)) {
            echo 'No bonds tasks' . PHP_EOL;

            $stdout_data = ob_get_contents();
            ob_end_clean();

            if ($stdout_data) {
                Log::info($stdout_data, static::STRATEGY_MANAGE_LOG_TARGET);

                echo $stdout_data;
            }

            return;
        }

        $economy_buy = $this->isValidTradingPeriod(15, 30, 22, 25);
        $force_buy = $this->isValidTradingPeriod(22, 26, 22, 46);

        if (!$economy_buy && !$force_buy) {
            echo 'Bad period to buy' . PHP_EOL;

            $stdout_data = ob_get_contents();
            ob_end_clean();

            if ($stdout_data) {
                Log::info($stdout_data, static::STRATEGY_MANAGE_LOG_TARGET);

                echo $stdout_data;
            }

            return;
        }

        try {
            $tinkoff_api = TIServices::clientByAccount($account_id);

            $base_tasks_to_buy_bonds = static::autoBondsBuyPrepareInstruments($account_id, $buy_settings);

            if (!$base_tasks_to_buy_bonds) {
                echo 'Tasks list is empty' . PHP_EOL;

                $stdout_data = ob_get_contents();
                ob_end_clean();

                if ($stdout_data) {
                    Log::info($stdout_data, static::STRATEGY_MANAGE_LOG_TARGET);

                    echo $stdout_data;
                }

                return;
            }

            $tasks_related_orders = static::autoBondsBuyGetOrders($account_id, $base_tasks_to_buy_bonds);

            list($portfolio_money, $etf_money, $single_etf_price, $lqdt_quantity) = static::portfolioTotalAvailableMoney($account_id);
            $available_money = $portfolio_money + $etf_money;

            echo 'Total money:' . $available_money . PHP_EOL;

            $tasks_to_buy_bonds = static::autoBondsBuyCheckPrices($account_id, $base_tasks_to_buy_bonds, $available_money, $portfolio_money);

            if (empty($tasks_to_buy_bonds) && !empty($tasks_related_orders)) {
                echo 'We should cancel some orders';

                shuffle($tasks_related_orders);

                $order_to_cancel = reset($tasks_related_orders);

                echo 'Cancel order: ' . ($order_to_cancel['ticker'] ?? 'Unknown') . '(' . $order_to_cancel['order_id'] ?? 'Unknown' . ')' . PHP_EOL;

                $cancel_order_request = new CancelOrderRequest();
                $cancel_order_request->setAccountId($account_id);
                $cancel_order_request->setOrderId($order_to_cancel['order_id']);

                /** @var GetOrdersResponse $response */
                list($response, $status) = $tinkoff_api->ordersServiceClient->CancelOrder($cancel_order_request)->wait();
                static::processRequestStatus($status, true);

                sleep(15);

                echo 'Order cancelled' . PHP_EOL;

                list($portfolio_money, $etf_money, $single_etf_price, $lqdt_quantity) = static::portfolioTotalAvailableMoney($account_id);
                $available_money = $portfolio_money + $etf_money;

                echo 'Total money:' . $available_money . PHP_EOL;

                $tasks_to_buy_bonds = static::autoBondsBuyCheckPrices($account_id, $base_tasks_to_buy_bonds, $available_money, $portfolio_money);
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

            $comission = static::COMMISSION;

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

            $instrument_top_prices = TIServices::marketdataForAccount($account_id)->getOrderbookTopPrices($target_instrument, false);

            if (!$instrument_top_prices) {
                echo 'Error on getting instrument price' . PHP_EOL;

                throw new Exception('Impossible to buy');
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

            $we_can_buy = (int) floor($available_money / ($current_buy_price_decimal * (1 + $comission) + $nkd_decimal));

            echo 'We can buy ' . $we_can_buy . ' lots' . PHP_EOL;

            $we_can_buy = min($we_can_buy, $target_limit);

            echo 'But we should buy ' . $we_can_buy . ' lots' . PHP_EOL;

            if ($we_can_buy <= 0) {
                echo 'Zero can buy' . PHP_EOL;

                $stdout_data = ob_get_contents();
                ob_end_clean();

                if ($stdout_data) {
                    Log::info($stdout_data, static::STRATEGY_MANAGE_LOG_TARGET);

                    echo $stdout_data;
                }

                return;
            }

            $need_money_from_etf = ($current_buy_price_decimal * (1 + $comission) + $nkd_decimal) * $we_can_buy - $portfolio_money;
            $need_wait = false;

            if ($need_money_from_etf > 0 && $single_etf_price) {
                $need_sell_etf_lots = (int) ceil(1.02 * (1 + $comission) * $need_money_from_etf / $single_etf_price);

                echo 'We need to sell ' . $need_sell_etf_lots . ' LQDT lots' . PHP_EOL;

                if ($need_sell_etf_lots > $lqdt_quantity) {
                    echo 'There are not that many items for sale' . PHP_EOL;

                    $stdout_data = ob_get_contents();
                    ob_end_clean();

                    if ($stdout_data) {
                        Log::info($stdout_data, static::STRATEGY_MANAGE_LOG_TARGET);

                        echo $stdout_data;
                    }

                    return;
                }

                if ($need_sell_etf_lots > 0) {
                    $this->actionSell($account_id, 'etf', 'LQDT', $need_sell_etf_lots);

                    $need_wait = true;
                }
            } else {
                echo 'Money is enough' . PHP_EOL;
            }

            if ($need_wait) {
                sleep(15);
            }

            $this->actionBuy($account_id, 'bond', $target_instrument->getTicker(), $we_can_buy, $force_buy ? null : $current_buy_price_decimal);

            static::notifyTelegram($account_id, 'Инициирована покупка облигации [[' . $target_instrument->getTicker() . ']] ' . static::escapeMarkdown($target_instrument->getName()) . ' по цене ' . ($force_buy ? 'лучшей' : $current_buy_price_decimal));
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::MAIN_LOG_TARGET);
        }

        $stdout_data = ob_get_contents();
        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, static::STRATEGY_MANAGE_LOG_TARGET);

            echo $stdout_data;
        }
    }

    /**
     * @param string $account_id
     * @param string $type
     * @param string $ticker
     * @param int|null $lots
     * @param float|null $price
     * @return void
     */
    public function actionParkFreeMoney(string $account_id, string $type, string $ticker, int $lots = null, float $price = null): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        try {
            $tinkoff_api = TIServices::clientByAccount($account_id);
            $tinkoff_instruments = TIServices::instrumentsForAccount($account_id);

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

            if ($target_instrument->getBuyAvailableFlag() === false) {
                echo 'Покупка не доступна' . PHP_EOL;

                throw new Exception('Impossible to buy');
            } else {
                echo 'Покупка доступна' . PHP_EOL;
            }

            $trading_status = $target_instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING) {
                echo 'Не подходящий Trading Status: ' . SecurityTradingStatus::name($trading_status) . PHP_EOL;

                throw new Exception('Impossible to buy');
            }

            $orderbook_request = new GetOrderBookRequest();
            $orderbook_request->setDepth(1);
            $orderbook_request->setInstrumentId($target_instrument->getFigi());

            /** @var GetOrderBookResponse $response */
            list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
            static::processRequestStatus($status, true);

            if (!$response) {
                echo 'Ошибка получения стакана заявок' . PHP_EOL;

                throw new Exception('Impossible to buy');
            }

            /** @var RepeatedField|Order[] $asks */
            $asks = $response->getAsks();

            /** @var RepeatedField|Order[] $bids */
            $bids = $response->getBids();

            if ($asks->count() === 0 || $bids->count() === 0) {
                echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

                throw new Exception('Impossible to buy');
            }

            $top_ask_price = $asks[0]->getPrice();
            $top_bid_price = $bids[0]->getPrice();

            $current_buy_price = $top_ask_price;

            if ($price === null) {
                $current_buy_price_decimal = QuotationHelper::toCurrency($current_buy_price, $target_instrument);

                echo 'Целевая цена из стакана: ' . $current_buy_price_decimal . PHP_EOL;
            } else {
                $current_buy_price_decimal = $price;

                if (!QuotationHelper::isPriceValid($current_buy_price_decimal, $target_instrument)) {
                    echo 'Цена ' . $price. ' для инструмента не валидна, не подходящий шаг цены' . PHP_EOL;

                    throw new Exception('Buy order error');
                }

                echo 'Целевая цена: ' . $current_buy_price_decimal . PHP_EOL;
            }

            /** @var Quotation $current_buy_price */
            $current_buy_price = QuotationHelper::toQuotation($current_buy_price_decimal);

            echo 'Сконвертированная цена: ' . $current_buy_price->serializeToJsonString() . PHP_EOL;

            list($portfolio_currency, $portfolio_currency_decimal) = $this->portfolioMoney($account_id);
            $currency_decimal = $portfolio_currency_decimal[$target_instrument->getCurrency()]['available'] ?? 0;

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
                $post_order_request = new PostOrderRequest();
                $post_order_request->setInstrumentId($target_instrument->getFigi());
                $post_order_request->setQuantity($optimal_buy_lots);
                $post_order_request->setPrice($current_buy_price);
                $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_BUY);
                $post_order_request->setAccountId($account_id);
                $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

                $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

                $post_order_request->setOrderId($order_id);

                /** @var PostOrderResponse $response */
                list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
                static::processRequestStatus($status, true);

                if (!$response) {
                    echo 'Ошибка отправки торговой заявки' . PHP_EOL;

                    throw new Exception('Buy order error');
                }

                echo 'Заявка с идентификатором ' . $response->getOrderId() . ' отправлена' . PHP_EOL;
            }
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::MAIN_LOG_TARGET);
        }
    }

    /**
     * @param string $account_id
     * @param string $type
     * @param string $ticker
     * @param int $lots
     * @param float|null $price
     * @return void
     */
    public function actionBuy(string $account_id, string $type, string $ticker, int $lots = 1, float $price = null): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        try {
            echo 'Запрос покупки  ' . $type . ' ' . $ticker . ' на счет ' . $account_id . PHP_EOL;

            $tinkoff_api = TIServices::clientByAccount($account_id);
            $tinkoff_instruments = TIServices::instrumentsForAccount($account_id);

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

            if ($target_instrument->getBuyAvailableFlag() === false) {
                echo 'Покупка не доступна' . PHP_EOL;

                throw new Exception('Impossible to buy');
            } else {
                echo 'Покупка доступна' . PHP_EOL;
            }

            $trading_status = $target_instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING) {
                echo 'Не подходящий Trading Status: ' . SecurityTradingStatus::name($trading_status) . PHP_EOL;

                throw new Exception('Impossible to buy');
            }

            echo 'Получаем стакан' . PHP_EOL;

            $orderbook_request = new GetOrderBookRequest();
            $orderbook_request->setDepth(1);
            $orderbook_request->setInstrumentId($target_instrument->getFigi());

            /** @var GetOrderBookResponse $response */
            list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
            static::processRequestStatus($status, true);

            if (!$response) {
                echo 'Ошибка получения стакана заявок' . PHP_EOL;

                throw new Exception('Impossible to buy');
            }

            /** @var RepeatedField|Order[] $asks */
            $asks = $response->getAsks();

            /** @var RepeatedField|Order[] $bids */
            $bids = $response->getBids();

            if ($asks->count() === 0 || $bids->count() === 0) {
                echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

                throw new Exception('Impossible to buy');
            }

            $top_ask_price = $asks[0]->getPrice();
            $top_bid_price = $bids[0]->getPrice();

            $current_buy_price = $top_ask_price;

            if ($price === null) {
                $current_buy_price_decimal = QuotationHelper::toCurrency($current_buy_price, $target_instrument);

                echo 'Целевая цена из стакана: ' . $current_buy_price_decimal . PHP_EOL;
            } else {
                $current_buy_price_decimal = $price;

                if (!QuotationHelper::isPriceValid($current_buy_price_decimal, $target_instrument)) {
                    echo 'Цена ' . $current_buy_price_decimal . ' для инструмента не валидна, не подходящий шаг цены' . PHP_EOL;

                    $min_price_increment = $target_instrument->getMinPriceIncrement();
                    $precision = pow(10,9);

                    var_dump(
                        $precision * QuotationHelper::toDecimal($current_buy_price_decimal),
                        $precision * QuotationHelper::toDecimal($min_price_increment),
                        ($precision * QuotationHelper::toDecimal($price)) % ($precision * QuotationHelper::toDecimal($min_price_increment)),
                        ($precision * QuotationHelper::toDecimal($price)) % ($precision * QuotationHelper::toDecimal($min_price_increment)) == 0
                    );

                    throw new Exception('Buy order error');
                }

                echo 'Целевая цена: ' . $current_buy_price_decimal . PHP_EOL;
            }

            /** @var Quotation $current_buy_price */
            $current_buy_price = QuotationHelper::toQuotation($current_buy_price_decimal);

            echo 'Сконвертированная цена: ' . $current_buy_price->serializeToJsonString() . PHP_EOL;
            echo 'Попытаемся купить ' . $lots . ' лотов по цене (' . $current_buy_price_decimal . ')' . PHP_EOL;

            $post_order_request = new PostOrderRequest();
            $post_order_request->setInstrumentId($target_instrument->getFigi());
            $post_order_request->setQuantity($lots);
            $post_order_request->setPrice($current_buy_price);
            $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_BUY);
            $post_order_request->setAccountId($account_id);
            $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

            $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

            $post_order_request->setOrderId($order_id);

            /** @var PostOrderResponse $response */
            list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
            static::processRequestStatus($status, true);

            if (!$response) {
                echo 'Ошибка отправки торговой заявки' . PHP_EOL;

                throw new Exception('Buy order error');
            }

            echo 'Заявка с идентификатором ' . $response->getOrderId() . ' отправлена' . PHP_EOL;

        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::MAIN_LOG_TARGET);
        }
    }

    /**
     * @param string $account_id
     * @param string $type
     * @param string $ticker
     * @param int $lots
     * @param float|null $price
     * @return void
     */
    public function actionSell(string $account_id, string $type, string $ticker, int $lots = 1, float $price = null): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        try {
            echo 'Запрос продажи  ' . $type . ' ' . $ticker . ' со счета ' . $account_id . PHP_EOL;

            $tinkoff_api = TIServices::clientByAccount($account_id);
            $tinkoff_instruments = TIServices::instrumentsForAccount($account_id);

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

            if ($target_instrument->getSellAvailableFlag()) {
                echo 'Продажа доступна' . PHP_EOL;
            } else {
                echo 'Продажа не доступна' . PHP_EOL;

                throw new Exception('Impossible to sell');
            }

            $trading_status = $target_instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING &&
                $trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_DEALER_NORMAL_TRADING) {
                echo 'Не подходящий Trading Status: ' . SecurityTradingStatus::name($trading_status) . PHP_EOL;

                throw new Exception('Impossible to sell');
            }

            echo 'Получаем стакан' . PHP_EOL;

            $orderbook_request = new GetOrderBookRequest();
            $orderbook_request->setDepth(1);
            $orderbook_request->setInstrumentId($target_instrument->getFigi());

            /** @var GetOrderBookResponse $response */
            list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
            static::processRequestStatus($status, true);

            if (!$response) {
                echo 'Ошибка получения стакана заявок' . PHP_EOL;

                throw new Exception('Impossible to sell');
            }

            /** @var RepeatedField|Order[] $asks */
            $asks = $response->getAsks();

            /** @var RepeatedField|Order[] $bids */
            $bids = $response->getBids();

            if ($asks->count() === 0 || $bids->count() === 0) {
                echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

                throw new Exception('Impossible to sell');
            }

            $top_ask_price = $asks[0]->getPrice();
            $top_bid_price = $bids[0]->getPrice();

            $current_sell_price = $top_bid_price;

            if ($price === null) {
                $current_sell_price_decimal = QuotationHelper::toCurrency($current_sell_price, $target_instrument);

                echo 'Целевая цена из стакана: ' . $current_sell_price_decimal . PHP_EOL;
            } else {
                $current_sell_price_decimal = $price;

                if (!QuotationHelper::isPriceValid($current_sell_price_decimal, $target_instrument)) {
                    echo 'Цена ' . $price. ' для инструмента не валидна, не подходящий шаг цены' . PHP_EOL;

                    throw new Exception('Sell order error');
                }

                echo 'Целевая цена: ' . $current_sell_price_decimal . PHP_EOL;
            }

            /** @var Quotation $current_sell_price */
            $current_sell_price = QuotationHelper::toQuotation($current_sell_price_decimal);

            echo 'Сконвертированная цена: ' . $current_sell_price->serializeToJsonString() . PHP_EOL;
            echo 'Попытаемся продать ' . $lots . ' лотов по цене (' . $current_sell_price_decimal . ')' . PHP_EOL;

            $post_order_request = new PostOrderRequest();
            $post_order_request->setInstrumentId($target_instrument->getFigi());
            $post_order_request->setQuantity($lots);
            $post_order_request->setPrice($current_sell_price);
            $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_SELL);
            $post_order_request->setAccountId($account_id);
            $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

            $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

            $post_order_request->setOrderId($order_id);

            /** @var PostOrderResponse $response */
            list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
            static::processRequestStatus($status, true);

            if (!$response) {
                echo 'Ошибка отправки торговой заявки' . PHP_EOL;

                throw new Exception('Buy order error');
            }

            echo 'Заявка с идентификатором ' . $response->getOrderId() . ' отправлена' . PHP_EOL;

        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::MAIN_LOG_TARGET);
        }
    }



    /**
     * @param string $account_id
     * @param Bond $target_instrument
     * @param bool $force
     *
     * @return Quotation|null
     *
     * @throws ValidateException
     * @throws Exception
     */
    public function bondOrderbookPrice(string $account_id, Bond $target_instrument, bool $force = false): ?Quotation
    {
        $orderbook_request = new GetOrderBookRequest();
        $orderbook_request->setDepth(1);
        $orderbook_request->setInstrumentId($target_instrument->getFigi());

        $tinkoff_api = TIServices::clientByAccount($account_id);

        /** @var GetOrderBookResponse $response */
        list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
        static::processRequestStatus($status, true);

        if (!$response) {
            echo 'Ошибка получения стакана заявок' . PHP_EOL;

            return null;
        }

        /** @var RepeatedField|Order[] $asks */
        $asks = $response->getAsks();

        /** @var RepeatedField|Order[] $bids */
        $bids = $response->getBids();

        if ($asks->count() === 0 || $bids->count() === 0) {
            echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

            return null;
        }

        $top_ask_price = $asks[0]->getPrice();
        $top_bid_price = $bids[0]->getPrice();

        if ($force) {
            return $top_ask_price;
        } else {
            return $top_bid_price;
        }
    }




    /**
     * @param string $account_id
     * @param string $ticker
     * @param float $price
     * @return void
     */
    protected function storeCurrentPrice(string $account_id, string $ticker, float $price): void
    {
        try {
            $cache_key = 'history_prices_list@account:' . $account_id . ':ticker:' . $ticker;

            Yii::$app->redis->lpush($cache_key, json_encode([date('Y-m-d'), time(), $price]));
            Yii::$app->redis->ltrim($cache_key, 0, 5 * 12 * 60);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::MAIN_LOG_TARGET);
        }
    }

    /**
     * @param array $portfolio
     * @return array
     */
    protected static function portfolioAnalyse(array $portfolio): array
    {
        $avg_lot_price = 0;
        $spend_money = 0;
        $lots_count = 0;

        foreach ($portfolio as $row) {
            list ($time, $lots, $price) = $row;

            $lots_count += $lots;
            $buy_money = $lots * $price;
            $spend_money += $buy_money;
        }

        if ($lots_count > 0) {
            $avg_lot_price = $spend_money / $lots_count;
        }

        return [$avg_lot_price, $lots_count, $spend_money];
    }

    /**
     * @param array $history_data
     * @param int $lot_increment
     * @param int $increment_period
     * @param int $buy_step
     * @param float $trailing_sensitivity
     *
     * @return array
     */
    protected function modelingTrailingBuy(array $history_data, int $lot_increment, int $increment_period, int $buy_step, float $trailing_sensitivity): array
    {
        $portfolio = [];

        $cache_trailing_count_value = 1;
        $cache_trailing_price_value = 0;
        $cache_trailing_events_value = 0;

        $minutes = 0;

        foreach ($history_data as $minute_data) {
            list($day, $time, $current_price_decimal) = $minute_data;

            $cache_trailing_price_value = $cache_trailing_price_value ?: $current_price_decimal;

            $minutes ++;

            if ($minutes === $increment_period) {
                $cache_trailing_count_value += $lot_increment;

                $minutes = 0;
            }

            $buy_step_reached = ($cache_trailing_count_value >= $buy_step);

            $place_order = false;

            $sensitivity_price = $cache_trailing_price_value * (1 + $trailing_sensitivity / 100);

            if ($buy_step_reached) {
                if ($current_price_decimal >= $sensitivity_price) {
                    $cache_trailing_events_value++;

                    if ($cache_trailing_events_value >= 3) {
                        $place_order = true;
                        $cache_trailing_events_value = 0;
                    }
                } else {
                    $cache_trailing_events_value = 0;
                }
            } else {
                $cache_trailing_events_value = 0;
            }

            if (!$place_order) {
                /** Не переносим накопленные остатки на следующий день */
                $final_day_action = ($cache_trailing_count_value > ($buy_step / 2)) && $this->isValidTradingPeriodModeling($time, 3, 40, 3, 44);

                if ($final_day_action) {
                    $place_order = true;
                }
            }

            if ($place_order) {
                $order_moment = new DateTime();
                $order_moment->setTimezone(new DateTimeZone('UTC'));
                $order_moment->setTimestamp($time);
                $order_moment->setTimezone(new DateTimeZone('Asia/Krasnoyarsk'));

                $portfolio[] = [
                    $order_moment->format('Y-m-d H:i:s'),
                    $cache_trailing_count_value,
                    $current_price_decimal
                ];

                unset($order_moment);

                $cache_trailing_count_value = 0;
                $cache_trailing_price_value = $current_price_decimal;
            } else {
                if ($buy_step_reached) {
                    $cache_trailing_price_value = min($cache_trailing_price_value, $current_price_decimal);
                } else {
                    $cache_trailing_price_value = $current_price_decimal;
                }
            }
        }

        return $portfolio;
    }

    /**
     * @param string $account_id
     * @param string $ticker
     * @param string|null $date
     *
     * @return void
     *
     * @throws Exception
     */
    public function actionPeriodParamsOptimization(string $account_id, string $ticker, string $date = null): void
    {
        $cache_key = 'history_prices_list@account:' . $account_id . ':ticker:' . $ticker;

        $data = Yii::$app->redis->lrange($cache_key, 0, 7 * 24 * 60);

        $start_date = new DateTime($date ? ($date . ' 12:00:00') : 'now', new DateTimeZone('Asia/Krasnoyarsk'));
        $start_date->setTime(13, 59, 59);

        $end_date = new DateTime('now', new DateTimeZone('Asia/Krasnoyarsk'));
        $end_date->setTime(3, 59, 59);

        $interval = DateInterval::createFromDateString('1 day');

        $date_range = new DatePeriod($start_date, $interval, $end_date);

        $history_data = [];

        echo 'Получаем исторические данные из хранилища ...' . PHP_EOL;

        foreach ($date_range as $day) {
            $day->setTimezone(new DateTimeZone('UTC'));
            $day_timestamp = $day->getTimestamp();
            $day_date = $day->format('Y-m-d');

            echo '   Ищем данные за дату ' . $day_date . ' ... ';

            $day_history_data = [];

            foreach ($data as $row) {
                list($day, $time, $price) = json_decode($row);

                if ($time >= $day_timestamp && $time < $day_timestamp + 24 * 60 * 60) {
                    $day_history_data[] = [$day, $time, $price];
                }
            }

            if ($day_history_data) {
                echo 'найдено ' . count($day_history_data) . ' записей' . PHP_EOL;

                $history_data[$day_date] = $day_history_data;
            } else {
                echo 'данных нет' . PHP_EOL;
            }
        }

        if (!$history_data) {
            echo 'Данные не найдены' . PHP_EOL;

            return;
        }

        $strategy_increment_value = 1;
        $strategy_increment_period = 5;
        $strategy_buy_lots_limit = 10;
        $strategy_trailing_sensitivity = 0.16;

        $modeling_portfolios = [];

        foreach ($history_data as $day_date => $day_data) {
            $day_data = array_reverse($day_data);

            echo 'Моделируем покупки за ' . $day_date . ' ... ' . PHP_EOL;

            for ($buy_limit = 10; $buy_limit <= 10; $buy_limit += 1) {
                for ($trailing_sensitivity = 0.1; $trailing_sensitivity <= 0.65; $trailing_sensitivity += 0.01) {
                    $portfolio = $this->modelingTrailingBuy($day_data, $strategy_increment_value, $strategy_increment_period, $buy_limit, $trailing_sensitivity);

                    $trailing_sensitivity = NumbersHelper::printFloat($trailing_sensitivity, 2, false);

                    if (!isset($modeling_portfolios[$strategy_increment_value][$strategy_increment_period][$buy_limit][$trailing_sensitivity])) {
                        $modeling_portfolios[$strategy_increment_value][$strategy_increment_period][$buy_limit][$trailing_sensitivity] = $portfolio;
                    } else {
                        $modeling_portfolios[$strategy_increment_value][$strategy_increment_period][$buy_limit][$trailing_sensitivity] = array_merge(
                            $modeling_portfolios[$strategy_increment_value][$strategy_increment_period][$buy_limit][$trailing_sensitivity],
                            $portfolio
                        );
                    }
                }
            }

            echo 'Выполнено' . PHP_EOL;
        }

        echo 'Вычисляем среднюю цену лота:' . PHP_EOL;

        $modeling_data = [];

        foreach ($modeling_portfolios as $strategy_increment_value => $data1) {
            foreach ($data1 as $strategy_increment_period => $data2) {
                foreach ($data2 as $buy_limit => $data3) {
                    foreach ($data3 as $trailing_sensitivity => $portfolio) {
                        $params = [
                            'increment_value' => $strategy_increment_value,
                            'increment_period' => $strategy_increment_period,
                            'buy_limit' => $buy_limit,
                            'trailing_sensitivity' => $trailing_sensitivity,
                        ];

                        list($avg_lot_price, $lots_count, $spend_money) = static::portfolioAnalyse($portfolio);

                        $modeling_data[] = [
                            'avg_price' => $avg_lot_price,
                            'params' => $params,
                        ];

                        echo json_encode($params) . ' => ' . PHP_EOL;
                        echo '   Куплено ' . $lots_count . ' со средней ценой : ' . NumbersHelper::printFloat($avg_lot_price, 3, false) . ' руб.' . PHP_EOL;
                    }
                }
            }
        }

        usort($modeling_data, function ($a, $b) {
            if ($a['avg_price'] === $b['avg_price']) {
                if ($a['params']['trailing_sensitivity'] == $b['params']['trailing_sensitivity']) {
                    return $a['params']['buy_limit'] <=> $b['params']['buy_limit'];
                }

                return $a['params']['trailing_sensitivity'] <=> $b['params']['trailing_sensitivity'];
            }

            return $a['avg_price'] < $b['avg_price'] ? -1 : 1;
        });

        echo ' ---------------------------------------- ' . PHP_EOL;
        echo ' ---------------------------------------- ' . PHP_EOL . PHP_EOL;

        if (count($history_data) === 1) {
            $day_data = reset($history_data);
            $day_data = array_reverse($day_data);

            echo ' Моделируем Текущий сценарий:' . PHP_EOL;

            $portfolio = $this->modelingTrailingBuy($day_data, $strategy_increment_value, $strategy_increment_period, $strategy_buy_lots_limit, $strategy_trailing_sensitivity);

            $params = [
                'increment_value' => $strategy_increment_value,
                'increment_period' => $strategy_increment_period,
                'buy_limit' => $strategy_buy_lots_limit,
                'trailing_sensitivity' => $strategy_trailing_sensitivity,
            ];

            list($avg_lot_price, $lots_count, $spend_money) = static::portfolioAnalyse($portfolio);

            echo json_encode($params) . ' => ' . PHP_EOL;
            echo '   Куплено ' . $lots_count . ' со средней ценой : ' . NumbersHelper::printFloat($avg_lot_price, 3, false) . ' руб.' . PHP_EOL;

            echo ' ---------------------------------------- ' . PHP_EOL;
            echo ' ---------------------------------------- ' . PHP_EOL . PHP_EOL;
        }

        echo ' Лучшие сценарии:' . PHP_EOL;

        for ($i = 0; $i <= 14; $i++) {
            echo 'Сценарий ' . ($i+1) . ' со средней ценой ' . $modeling_data[$i]['avg_price'] . ' руб.' . PHP_EOL;
            echo json_encode($modeling_data[$i]['params']) . PHP_EOL;

            echo ' ---------------------------------------- ' . PHP_EOL . PHP_EOL;
        }

        //echo 'Сохраненная история цен для тикера ' . $ticker . ' на дату ' . $date . PHP_EOL;
        //$this->modelingTrailingBuy($history_data, 1, 5, 10, 0.16);
    }

    /**
     * @param string $account_id
     * @param string $ticker
     * @param string|null $date
     *
     * @return void
     *
     * @throws Exception
     */
    public function actionDayParamsOptimization(string $account_id, string $ticker, string $date = null): void
    {
        $cache_key = 'history_prices_list@account:' . $account_id . ':ticker:' . $ticker;

        $data = Yii::$app->redis->lrange($cache_key, 0, 7 * 24 * 60);

        $current_day = new DateTime($date ? ($date . ' 12:00:00') : 'now', new DateTimeZone('Asia/Krasnoyarsk'));
        $current_day->setTime(13, 59, 0);
        $current_day->setTimezone(new DateTimeZone('UTC'));

        $current_day_timestamp = $current_day->getTimestamp();

        $history_data = [];

        echo 'Получаем исторические данные из хранилища ...' . PHP_EOL;

        foreach ($data as $row) {
            list($day, $time, $price) = json_decode($row);

            if ($time >= $current_day_timestamp && $time < $current_day_timestamp + 24 * 60 * 60) {
                $history_data[] = [$day, $time, $price];
            }

            unset($day);
            unset($time);
            unset($price);
        }

        unset($data);

        if (!$history_data) {
            echo 'Данные не найдены' . PHP_EOL;

            return;
        } else {
            echo 'Найдено ' . count($history_data) . ' записей' . PHP_EOL;
        }

        $history_data = array_reverse($history_data);

        $strategy_increment_value = 1;
        $strategy_increment_period = 5;
        $strategy_buy_lots_limit = 10;
        $strategy_trailing_sensitivity = 0.16;

        $modeling_data = [];

        for ($buy_limit = 10; $buy_limit <= 10; $buy_limit += 1) {
            for ($trailing_sensitivity = 0.1; $trailing_sensitivity <= 0.65; $trailing_sensitivity += 0.01) {
                $params = [
                    'increment_value' => $strategy_increment_value,
                    'increment_period' => $strategy_increment_period,
                    'buy_limit' => $buy_limit,
                    'trailing_sensitivity' => NumbersHelper::printFloat($trailing_sensitivity, 2, false),
                ];

                $portfolio = $this->modelingTrailingBuy($history_data, $strategy_increment_value, $strategy_increment_period, $buy_limit, $trailing_sensitivity);

                list($avg_lot_price, $lots_count, $spend_money) = static::portfolioAnalyse($portfolio);

                echo json_encode($params) . ' => ' . PHP_EOL;
                echo '   Куплено ' . $lots_count . ' со средней ценой : ' . NumbersHelper::printFloat($avg_lot_price, 3, false) . ' руб.' . PHP_EOL;

                $modeling_data[] = [
                    'avg_price' => $avg_lot_price,
                    'params' => $params,
                ];

                echo ' ---------------------------------------- ' . PHP_EOL . PHP_EOL;
            }
        }

        usort($modeling_data, function ($a, $b) {
            if ($a['avg_price'] === $b['avg_price']) {
                if ($a['params']['trailing_sensitivity'] == $b['params']['trailing_sensitivity']) {
                    return $a['params']['buy_limit'] <=> $b['params']['buy_limit'];
                }

                return $a['params']['trailing_sensitivity'] <=> $b['params']['trailing_sensitivity'];
            }

            return $a['avg_price'] < $b['avg_price'] ? -1 : 1;
        });

        echo ' ---------------------------------------- ' . PHP_EOL;
        echo ' ---------------------------------------- ' . PHP_EOL . PHP_EOL;

        echo ' Текущий сценарий:' . PHP_EOL;

        $params = [
            'increment_value' => $strategy_increment_value,
            'increment_period' => $strategy_increment_period,
            'buy_limit' => $strategy_buy_lots_limit,
            'trailing_sensitivity' => $strategy_trailing_sensitivity,
        ];

        $portfolio = $this->modelingTrailingBuy($history_data, $strategy_increment_value, $strategy_increment_period, $strategy_buy_lots_limit, $strategy_trailing_sensitivity);

        list($avg_lot_price, $lots_count, $spend_money) = static::portfolioAnalyse($portfolio);

        echo json_encode($params) . ' => ' . PHP_EOL;
        echo '   Куплено ' . $lots_count . ' со средней ценой : ' . NumbersHelper::printFloat($avg_lot_price, 3, false) . ' руб.' . PHP_EOL;

        echo ' ---------------------------------------- ' . PHP_EOL;
        echo ' ---------------------------------------- ' . PHP_EOL . PHP_EOL;

        echo ' Лучшие сценарии:' . PHP_EOL;

        for ($i = 0; $i <= 14; $i++) {
            echo 'Сценарий ' . ($i+1) . ' со средней ценой ' . $modeling_data[$i]['avg_price'] . ' руб.' . PHP_EOL;
            echo json_encode($modeling_data[$i]['params']) . PHP_EOL;

            echo ' ---------------------------------------- ' . PHP_EOL . PHP_EOL;
        }

        //echo 'Сохраненная история цен для тикера ' . $ticker . ' на дату ' . $date . PHP_EOL;
        //$this->modelingTrailingBuy($history_data, 1, 5, 10, 0.16);
    }

}
