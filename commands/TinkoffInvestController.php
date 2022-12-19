<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\helpers\ArrayHelper;
use app\helpers\DateTimeHelper;
use DateTime;
use DateTimeZone;
use Exception;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Timestamp;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use Metaseller\TinkoffInvestApi2\providers\InstrumentsProvider;
use Metaseller\yii2TinkoffInvestApi2\TinkoffInvestApi;
use stdClass;
use Throwable;
use Tinkoff\Invest\V1\Account;
use Tinkoff\Invest\V1\CandleInstrument;
use Tinkoff\Invest\V1\Etf;
use Tinkoff\Invest\V1\EtfsResponse;
use Tinkoff\Invest\V1\GetAccountsRequest;
use Tinkoff\Invest\V1\GetAccountsResponse;
use Tinkoff\Invest\V1\GetCandlesRequest;
use Tinkoff\Invest\V1\GetCandlesResponse;
use Tinkoff\Invest\V1\HistoricCandle;
use Tinkoff\Invest\V1\CandleInterval;
use app\components\console\Controller;
use Tinkoff\Invest\V1\GetInfoRequest;
use Tinkoff\Invest\V1\GetInfoResponse;
use Tinkoff\Invest\V1\GetOrderBookRequest;
use Tinkoff\Invest\V1\GetOrderBookResponse;
use Tinkoff\Invest\V1\InstrumentsRequest;
use Tinkoff\Invest\V1\InstrumentStatus;
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
use Tinkoff\Invest\V1\OrderType;
use Tinkoff\Invest\V1\PortfolioPosition;
use Tinkoff\Invest\V1\PortfolioRequest;
use Tinkoff\Invest\V1\PortfolioResponse;
use Tinkoff\Invest\V1\PostOrderRequest;
use Tinkoff\Invest\V1\PostOrderResponse;
use Tinkoff\Invest\V1\SecurityTradingStatus;
use Tinkoff\Invest\V1\SubscribeCandlesRequest;
use Tinkoff\Invest\V1\SubscribeLastPriceRequest;
use Tinkoff\Invest\V1\SubscribeOrderBookRequest;
use Tinkoff\Invest\V1\SubscriptionAction;
use Tinkoff\Invest\V1\SubscriptionInterval;
use Tinkoff\Invest\V1\WithdrawLimitsRequest;
use Tinkoff\Invest\V1\WithdrawLimitsResponse;
use Yii;

/**
 * Консольный контроллер, предназначенный для демонстрации работы с Tinkoff Invest Api и реализации базовой стратегии робота-покупателя ETF
 *
 * @package app\commands
 */
class TinkoffInvestController extends Controller
{
    use ProgressTrait;

    /**
     * @var string Основная цель логирования коммуникации с Tinkoff Invest
     */
    public const MAIN_LOG_TARGET = 'tinkoff_invest';

    /**
     * @var string Основная цель логирования исполнения стратегии
     */
    public const BUY_STRATEGY_LOG_TARGET = 'tinkoff_invest_strategy';

    /**
     * @var string Основная цель логирования исполнения стратегии
     */
    public const TRADE_STRATEGY_LOG_TARGET = 'tinkoff_invest_strategy_trade';

    protected const TRADE_ETF_STRATEGY = [
        'account1' => [
            'ETF' => 'TCS00A102EQ8',
            'ACTIVE' => false,

            'INCREMENT_VALUE' => 1,

            'BUY_LOTS_BOTTOM_LIMIT' => 1,
            'BUY_LOTS_UPPER_LIMIT' => 3,

            'BUY_TRAILING_PERCENTAGE' => 0.05,
            'SELL_TRAILING_PERCENTAGE' => 0.05,

            'EXPECTED_YIELD' => 0.085,

            'STOP_LOSS_YIELD' => 0.1,
            'STOP_LOSS' => 0.85,
            'DAY_FINALIZATION_YIELD' => 0.1,

            'LOG_TARGET' => 'tinkoff_trade_strategy_tspx',

            'TRADE_START_H' => 20,
            'TRADE_START_M' => 30,
            'TRADE_END_H' => 22,
            'TRADE_END_M' => 45,
            'TRADE_FINALIZATION_H' => 22,
            'TRADE_FINALIZATION_M' => 40,
        ],
        'account2' => [
            'ETF' => 'BBG333333333',
            'ACTIVE' => true,

            'INCREMENT_VALUE' => 30,

            'BUY_LOTS_BOTTOM_LIMIT' => 60,
            'BUY_LOTS_UPPER_LIMIT' => 1800,

            'BUY_TRAILING_PERCENTAGE' => 0.075,
            'SELL_TRAILING_PERCENTAGE' => 0.07,

            'EXPECTED_YIELD' => 0.1,

            'STOP_LOSS_YIELD' => 0.065,
            'STOP_LOSS' => 1.5,
            'DAY_FINALIZATION_YIELD' => 0.15,

            'LOG_TARGET' => 'tinkoff_trade_strategy_tmos',

            'TRADE_START_H' => 14,
            'TRADE_START_M' => 0,
            'TRADE_END_H' => 23,
            'TRADE_END_M' => 59,
            'TRADE_FINALIZATION_H' => 23,
            'TRADE_FINALIZATION_M' => 55,
        ],
    ];

    /**
     * Консольное действие, которые выводит в stdout список идентификаторов ваших портфелей
     *
     * @return void
     */
    public function actionUserInfo(): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        ob_start();

        try {
            /**
             * @var TinkoffInvestApi $tinkoff_api Этот объект создается автоматически, используя магический геттер и настройки
             * компоненты tinkoffInvest в файле ./../config/console.php
             *
             * В целом вы можете создавать данный объект вручную, например следующий функционал SDK:
             *  <code>
             *      $tinkoff_api = TinkoffClientsFactory::create($token);
             *  </code>
             */
            $tinkoff_api = Yii::$app->tinkoffInvest;

            /**
             * Создаем экземпляр запроса информации об аккаунте к сервису
             *
             * Запрос не принимает никаких параметров на вход
             *
             * @see https://tinkoff.github.io/investAPI/users/#getinforequest
             */
            $request = new GetInfoRequest();

            /**
             * @var GetInfoResponse $response - Получаем ответ, содержащий информацию о пользователе
             */
            list($response, $status) = $tinkoff_api->usersServiceClient->GetInfo($request)->wait();
            $this->processRequestStatus($status, true);

            /** Выводим полученную информацию */
            var_dump(['user_info' => [
                'prem_status' => $response->getPremStatus(),
                'qual_status' => $response->getQualStatus(),
                'qualified_for_work_with' => ArrayHelper::repeatedFieldToArray($response->getQualifiedForWorkWith()),
            ]]);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        $stdout_data = ob_get_contents();

        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, static::MAIN_LOG_TARGET);

            echo $stdout_data;
        }
    }

    /**
     * Консольное действие, которые выводит в stdout список идентификаторов ваших портфелей
     *
     * @return void
     */
    public function actionAccounts(): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        ob_start();

        try {
            /**
             * @var TinkoffInvestApi $tinkoff_api Этот объект создается автоматически, используя магический геттер и настройки
             * компоненты tinkoffInvest в файле ./../config/console.php
             *
             * В целом вы можете создавать данный объект вручную, например следующий функционал SDK:
             *  <code>
             *      $tinkoff_api = TinkoffClientsFactory::create($token);
             *  </code>
             */
            $tinkoff_api = Yii::$app->tinkoffInvest;

            /**
             * @var GetAccountsResponse $response - Получаем ответ, содержащий информацию об аккаунтах
             */
            list($response, $status) = $tinkoff_api->usersServiceClient->GetAccounts(new GetAccountsRequest())
                ->wait()
            ;

            $this->processRequestStatus($status, true);

            /** Выводим полученную информацию */
            /** @var Account $account */
            foreach ($response->getAccounts() as $account) {
                echo $account->getName() . ' => ' . $account->getId() . PHP_EOL;
            }
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        $stdout_data = ob_get_contents();

        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, static::MAIN_LOG_TARGET);

            echo $stdout_data;
        }
    }

    /**
     * Метод демонстративно выводит в stdout информацию о составе вашего портфеля с указанным идентификатором аккаунта (портфеля)
     *
     * @param string $account_id Идентификатор аккаунта (портфеля)
     *
     * @return void
     */
    public function actionPortfolio(string $account_id): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        ob_start();

        try {
            $tinkoff_api = Yii::$app->tinkoffInvest;
            $client = $tinkoff_api->operationsServiceClient;

            $request = new PortfolioRequest();
            $request->setAccountId($account_id);

            /**
             * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
             */
            list($response, $status) = $client->GetPortfolio($request)->wait();
            $this->processRequestStatus($status, true);

            /** Выводим полученную информацию */
            var_dump(['portfolio_info' => [
                'total_amount_shares' => $response->getTotalAmountShares()->serializeToJsonString(),
                'total_amount_bonds' => $response->getTotalAmountBonds()->serializeToJsonString(),
                'total_amount_etf' => $response->getTotalAmountEtf()->serializeToJsonString(),
                'total_amount_futures' => $response->getTotalAmountFutures()->serializeToJsonString(),
                'total_amount_currencies' => $response->getTotalAmountCurrencies()->serializeToJsonString(),
            ]]);

            $positions = $response->getPositions();

            echo 'Available portfolio positions: ' . PHP_EOL;

            $instruments_provider = new InstrumentsProvider($tinkoff_api, true, true, true, true);

            /** @var PortfolioPosition $position */
            foreach ($positions as $position) {
                $dictionary_instrument = $instruments_provider->instrumentByFigi($position->getFigi());

                $display = '[' . $position->getInstrumentType() . '][' . $position->getFigi() . '][' . $dictionary_instrument->getIsin() . '][' . $dictionary_instrument->getTicker() . '] ' . $dictionary_instrument->getName();

                echo $display . PHP_EOL;
                echo 'Лотов: ' . $position->getQuantityLots()->getUnits() . ', Количество: ' . QuotationHelper::toDecimal($position->getQuantity()). PHP_EOL;

                $average_position_price = $position->getAveragePositionPrice();
                $average_position_price_fifo = $position->getAveragePositionPriceFifo();

                echo 'Средняя цена: ' . ($average_position_price ? QuotationHelper::toCurrency($average_position_price, $dictionary_instrument) : ' -- ') . ', ' .
                     'Средняя цена FIFO: ' . ($average_position_price_fifo ? QuotationHelper::toCurrency($average_position_price_fifo, $dictionary_instrument) : ' -- ') . ',' . PHP_EOL;
                echo PHP_EOL;
            }
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        $stdout_data = ob_get_contents();

        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, static::MAIN_LOG_TARGET);

            echo $stdout_data;
        }
    }

    /**
     * Метод инкрементирует в указанном аккаунте количество накопленных к покупке лотов ETF с указанным тикером на
     * указанное количество единиц
     *
     * @param string $account_id Идентификатор аккаунта, для которого работает стратегия
     * @param string $ticker Тикер ETF инструмента
     * @param int $lots_increment Количество лотов, на которое инкрементируем накопленное количество
     *
     * @return void
     *
     * @throws Exception
     */
    public function actionIncrementEtfTrailing(string $account_id, string $ticker, int $lots_increment): void
    {
        Log::info('Start action ' . __FUNCTION__, static::BUY_STRATEGY_LOG_TARGET);

        ob_start();

        if (
            !$this->isValidTradingPeriod(14, 0, 23, 59) &&
            !$this->isValidTradingPeriod(0, 0, 3, 45)
        ) {
            return;
        }

        try {
            $tinkoff_api = Yii::$app->tinkoffInvest;
            $tinkoff_instruments = InstrumentsProvider::create($tinkoff_api);

            echo 'Ищем ETF инструмент' . PHP_EOL;

            $target_instrument = $tinkoff_instruments->etfByTicker($ticker);

            echo 'Инструмент найден' . PHP_EOL;

            if ($target_instrument->getBuyAvailableFlag()) {
                echo 'Покупка доступна' . PHP_EOL;
            } else {
                echo 'Покупка не доступна' . PHP_EOL;

                return;
            }

            $trading_status = $target_instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING) {
                echo 'Не подходящий Trading Status: ' . SecurityTradingStatus::name($trading_status) . PHP_EOL;

                return;
            }

            echo 'Проверим еще и стакан' . PHP_EOL;

            $orderbook_request = new GetOrderBookRequest();
            $orderbook_request->setDepth(1);
            $orderbook_request->setFigi($target_instrument->getFigi());

            /** @var GetOrderBookResponse $response */
            list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
            $this->processRequestStatus($status);

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

            $cache_trailing_count_key = $account_id . '@etf@' . $ticker . '_count';
            $cache_trailing_count_value = Yii::$app->cache->get($cache_trailing_count_key) ?? 0;

            echo 'Накопленное количество к покупке ' . $ticker . ': ' . $cache_trailing_count_value . PHP_EOL;

            $cache_trailing_count_value = $cache_trailing_count_value + $lots_increment;

            echo 'Инкрементируем количество к покупке ' . $ticker . ': ' . $cache_trailing_count_value . PHP_EOL;


            Yii::$app->cache->set($cache_trailing_count_key, $cache_trailing_count_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::BUY_STRATEGY_LOG_TARGET);
        }

        $stdout_data = ob_get_contents();
        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, static::BUY_STRATEGY_LOG_TARGET);

            echo $stdout_data;
        }
    }

    /**
     * @throws Exception
     */
    public function actionIncrementEtfTrailingTrade(string $account_shortcut): void
    {
        if (!static::TRADE_ETF_STRATEGY[$account_shortcut]['ACTIVE'] ?? false) {
            return;
        }

        $figi = static::TRADE_ETF_STRATEGY[$account_shortcut]['ETF'];
        $lots_increment = static::TRADE_ETF_STRATEGY[$account_shortcut]['INCREMENT_VALUE'];

        $lots_increment_limit = static::TRADE_ETF_STRATEGY[$account_shortcut]['BUY_LOTS_UPPER_LIMIT'];

        $log_target = static::TRADE_ETF_STRATEGY[$account_shortcut]['LOG_TARGET'] ?? static::TRADE_STRATEGY_LOG_TARGET;

        $trade_start_h = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_START_H'] ?? 20;
        $trade_start_m = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_START_M'] ?? 30;
        $trade_end_h = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_END_H'] ?? 22;
        $trade_end_m = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_END_M'] ?? 45;
        $trade_finalization_h = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_FINALIZATION_H'] ?? 22;
        $trade_finalization_m = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_FINALIZATION_M'] ?? 40;

        Log::info('Start action ' . __FUNCTION__, $log_target);

        ob_start();

        if (!$this->isValidTradingPeriod($trade_start_h, $trade_start_m, $trade_end_h, $trade_end_m)) {
            return;
        }

        try {
            $tinkoff_api = Yii::$app->tinkoffInvest;
            $tinkoff_instruments = InstrumentsProvider::create($tinkoff_api);

            echo 'Ищем ETF инструмент' . PHP_EOL;

            $target_instrument = $tinkoff_instruments->etfByFigi($figi);

            echo 'Инструмент найден' . PHP_EOL;

            if ($target_instrument->getBuyAvailableFlag()) {
                echo 'Покупка доступна' . PHP_EOL;
            } else {
                echo 'Покупка не доступна' . PHP_EOL;

                return;
            }

            $trading_status = $target_instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING) {
                echo 'Не подходящий Trading Status: ' . SecurityTradingStatus::name($trading_status) . PHP_EOL;

                return;
            }

            echo 'Проверим еще и стакан' . PHP_EOL;

            $orderbook_request = new GetOrderBookRequest();
            $orderbook_request->setDepth(1);
            $orderbook_request->setFigi($target_instrument->getFigi());

            /** @var GetOrderBookResponse $response */
            list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
            $this->processRequestStatus($status);

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

            $cache_trailing_count_key = $account_shortcut . '@TRetf@' . $figi . '_count';
            $cache_trailing_count_value = Yii::$app->cache->get($cache_trailing_count_key) ?? 0;

            echo 'Накопленное количество к покупке ' . $figi . ': ' . $cache_trailing_count_value . PHP_EOL;

            if ($cache_trailing_count_value + $lots_increment <= $lots_increment_limit) {
                $cache_trailing_count_value = $cache_trailing_count_value + $lots_increment;

                echo 'Инкрементируем количество к покупке ' . $figi . ': ' . $cache_trailing_count_value . PHP_EOL;
            } else {
                echo 'Достигнут лимит количества к покупке по FIGI ' . $figi . ': ' . $cache_trailing_count_value . PHP_EOL;
            }

            Yii::$app->cache->set($cache_trailing_count_key, $cache_trailing_count_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;
            echo 'Ошибка: ' . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), $log_target);
        }

        $stdout_data = ob_get_contents();
        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, $log_target);

            echo $stdout_data;
        }
    }

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
        Log::info('Start action ' . __FUNCTION__, static::BUY_STRATEGY_LOG_TARGET);

        ob_start();

        if (
            !$this->isValidTradingPeriod(14, 0, 23, 59) &&
            !$this->isValidTradingPeriod(0, 0, 3, 45)
        ) {
            return;
        }

        try {
            $tinkoff_api = Yii::$app->tinkoffInvest;
            $tinkoff_instruments = InstrumentsProvider::create($tinkoff_api);

            echo 'Ищем ETF инструмент' . PHP_EOL;

            $target_instrument = $tinkoff_instruments->etfByTicker($ticker);

            echo 'Инструмент найден' . PHP_EOL;

            if ($target_instrument->getBuyAvailableFlag()) {
                echo 'Покупка доступна' . PHP_EOL;
            } else {
                echo 'Покупка не доступна' . PHP_EOL;

                return;
            }

            $trading_status = $target_instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING) {
                echo 'Не подходящий Trading Status: ' . SecurityTradingStatus::name($trading_status) . PHP_EOL;

                return;
            }

            echo 'Получаем стакан' . PHP_EOL;

            $orderbook_request = new GetOrderBookRequest();
            $orderbook_request->setDepth(1);
            $orderbook_request->setFigi($target_instrument->getFigi());

            /** @var GetOrderBookResponse $response */
            list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
            $this->processRequestStatus($status);

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
            $cache_traling_events_value = Yii::$app->cache->get($cache_trailing_events_key) ?: 0;

            $buy_step_reached = ($cache_trailing_count_value >= $buy_step);

            $place_order = false;

            $sensitivity_price = $cache_trailing_price_value * (1 + $trailing_sensitivity / 100);

            if ($buy_step_reached) {
                if ($current_price_decimal >= $sensitivity_price) {
                    $cache_traling_events_value++;

                    if ($cache_traling_events_value >= 3) {
                        $place_order = true;
                        $cache_traling_events_value = 0;
                    }
                } else {
                    $cache_traling_events_value = 0;
                }
            } else {
                $cache_traling_events_value = 0;
            }

            Yii::$app->cache->set($cache_trailing_events_key, $cache_traling_events_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);

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
                    'stability' => $cache_traling_events_value,
                ]) . PHP_EOL
            ;

            if ($place_order) {
                echo 'Событие покупки. Попытаемся купить ' . $cache_trailing_count_value . ' лотов' . PHP_EOL;

                $post_order_request = new PostOrderRequest();
                $post_order_request->setFigi($target_instrument->getFigi());
                $post_order_request->setQuantity($cache_trailing_count_value);
                $post_order_request->setPrice($current_price);
                $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_BUY);
                $post_order_request->setAccountId($account_id);
                $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

                $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

                $post_order_request->setOrderId($order_id);

                /** @var PostOrderResponse $response */
                list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
                $this->processRequestStatus($status);

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
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::BUY_STRATEGY_LOG_TARGET);
        }

        $stdout_data = ob_get_contents();
        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, static::BUY_STRATEGY_LOG_TARGET);

            echo $stdout_data;
        }
    }

    public function actionTradeEtfTrailing(string $account_shortcut): void
    {
        if (!static::TRADE_ETF_STRATEGY[$account_shortcut]['ACTIVE'] ?? false) {
            return;
        }

        if (!$account_id = Yii::$app->params['tinkoff_invest']['account_shortcuts'][$account_shortcut] ?? false) {
            return;
        }

        $figi = static::TRADE_ETF_STRATEGY[$account_shortcut]['ETF'];
        $buy_step = static::TRADE_ETF_STRATEGY[$account_shortcut]['BUY_LOTS_BOTTOM_LIMIT'];

        $buy_trailing_sensitivity = static::TRADE_ETF_STRATEGY[$account_shortcut]['BUY_TRAILING_PERCENTAGE'];
        $sell_trailing_sensitivity = static::TRADE_ETF_STRATEGY[$account_shortcut]['SELL_TRAILING_PERCENTAGE'];

        $expected_yield = static::TRADE_ETF_STRATEGY[$account_shortcut]['EXPECTED_YIELD'] ?? 0;

        $stop_loss_yield = static::TRADE_ETF_STRATEGY[$account_shortcut]['STOP_LOSS_YIELD'] ?? false;
        $stop_loss = static::TRADE_ETF_STRATEGY[$account_shortcut]['STOP_LOSS'] ?? false;

        $day_finalization_yield = static::TRADE_ETF_STRATEGY[$account_shortcut]['DAY_FINALIZATION_YIELD'] ?? false;

        $log_target = static::TRADE_ETF_STRATEGY[$account_shortcut]['LOG_TARGET'] ?? static::TRADE_STRATEGY_LOG_TARGET;

        $trade_start_h = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_START_H'] ?? 20;
        $trade_start_m = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_START_M'] ?? 30;
        $trade_end_h = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_END_H'] ?? 22;
        $trade_end_m = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_END_M'] ?? 45;
        $trade_finalization_h = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_FINALIZATION_H'] ?? 22;
        $trade_finalization_m = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_FINALIZATION_M'] ?? 40;

        Log::info('Start action ' . __FUNCTION__, $log_target);

        ob_start();

        if (!$this->isValidTradingPeriod($trade_start_h, $trade_start_m, $trade_end_h, $trade_end_m)) {
            return;
        }

        try {
            $tinkoff_api = Yii::$app->tinkoffInvest;
            $tinkoff_instruments = InstrumentsProvider::create($tinkoff_api);

            echo 'Ищем ETF инструмент' . PHP_EOL;

            $target_instrument = $tinkoff_instruments->etfByFigi($figi);

            echo 'Инструмент найден' . PHP_EOL;

            if ($target_instrument->getBuyAvailableFlag()) {
                echo 'Покупка доступна' . PHP_EOL;
            } else {
                echo 'Покупка не доступна' . PHP_EOL;

                return;
            }

            $trading_status = $target_instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING) {
                echo 'Не подходящий Trading Status: ' . SecurityTradingStatus::name($trading_status) . PHP_EOL;

                return;
            }

            echo 'Получаем портфель' . PHP_EOL;

            $request = new PortfolioRequest();
            $request->setAccountId($account_id);

            /**
             * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
             */
            list($response, $status) = $tinkoff_api->operationsServiceClient->GetPortfolio($request)->wait();
            $this->processRequestStatus($status, true);

            $positions = $response->getPositions();

            echo 'Available portfolio positions: ' . PHP_EOL;

            $portfolio_position = null;

            /** @var PortfolioPosition $position */
            foreach ($positions as $position) {
                if ($position->getFigi() === $target_instrument->getFigi()) {
                    $portfolio_position = $position;

                    break;
                }
            }

            if ($portfolio_position) {
                $average_position_price_fifo = $portfolio_position->getAveragePositionPriceFifo();
                $average_position_price_fifo_decimal = $average_position_price_fifo ? QuotationHelper::toDecimal($average_position_price_fifo) : 0;

                $portfolio_lots = (int) $portfolio_position->getQuantityLots()->getUnits();
                $portfolio_count = QuotationHelper::toDecimal($position->getQuantity());
                $portfolio_lot_price_decimal = $average_position_price_fifo_decimal * $target_instrument->getLot();
            } else {
                $portfolio_lots = 0;
                $portfolio_count = 0;
                $portfolio_lot_price_decimal = 0;
            }

            echo 'В портфеле лотов: ' . $portfolio_lots . ', Количество: ' . $portfolio_count . PHP_EOL;
            echo 'Средняя цена лота: ' . $portfolio_lot_price_decimal . PHP_EOL;

            echo 'Получаем стакан' . PHP_EOL;

            $orderbook_request = new GetOrderBookRequest();
            $orderbook_request->setDepth(1);
            $orderbook_request->setFigi($target_instrument->getFigi());

            /** @var GetOrderBookResponse $response */
            list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
            $this->processRequestStatus($status);

            if (!$response) {
                echo 'Ошибка получения стакана заявок' . PHP_EOL;

                return;
            }

            /** @var RepeatedField|Order[] $asks */
            $asks = $response->getAsks();

            /** @var RepeatedField|Order[] $bids */
            $bids = $response->getBids();

            if ($asks->count() === 0 || $bids->count() === 0) {
                echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

                return;
            }

            $top_ask_price = $asks[0]->getPrice();
            $top_bid_price = $bids[0]->getPrice();

            $current_buy_price = $top_ask_price;
            $current_buy_price_decimal = QuotationHelper::toDecimal($current_buy_price);

            $current_sell_price = $top_bid_price;
            $current_sell_price_decimal = QuotationHelper::toDecimal($current_sell_price);

            $cache_trailing_count_key = $account_shortcut . '@TRetf@' . $figi . '_count';

            $cache_trailing_buy_events_key = $account_shortcut . '@TRetf@' . $figi . '_buy_events';
            $cache_trailing_sell_events_key = $account_shortcut . '@TRetf@' . $figi . '_sell_events';
            $cache_trailing_stop_loss_events_key = $account_shortcut . '@TRetf@' . $figi . '_stop_loss_events';
            $cache_trailing_stop_loss_price_key = $account_shortcut . '@TRetf@' . $figi . '_stop_loss_price';

            $cache_trailing_buy_price_key = $account_shortcut . '@TRetf@' . $figi . '_buy_price';
            $cache_trailing_sell_price_key = $account_shortcut . '@TRetf@' . $figi . '_sell_price';

            $cache_stop_loss_price_reached_key = $account_shortcut . '@TRetf@' . $figi . '_stop_loss_reached';

            $cache_trailing_count_value = Yii::$app->cache->get($cache_trailing_count_key) ?: 0;

            $cache_trailing_buy_price_value = Yii::$app->cache->get($cache_trailing_buy_price_key) ?: $current_buy_price_decimal;
            $cache_trailing_sell_price_value = Yii::$app->cache->get($cache_trailing_sell_price_key) ?: $current_sell_price_decimal;

            $cache_traling_buy_events_value = Yii::$app->cache->get($cache_trailing_buy_events_key) ?: 0;
            $cache_traling_sell_events_value = Yii::$app->cache->get($cache_trailing_sell_events_key) ?: 0;
            $cache_traling_stop_loss_events_value = Yii::$app->cache->get($cache_trailing_stop_loss_events_key) ?: 0;
            $cache_traling_stop_loss_price_value = Yii::$app->cache->get($cache_trailing_stop_loss_price_key) ?: 0;

            $cache_stop_loss_price_reached_value = Yii::$app->cache->get($cache_stop_loss_price_reached_key) ?: false;

            $buy_step_reached = ($cache_trailing_count_value >= $buy_step);
            $sell_step_reached = ($portfolio_lots > 1);

            $place_buy_order = false;
            $place_sell_order = false;

            $sensitivity_buy_price = $cache_trailing_buy_price_value * (1 + $buy_trailing_sensitivity / 100);
            $sensitivity_sell_price = $cache_trailing_sell_price_value * (1 - $sell_trailing_sensitivity / 100);

            if ($buy_step_reached) {
                if ($current_buy_price_decimal >= $sensitivity_buy_price) {
                    $cache_traling_buy_events_value++;

                    if ($cache_traling_buy_events_value >= 3) {
                        $place_buy_order = true;
                        $cache_traling_buy_events_value = 0;
                    }
                } else {
                    $cache_traling_buy_events_value = 0;
                }
            } else {
                $cache_traling_buy_events_value = 0;
            }

            $sell_by_stop_loss = false;

            if ($sell_step_reached) {
                if ($current_sell_price_decimal * $target_instrument->getLot() > $portfolio_lot_price_decimal * (1 + $expected_yield / 100) && $current_sell_price_decimal <= $sensitivity_sell_price) {
                    $cache_traling_sell_events_value++;

                    if ($cache_traling_sell_events_value >= 2) {
                        $place_sell_order = true;
                        $cache_traling_sell_events_value = 0;
                    }
                } else {
                    $cache_traling_sell_events_value = 0;

                }

                if (!$place_sell_order) {
                    if ($current_sell_price_decimal * $target_instrument->getLot() < $portfolio_lot_price_decimal * (1 - $stop_loss / 100)) {
                        if (!$cache_traling_stop_loss_price_value || ($cache_traling_stop_loss_price_value > $current_sell_price_decimal * $target_instrument->getLot())) {
                            $cache_traling_stop_loss_price_value = $current_sell_price_decimal * $target_instrument->getLot();
                            $cache_traling_stop_loss_events_value++;
                        } else {
                            $cache_traling_stop_loss_price_value = 0;
                            $cache_traling_stop_loss_events_value = 0;
                        }
                    } else {
                        $cache_traling_stop_loss_price_value = 0;
                        $cache_traling_stop_loss_events_value = 0;
                    }

                    if ($cache_traling_stop_loss_events_value >= 3) {
                        $place_sell_order = true;
                        $cache_traling_stop_loss_price_value = 0;
                        $cache_traling_stop_loss_events_value = 0;

                        $sell_by_stop_loss = true;
                    }
                }
            } else {
                $cache_traling_sell_events_value = 0;
            }

            Yii::$app->cache->set($cache_trailing_buy_events_key, $cache_traling_buy_events_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
            Yii::$app->cache->set($cache_trailing_sell_events_key, $cache_traling_sell_events_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
            Yii::$app->cache->set($cache_trailing_stop_loss_events_key, $cache_traling_stop_loss_events_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
            Yii::$app->cache->set($cache_trailing_stop_loss_price_key, $cache_traling_stop_loss_price_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);

            $final_day_sell_action = false;

            if (!$place_sell_order && $day_finalization_yield && $sell_step_reached) {
                if (!$this->isValidTradingPeriod(null, null, $trade_finalization_h, $trade_finalization_m)) {
                    if ($current_sell_price_decimal * $target_instrument->getLot() >= $portfolio_lot_price_decimal * (1 + $day_finalization_yield / 100)) {
                        echo 'Форсируем продажу для завершения торгового дня на объем ' . (max(0, $portfolio_lots - 1)) . ' лотов' . PHP_EOL;

                        $place_sell_order = true;
                        $final_day_sell_action = true;
                    }
                }
            }

            $stop_loss_sell_action = false;

            if ($cache_stop_loss_price_reached_value && $stop_loss_yield && $sell_step_reached) {
                if (
                    $current_sell_price_decimal * $target_instrument->getLot() > $portfolio_lot_price_decimal &&
                    $current_sell_price_decimal * $target_instrument->getLot() <= $portfolio_lot_price_decimal * (1 + $stop_loss_yield / 100)
                ) {
                    echo 'Форсируем продажу по STOP LOSS на объем ' . (max(0, $portfolio_lots - 1)) . ' лотов' . PHP_EOL;

                    $place_sell_order = true;
                    $stop_loss_sell_action = true;
                }
            }

            echo 'Данные к расчету: ' . Log::logSerialize([
                    'Стакан' => '[' . $current_sell_price_decimal . ' - ' . $current_buy_price_decimal . ']',

                    'Параметры' => static::TRADE_ETF_STRATEGY[$account_shortcut],

                    'Покупка' => [
                        'buy_step' => $buy_step,
                        'current_buy_price' => $current_buy_price_decimal,
                        'sensitivity_buy_price' => $sensitivity_buy_price,
                        'trailing_price' => $cache_trailing_buy_price_value,
                        'buy_step_reached' => $buy_step_reached,
                        'place_buy_order_action' => $place_buy_order,
                        'buy_stability' => $cache_traling_buy_events_value,
                    ],

                    'Продажа' => [
                        'Портфель' => [
                            'portfolio_count' => $portfolio_count,
                            'portfolio_lots' => $portfolio_lots,
                            'portfolio_lot_price_decimal' => $portfolio_lot_price_decimal,
                            'yield' => $portfolio_lots > 0 ? $current_sell_price_decimal * $target_instrument->getLot() - $portfolio_lot_price_decimal : ' - ',
                        ],

                        'current_sell_price' => $current_sell_price_decimal,
                        'sensitivity_sell_price' => $sensitivity_sell_price,
                        'trailing_price' => $cache_trailing_sell_price_value,
                        'sell_step_reached' => $sell_step_reached,
                        'place_sell_order_action' => $place_sell_order,
                        'sell_stability' => $cache_traling_sell_events_value,

                        'final_day_sell_action' => $final_day_sell_action,
                        'stop_loss_sell_action' => $stop_loss_sell_action,
                    ],

                    'Стоп Лосс' => $stop_loss ? [
                        'current_lot_sell_price' => $current_sell_price_decimal * $target_instrument->getLot(),
                        'portfolio_lot_price_decimal' => $portfolio_lot_price_decimal,
                        'stop_loss' => '-' . $stop_loss . '%',
                        'stop_loss_sell_price' => $portfolio_lot_price_decimal * (1 - $stop_loss / 100),
                        'stop_loss_stability' => $cache_traling_stop_loss_events_value,
                        'sell_by_stop_loss' => $sell_by_stop_loss,
                    ] : 'inactive',
                ]) . PHP_EOL
            ;

            if ($place_sell_order && ($lots_to_sell = max(0, $portfolio_lots - 1)) > 0) {
                echo 'Событие продажи. Попытаемся продать ' . $lots_to_sell . ' лотов' . PHP_EOL;

                $post_order_request = new PostOrderRequest();
                $post_order_request->setFigi($target_instrument->getFigi());
                $post_order_request->setQuantity($lots_to_sell);
                $post_order_request->setPrice($current_sell_price);
                $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_SELL);
                $post_order_request->setAccountId($account_id);
                $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

                $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

                $post_order_request->setOrderId($order_id);

                /** @var PostOrderResponse $response */
                list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
                $this->processRequestStatus($status);

                if (!$response) {
                    echo 'Ошибка отправки торговой заявки' . PHP_EOL;

                    return;
                }

                echo 'Заявка с идентификатором ' . $response->getOrderId() . ' отправлена' . PHP_EOL;

                $cache_trailing_count_value = 0;

                Yii::$app->cache->set($cache_trailing_count_key, 0, 6 * DateTimeHelper::SECONDS_IN_HOUR);

                $cache_trailing_sell_price_value = $current_sell_price_decimal;
                $cache_stop_loss_price_reached_value = false;
            } elseif ($place_buy_order) {
                echo 'Событие покупки. Попытаемся купить ' . $cache_trailing_count_value . ' лотов' . PHP_EOL;

                $post_order_request = new PostOrderRequest();
                $post_order_request->setFigi($target_instrument->getFigi());
                $post_order_request->setQuantity($cache_trailing_count_value);
                $post_order_request->setPrice($current_buy_price);
                $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_BUY);
                $post_order_request->setAccountId($account_id);
                $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

                $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

                $post_order_request->setOrderId($order_id);

                /** @var PostOrderResponse $response */
                list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
                $this->processRequestStatus($status);

                if (!$response) {
                    echo 'Ошибка отправки торговой заявки' . PHP_EOL;

                    return;
                }

                echo 'Заявка с идентификатором ' . $response->getOrderId() . ' отправлена' . PHP_EOL;

                Yii::$app->cache->set($cache_trailing_count_key, 0, 6 * DateTimeHelper::SECONDS_IN_HOUR);

                $cache_trailing_count_value = 0;
                $cache_trailing_buy_price_value = $current_buy_price_decimal;
                $cache_stop_loss_price_reached_value = false;
            } else {
                if ($buy_step_reached) {
                    echo 'Событие покупки не наступило, цена не достигнута' . PHP_EOL;

                    $cache_trailing_buy_price_value = min($cache_trailing_buy_price_value, $current_buy_price_decimal);
                } else {
                    echo 'Событие покупки не наступило, мало накоплено' . PHP_EOL;

                    $cache_trailing_buy_price_value = $current_buy_price_decimal;
                }

                if ($sell_step_reached) {
                    echo 'Событие продажи не наступило, цена не достигнута' . PHP_EOL;

                    $cache_trailing_sell_price_value = max($cache_trailing_sell_price_value, $current_sell_price_decimal);

                    if ($current_sell_price_decimal * $target_instrument->getLot() > $portfolio_lot_price_decimal * (1 + $stop_loss_yield / 100)) {
                        $cache_stop_loss_price_reached_value = true;
                    }
                } else {
                    echo 'Событие продажи не наступило, в портфеле мало накоплено' . PHP_EOL;

                    $cache_trailing_sell_price_value = $current_sell_price_decimal;
                }
            }

            echo 'Помещаем в кэш: ' . Log::logSerialize([
                    'cache_trailing_count_value' => $cache_trailing_count_value,
                    'cache_trailing_buy_price_value' => $cache_trailing_buy_price_value,
                    'cache_trailing_sell_price_value' => $cache_trailing_sell_price_value,
                    'cache_stop_loss_price_reached_value' => $cache_stop_loss_price_reached_value,
                ]) . PHP_EOL
            ;

            Yii::$app->cache->set($cache_trailing_buy_price_key, $cache_trailing_buy_price_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
            Yii::$app->cache->set($cache_trailing_sell_price_key, $cache_trailing_sell_price_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
            Yii::$app->cache->set($cache_stop_loss_price_reached_key, $cache_stop_loss_price_reached_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;
            echo 'Ошибка: ' . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), $log_target);
        }

        $stdout_data = ob_get_contents();
        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, $log_target);

            echo $stdout_data;
        }
    }

    public function actionFullTradeEtfTrailing(string $account_shortcut): void
    {
        if (!static::TRADE_ETF_STRATEGY[$account_shortcut]['ACTIVE'] ?? false) {
            return;
        }

        if (!$account_id = Yii::$app->params['tinkoff_invest']['account_shortcuts'][$account_shortcut] ?? false) {
            return;
        }

        $figi = static::TRADE_ETF_STRATEGY[$account_shortcut]['ETF'];

        $buy_trailing_sensitivity = static::TRADE_ETF_STRATEGY[$account_shortcut]['BUY_TRAILING_PERCENTAGE'];
        $sell_trailing_sensitivity = static::TRADE_ETF_STRATEGY[$account_shortcut]['SELL_TRAILING_PERCENTAGE'];

        $log_target = static::TRADE_ETF_STRATEGY[$account_shortcut]['LOG_TARGET'] ?? static::TRADE_STRATEGY_LOG_TARGET;

        $trade_start_h = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_START_H'] ?? 20;
        $trade_start_m = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_START_M'] ?? 30;
        $trade_end_h = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_END_H'] ?? 22;
        $trade_end_m = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_END_M'] ?? 45;
        $trade_finalization_h = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_FINALIZATION_H'] ?? 22;
        $trade_finalization_m = static::TRADE_ETF_STRATEGY[$account_shortcut]['TRADE_FINALIZATION_M'] ?? 40;

        Log::info('Start action ' . __FUNCTION__, $log_target);

        ob_start();

        if ($account_shortcut === 'account2') {
            if (
                !$this->isValidTradingPeriod(14, 0, 23, 59) &&
                !$this->isValidTradingPeriod(0, 0, 3, 45)
            ) {
                return;
            }
        } else {
            if (!$this->isValidTradingPeriod($trade_start_h, $trade_start_m, $trade_end_h, $trade_end_m)) {
                return;
            }
        }

        try {
            $tinkoff_api = Yii::$app->tinkoffInvest;
            $tinkoff_instruments = InstrumentsProvider::create($tinkoff_api);

            echo 'Ищем ETF инструмент' . PHP_EOL;

            $target_instrument = $tinkoff_instruments->etfByFigi($figi);

            $min_increment = $target_instrument->getMinPriceIncrement();

            if (!$min_increment) {
                echo 'Ошибка получения min_increment' . PHP_EOL;

                throw new Exception('Ошибка');
            }

            $min_increment_float = QuotationHelper::toDecimal($min_increment);

            echo 'Инструмент найден' . PHP_EOL;

            if ($target_instrument->getBuyAvailableFlag()) {
                echo 'Покупка доступна' . PHP_EOL;
            } else {
                echo 'Покупка не доступна' . PHP_EOL;

                throw new Exception('Ошибка');
            }

            $trading_status = $target_instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING) {
                echo 'Не подходящий Trading Status: ' . SecurityTradingStatus::name($trading_status) . PHP_EOL;

                throw new Exception('Ошибка');
            }

            echo 'Получаем портфель' . PHP_EOL;

            $request = new PortfolioRequest();
            $request->setAccountId($account_id);

            /**
             * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
             */
            list($response, $status) = $tinkoff_api->operationsServiceClient->GetPortfolio($request)->wait();
            $this->processRequestStatus($status, true);

            $positions = $response->getPositions();

            echo 'Available portfolio positions: ' . PHP_EOL;

            $portfolio_position = null;

            /** @var PortfolioPosition $position */
            foreach ($positions as $position) {
                if ($position->getFigi() === $target_instrument->getFigi()) {
                    $portfolio_position = $position;

                    break;
                }
            }

            if ($portfolio_position) {
                $average_position_price_fifo = $portfolio_position->getAveragePositionPriceFifo();
                $average_position_price_fifo_decimal = $average_position_price_fifo ? QuotationHelper::toDecimal($average_position_price_fifo) : 0;

                $portfolio_lots = (int) $portfolio_position->getQuantityLots()->getUnits();
                $portfolio_count = QuotationHelper::toDecimal($position->getQuantity());
                $portfolio_lot_price_decimal = $average_position_price_fifo_decimal * $target_instrument->getLot();
            } else {
                $portfolio_lots = 0;
                $portfolio_count = 0;
                $portfolio_lot_price_decimal = 0;
            }

            echo 'В портфеле лотов: ' . $portfolio_lots . ', Количество: ' . $portfolio_count . PHP_EOL;
            echo 'Средняя цена лота: ' . $portfolio_lot_price_decimal . PHP_EOL;

            echo 'Получаем информацию о доступных остатках' . PHP_EOL;

            $request = new WithdrawLimitsRequest();
            $request->setAccountId($account_id);

            /**
             * @var WithdrawLimitsResponse $response - Получаем ответ, содержащий информацию о доступных остатках
             */
            list($response, $status) = $tinkoff_api->operationsServiceClient->GetWithdrawLimits($request)->wait();
            $this->processRequestStatus($status, true);

            $money_array = $response->getMoney();
            $blocked_array = $response->getBlocked();

            $money_float = 0;
            $blocked_float = 0;

            /** @var MoneyValue $money */
            foreach ($money_array as $money) {
                if ($money->getCurrency() === $target_instrument->getCurrency()) {
                    $money_float = QuotationHelper::toDecimal($money);

                    break;
                }
            }

            /** @var MoneyValue $money */
            foreach ($blocked_array as $money) {
                if ($money->getCurrency() === $target_instrument->getCurrency()) {
                    $blocked_float = QuotationHelper::toDecimal($money);

                    break;
                }
            }

            $available_money = abs($money_float - $blocked_float);

            echo 'В портфеле денег: ' . $money_float . ' из них заблокировано: ' . $blocked_float . '. Доступный остаток: ' . $available_money . PHP_EOL;
            echo 'Получаем стакан' . PHP_EOL;

            $orderbook_depth_control = 3;

            $orderbook_request = new GetOrderBookRequest();
            $orderbook_request->setDepth($orderbook_depth_control);
            $orderbook_request->setFigi($target_instrument->getFigi());

            /** @var GetOrderBookResponse $response */
            list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
            $this->processRequestStatus($status);

            if (!$response) {
                echo 'Ошибка получения стакана заявок' . PHP_EOL;

                throw new Exception('Ошибка');
            } else {
                echo 'Стакан:' . $response->serializeToJsonString() . PHP_EOL;
            }

            /** @var RepeatedField|Order[] $asks */
            $asks = $response->getAsks();

            /** @var RepeatedField|Order[] $bids */
            $bids = $response->getBids();

            if ($asks->count() === 0 || $bids->count() === 0) {
                echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

                throw new Exception('Ошибка');
            }

            $top_ask_price = $asks[0]->getPrice();
            $top_bid_price = $bids[0]->getPrice();

            $current_buy_price = $top_ask_price;
            $current_buy_price_decimal = QuotationHelper::toDecimal($current_buy_price);

            $available_to_buy_in_orderbook = (int) $asks[0]->getQuantity();

            $orderbook_ready_to_sell = 0;
            $orderbook_ready_to_buy = 0;

            $direction_to_buy = false;
            $direction_to_sell = false;

            for ($dp = 0; $dp < $orderbook_depth_control; $dp++) {
                $orderbook_ready_to_sell += !empty($asks[$dp]) ? (int) $asks[$dp]->getQuantity() : 0;
                $orderbook_ready_to_buy += !empty($bids[$dp]) ? (int) $bids[$dp]->getQuantity() : 0;
            }

            if ($orderbook_ready_to_buy > 1.05 * $orderbook_ready_to_sell) {
                $direction_to_buy = true;
            }  elseif ($orderbook_ready_to_sell > 2 * $orderbook_ready_to_buy) {
                $direction_to_sell = true;
            }

            $current_sell_price = $top_bid_price;
            $current_sell_price_decimal = QuotationHelper::toDecimal($current_sell_price);

            if (abs($current_buy_price_decimal - $current_sell_price_decimal) > 1.1 * $min_increment_float) {
                echo 'Слишком большой спред в стакане (' . ($current_buy_price_decimal - $current_sell_price_decimal) . '). Подождем.' . PHP_EOL;

                throw new Exception('Ошибка');
            } else {
                echo 'Спред в стакане в норме' . PHP_EOL;
            }

            $cache_trailing_buy_events_key = $account_shortcut . '@F2TRetf@' . $figi . '_buy_events';
            $cache_trailing_sell_events_key = $account_shortcut . '@F2TRetf@' . $figi . '_sell_events';

            $cache_trailing_buy_preprice_key = $account_shortcut . '@F2TRetf@' . $figi . '_sell_preprice';

            $cache_trailing_buy_price_key = $account_shortcut . '@F2TRetf@' . $figi . '_buy_price';
            $cache_trailing_sell_price_key = $account_shortcut . '@F2TRetf@' . $figi . '_sell_price';

            $cache_trailing_buy_price_value = Yii::$app->cache->get($cache_trailing_buy_price_key) ?: $current_buy_price_decimal;
            $was_cache_trailing_buy_price_value = $cache_trailing_buy_price_value;

            $cache_trailing_sell_price_value = Yii::$app->cache->get($cache_trailing_sell_price_key) ?: $current_sell_price_decimal;

            $cache_traling_buy_events_value = Yii::$app->cache->get($cache_trailing_buy_events_key) ?: 0;
            $cache_traling_sell_events_value = Yii::$app->cache->get($cache_trailing_sell_events_key) ?: 0;
            $cache_traling_buy_preprice_value = Yii::$app->cache->get($cache_trailing_buy_preprice_key) ?: 0;

            $buy_step_reached = ($available_money >= 10 * $current_buy_price_decimal);
            $sell_step_reached = ($portfolio_lots > 1);

            $place_buy_order = false;
            $place_sell_order = false;

            $sensitivity_buy_price = $cache_trailing_buy_price_value * (1 + $buy_trailing_sensitivity / 100);
            $sensitivity_sell_price = $cache_trailing_sell_price_value * (1 - $sell_trailing_sensitivity / 100);

            if ($buy_step_reached && $direction_to_buy) {
                if ($current_buy_price_decimal >= $sensitivity_buy_price && ($current_buy_price_decimal >= $cache_traling_buy_preprice_value)) {
                    $cache_traling_buy_events_value++;

                    if ($cache_traling_buy_events_value >= 3) {
                        $place_buy_order = true;
                        $cache_traling_buy_events_value = 0;
                    }
                } else {
                    $cache_traling_buy_events_value = 0;
                }
            } else {
                $cache_traling_buy_events_value = 0;
            }

            $cache_traling_buy_preprice_value = $current_buy_price_decimal;

            if ($sell_step_reached) {
                if ($current_sell_price_decimal <= $sensitivity_sell_price || $direction_to_sell) {
                    $cache_traling_sell_events_value++;

                    if ($cache_traling_sell_events_value >= 3) {
                        $place_sell_order = true;
                        $cache_traling_sell_events_value = 0;
                    }
                } else {
                    $cache_traling_sell_events_value = 0;
                }
            } else {
                $cache_traling_sell_events_value = 0;
            }

            if ($account_shortcut === 'account2' &&
                $sell_step_reached &&
                $this->isValidTradingPeriod(3, 40, 3, 45)
            ) {
                echo 'Форсируем продажу, финализируя торговый день' . PHP_EOL;

                $place_sell_order = true;
                $cache_traling_sell_events_value = 0;
            }

            Yii::$app->cache->set($cache_trailing_buy_events_key, $cache_traling_buy_events_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
            Yii::$app->cache->set($cache_trailing_sell_events_key, $cache_traling_sell_events_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
            Yii::$app->cache->set($cache_trailing_buy_preprice_key, $cache_traling_buy_preprice_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);

            echo 'Данные к расчету: ' . Log::logSerialize([
                    'Параметры' => static::TRADE_ETF_STRATEGY[$account_shortcut],

                    'Стакан' => '[' . $current_sell_price_decimal . ' - ' . $current_buy_price_decimal . ']',

                    'Направление в стакане' => [
                        'orderbook_ready_to_buy' => $orderbook_ready_to_buy,
                        'orderbook_ready_to_sell' => $orderbook_ready_to_sell,
                        'direction_to_buy' => $direction_to_buy,
                        'direction_to_sell' => $direction_to_sell,
                    ],

                    'Покупка' => [
                        'current_buy_price' => $current_buy_price_decimal,
                        'sensitivity_buy_price' => $sensitivity_buy_price,
                        'trailing_price' => $cache_trailing_buy_price_value,
                        'buy_step_reached' => $buy_step_reached,
                        'place_buy_order_action' => $place_buy_order,
                        'buy_stability' => $cache_traling_buy_events_value,
                    ],

                    'Продажа' => [
                        'Портфель' => [
                            'portfolio_count' => $portfolio_count,
                            'portfolio_lots' => $portfolio_lots,
                            'portfolio_lot_price_decimal' => $portfolio_lot_price_decimal,
                            'yield' => $portfolio_lots > 0 ? $current_sell_price_decimal * $target_instrument->getLot() - $portfolio_lot_price_decimal : ' - ',
                        ],

                        'current_sell_price' => $current_sell_price_decimal,
                        'sensitivity_sell_price' => $sensitivity_sell_price,
                        'trailing_price' => $cache_trailing_sell_price_value,
                        'sell_step_reached' => $sell_step_reached,
                        'place_sell_order_action' => $place_sell_order,
                        'sell_stability' => $cache_traling_sell_events_value,
                    ],
                ]) . PHP_EOL
            ;

            if (
                $place_sell_order && ($lots_to_sell = max(0, $portfolio_lots - 1)) > 0
            ) {
                echo 'Событие продажи. Попытаемся продать ' . $lots_to_sell . ' лотов' . PHP_EOL;

                $post_order_request = new PostOrderRequest();
                $post_order_request->setFigi($target_instrument->getFigi());
                $post_order_request->setQuantity($lots_to_sell);
                $post_order_request->setPrice($current_sell_price);
                $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_SELL);
                $post_order_request->setAccountId($account_id);
                $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

                $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

                $post_order_request->setOrderId($order_id);

                /** @var PostOrderResponse $response */
                list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
                $this->processRequestStatus($status);

                if (!$response) {
                    echo 'Ошибка отправки торговой заявки' . PHP_EOL;

                    return;
                }

                echo 'Заявка с идентификатором ' . $response->getOrderId() . ' отправлена' . PHP_EOL;

                $cache_trailing_buy_price_value = $current_buy_price_decimal;
                $cache_trailing_sell_price_value = $current_sell_price_decimal;
            } elseif (
                $place_buy_order &&
                ($count_to_buy = min(floor($available_money / $current_buy_price_decimal), $available_to_buy_in_orderbook)) > 0
            ) {
                echo 'Событие покупки. Попытаемся купить ' . $count_to_buy . ' лотов' . PHP_EOL;

                $post_order_request = new PostOrderRequest();
                $post_order_request->setFigi($target_instrument->getFigi());
                $post_order_request->setQuantity($count_to_buy);
                $post_order_request->setPrice($current_buy_price);
                $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_BUY);
                $post_order_request->setAccountId($account_id);
                $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

                $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

                $post_order_request->setOrderId($order_id);

                /** @var PostOrderResponse $response */
                list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
                $this->processRequestStatus($status);

                if (!$response) {
                    echo 'Ошибка отправки торговой заявки' . PHP_EOL;

                    return;
                }

                echo 'Заявка с идентификатором ' . $response->getOrderId() . ' отправлена' . PHP_EOL;

                $cache_trailing_buy_price_value = $current_buy_price_decimal;
                $cache_trailing_sell_price_value = $current_sell_price_decimal;
            } else {
                if ($buy_step_reached) {
                    $cache_trailing_buy_price_value = min($cache_trailing_buy_price_value, $current_buy_price_decimal);

                    echo 'Возможность покупки [+]' . PHP_EOL;

                    if ($direction_to_buy) {
                        echo 'Направление к покупке [+]' . PHP_EOL;
                        echo 'Достигнута стабилизированная цена покупки [-]' . PHP_EOL;
                    } else {
                        echo 'Направление к покупке [-]' . PHP_EOL;
                    }
                } else {
                    $cache_trailing_buy_price_value = $current_buy_price_decimal;

                    echo 'Возможность покупки [-]' . PHP_EOL;
                }

                if ($sell_step_reached) {
                    $cache_trailing_sell_price_value = max($cache_trailing_sell_price_value, $current_sell_price_decimal);

                    echo 'Возможность продажи [+]' . PHP_EOL;

                    if ($direction_to_sell) {
                        echo 'Направление к продаже [+]' . PHP_EOL;
                        echo 'Достигнута стабилизированная цена продажи [-]' . PHP_EOL;
                    } else {
                        echo 'Направление к продаже [-]' . PHP_EOL;
                    }
                } else {
                    $cache_trailing_sell_price_value = $current_sell_price_decimal;

                    echo 'Возможность продажи [-]' .
                        PHP_EOL;
                }
            }

            echo 'Помещаем в кэш: ' . Log::logSerialize([
                    'cache_trailing_buy_price_value' => $cache_trailing_buy_price_value,
                    'cache_trailing_sell_price_value' => $cache_trailing_sell_price_value,
                ]) . PHP_EOL
            ;

            if ($cache_trailing_buy_price_value !== $was_cache_trailing_buy_price_value) {
                Yii::$app->cache->set($cache_trailing_buy_price_key, $cache_trailing_buy_price_value, 2 * DateTimeHelper::SECONDS_IN_HOUR);
            }

            Yii::$app->cache->set($cache_trailing_sell_price_key, $cache_trailing_sell_price_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;
            echo 'Ошибка: ' . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), $log_target);
        }

        $stdout_data = ob_get_contents();
        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, $log_target);

            echo $stdout_data;
        }
    }

    /**
     * Метод выводит в stdout информацию о пополнениях указанного счета
     *
     * Метод выводит информацию с разбивкой по годам. Дополнительно за последний год запрашивается информация помесячно.
     *
     * @param string $account_id Идентификатор аккаунта/портфеля
     * @param int $from_year Начиная с какого года формировать статистику пополнений
     *
     * @return void
     *
     * TODO: Метод за каждый год/месяц делает отдельный API запрос, гораздо более разумно в реальном проекте оптимизировать этот кусок и
     *       запрашивать данные "большими" периодами с меньшим количеством запросов к API, а затем разбирать ответ по годам/месяцам.
     *       Для демонстрации функционала и моих нужд с такой "избыточностью" запросов к API было проще и быстрее.
     */
    public function actionFunding(string $account_id, int $from_year = 2021): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        ob_start();

        try {
            $tinkoff_api = Yii::$app->tinkoffInvest;

            echo 'Запрашиваем информацию о пополнениях счета ' . $account_id . PHP_EOL;

            $current_year = (int) date('Y');
            $current_month = (int) date('m');

            $total = 0;
            $bot_message = '';

            for ($year = $from_year; $year <= $current_year; $year++) {
                $request = new OperationsRequest();

                $request->setAccountId($account_id);
                $request->setFrom((new Timestamp())->setSeconds(strtotime($year . "-01-01 00:00:00")));
                $request->setTo((new Timestamp())->setSeconds(strtotime($year . "-12-31 23:59:59")));
                $request->setState(OperationState::OPERATION_STATE_EXECUTED);

                list($reply, $status) = $tinkoff_api->operationsServiceClient->GetOperations($request)
                    ->wait();

                $this->processRequestStatus($status, true);

                $sum = 0;

                /** @var OperationsResponse $reply */
                /** @var Operation $operation */
                foreach ($reply->getOperations() as $operation) {
                    if ($operation->getOperationType() === OperationType::OPERATION_TYPE_INPUT) {
                        $sum += QuotationHelper::toDecimal($operation->getPayment());
                    }
                }

                $bot_message .= '[' . $year . ' год] => ' . $sum . ' руб.' . PHP_EOL;

                $total += $sum;
            }

            $bot_message .= PHP_EOL . '[Всего] => ' . $total . ' руб.' . PHP_EOL . PHP_EOL;

            echo $bot_message;

            echo 'Разбивка по месяцам текущего года:' . PHP_EOL;

            $bot_message = '';

            for ($month = 1; $month <= $current_month; $month++) {
                $request = new OperationsRequest();

                $request->setAccountId($account_id);
                $request->setFrom((new Timestamp())->setSeconds(strtotime($current_year . "-" . ($month < 10 ? '0' : '') . $month . "-01 00:00:00")));

                if ($month === 12) {
                    $request->setTo((new Timestamp())->setSeconds(strtotime(($current_year + 1) . "-01-01 00:00:00")));
                } else {
                    $request->setTo((new Timestamp())->setSeconds(strtotime($current_year . "-" . ($month + 1 < 10 ? '0' : '') . ($month + 1) . "-01 00:00:00")));
                }

                $request->setState(OperationState::OPERATION_STATE_EXECUTED);

                list($reply, $status) = $tinkoff_api->operationsServiceClient->GetOperations($request)
                    ->wait();

                $this->processRequestStatus($status, true);

                $sum = 0;

                /** @var OperationsResponse $reply */
                /** @var Operation $operation */
                foreach ($reply->getOperations() as $operation) {
                    if ($operation->getOperationType() === OperationType::OPERATION_TYPE_INPUT) {
                        $sum += QuotationHelper::toDecimal($operation->getPayment());
                    }
                }

                $bot_message .= DateTime::createFromFormat('!m', $month)
                        ->format('F') . ' -> ' . $sum . ' руб.' . PHP_EOL;
            }

            echo $bot_message;
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        $stdout_data = ob_get_contents();

        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, static::MAIN_LOG_TARGET);

            echo $stdout_data;
        }
    }

    /**
     * Метод демонстрирует возможность подписаться на стрим MarketDataStream для получения событий по свечам для инструмента с переданным тикером
     *
     * Получаются и выводятся в stdout события по минутным интервалам
     *
     * @param string $ticker Тикер инструмента для получения свечей
     *
     * @return void
     */
    public function actionCandlesStream(string $ticker): void
    {
        /**
         * Информация: Этот функционал является простейшей демонстрацией. PHP на самом деле не очень подходит для обработки стримов,
         * контроля статусов стрима и пр., поскольку вызов <code>$stream->read()</code> является блокирующим. Поэтому обработка происходит в бесконечном цикле и
         * в консоли прерывается по Ctrl+C / Cmd+C
         *
         * @see https://github.com/grpc/grpc/issues/12017
         * @see https://github.com/prooph/event-store-client/issues/114
         */
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        try {
            $tinkoff_api = Yii::$app->tinkoffInvest;
            $tinkoff_instruments = InstrumentsProvider::create($tinkoff_api);

            echo 'Ищем инструмент с тикером ' . $ticker . PHP_EOL;

            $target_instrument = $tinkoff_instruments->searchByTicker($ticker);

            echo 'Инструмент найден: ' . $target_instrument->serializeToJsonString() . PHP_EOL;

            if ($target_instrument->getBuyAvailableFlag()) {
                echo 'Покупка доступна' . PHP_EOL;
            } else {
                echo 'Покупка сейчас не доступна' . PHP_EOL;

                return;
            }

            $trading_status = $target_instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING) {
                echo 'Не подходящий Trading Status: ' . SecurityTradingStatus::name($trading_status) . PHP_EOL;

                return;
            }

            /**
             * Создаем подписку на данные {@link MarketDataRequest}, конкретно по {@link SubscribeOrderBookRequest} по FIGI инструмента META/FB
             */
            $subscription = (new MarketDataRequest())
                ->setSubscribeLastPriceRequest(
                    (new SubscribeLastPriceRequest())
                        ->setSubscriptionAction(SubscriptionAction::SUBSCRIPTION_ACTION_SUBSCRIBE)
                        ->setInstruments([
                            (new LastPriceInstrument())
                                ->setFigi($target_instrument->getFigi())
                        ])
                )
                ->setSubscribeCandlesRequest(
                    (new SubscribeCandlesRequest())
                        ->setSubscriptionAction(SubscriptionAction::SUBSCRIPTION_ACTION_SUBSCRIBE)
                        ->setInstruments([
                            (new CandleInstrument())
                                ->setFigi($target_instrument->getFigi())
                                ->setInterval(SubscriptionInterval::SUBSCRIPTION_INTERVAL_ONE_MINUTE)
                        ])
                )
            ;

            $stream = $tinkoff_api->marketDataStreamServiceClient->MarketDataStream();
            $stream->write($subscription);

            /** @var MarketDataResponse $market_data_response */
            while ($market_data_response = $stream->read()) {
                $last_price = $market_data_response->getLastPrice();
                $candle = $market_data_response->getCandle();
                $ping = $market_data_response->getPing();

                if ($last_price !== null) {
                    echo 'Cобытие стрима "last price": ' . $last_price->serializeToJsonString() . PHP_EOL;
                }

                if ($candle !== null) {
                    echo 'Cобытие стрима "candle": ' . $candle->serializeToJsonString() . PHP_EOL;
                }

                if ($ping!== null) {
                    echo 'Пришел пинг: ' . $ping->serializeToJsonString() . PHP_EOL;
                }
            }

            $stream->cancel();
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }
    }

    public function actionShowEtf(string $ticker): void
    {
        $tinkoff_api = Yii::$app->tinkoffInvest;
        $tinkoff_instruments = InstrumentsProvider::create($tinkoff_api);

        echo 'Ищем ETF инструмент' . PHP_EOL;

        $target_instrument = $tinkoff_instruments->etfByTicker($ticker);

        echo 'Инструмент найден' . PHP_EOL;

        var_dump([
            $target_instrument->getTicker(),
            $target_instrument->getIsin(),
            $target_instrument->getFigi(),
            $target_instrument->getTradingStatus(),
            $target_instrument->getBuyAvailableFlag(),
        ]);

        $instruments_request = new InstrumentsRequest();
        $instruments_request->setInstrumentStatus(InstrumentStatus::INSTRUMENT_STATUS_ALL);

        /** @var EtfsResponse $response */
        list($response, $status) = $tinkoff_api
            ->instrumentsServiceClient
            ->Etfs($instruments_request)
            ->wait()
        ;

        /** @var Etf[]|RepeatedField $instruments */
        $instruments = $response->getInstruments();

        foreach ($instruments as $instrument) {
            if ($instrument->getTicker() === $ticker) {
                echo $instrument->getTicker() . ' -> ' . $instrument->getFigi() . PHP_EOL;
            }
        }
    }

    public function actionBacktest(): void
    {
        $backtest = Yii::$app->params['backtest'] ?? [];

        if (!$backtest) {
            return;
        }

        $tinkoff_api = Yii::$app->tinkoffInvest;
        $tinkoff_instruments = InstrumentsProvider::create($tinkoff_api);

        foreach ($backtest['strategy']['ETF'] ?? [] as $ticker => $ticker_config) {
            echo 'Backtest for ' . $ticker . PHP_EOL;

            echo 'Ищем ETF инструмент' . PHP_EOL;

            $target_instrument = $tinkoff_instruments->etfByTicker($ticker);

            echo 'Инструмент найден' . PHP_EOL;

            $market_data_client = $tinkoff_api->marketDataServiceClient;

            $request = new GetCandlesRequest();

            $request->setFigi($target_instrument->getFigi());
            $request->setInterval(CandleInterval::CANDLE_INTERVAL_1_MIN);

            $date = new DateTime();
            $previous_day = $date->modify('-1 days')->format('Y-m-d');
//          $previous_day = $date->format('Y-m-d');

            echo 'Backtest day: ' . $previous_day . PHP_EOL;

            $request->setFrom((new Timestamp())->setSeconds(strtotime($previous_day . " 00:00:00")));
            $request->setTo((new Timestamp())->setSeconds(strtotime($previous_day . " 23:59:59")));

            list($reply, $status) = $market_data_client->GetCandles($request)->wait();

            $this->processRequestStatus($status, true);

            /** @var GetCandlesResponse $reply */

            $candles = ArrayHelper::repeatedFieldToArray($reply->getCandles());

            if (!count($candles)) {
                echo 'Candles list empty. Backtest canceled' . PHP_EOL;

                break;
            }

            $m_v = null;
            $m_bs = null;
            $m_ts = null;

            for ($buy_step = 5; $buy_step <= 5; $buy_step++ ) {
                for ($trailing_sensitivity = 0.03; $trailing_sensitivity <= 0.15; $trailing_sensitivity += 0.01) {
                    $operations = [];

                    $minutes = 0;

                    $cache_trailing_count_value = null;
                    $cache_traling_events_value = null;
                    $cache_trailing_price_value = null;

                    /** @var HistoricCandle $candle */
                    foreach ($candles as $candle) {
                        $top_ask_price = $candle->getClose();
                        $current_price = $top_ask_price;
                        $current_price_decimal = QuotationHelper::toDecimal($current_price);

                        $cache_trailing_count_value = $cache_trailing_count_value ?? 0;
                        $cache_trailing_price_value = $cache_trailing_price_value ?? $current_price_decimal;
                        $cache_traling_events_value = $cache_traling_events_value ?? 0;

                        $minutes++;

                        if ($minutes >= $ticker_config['INCREMENT_PERIOD']) {
                            $cache_trailing_count_value++;

                            $minutes = 0;
                        }

                        $buy_step_reached = ($cache_trailing_count_value >= $buy_step);

                        $place_order = false;

                        $sensitivity_price = $cache_trailing_price_value * (1 + $trailing_sensitivity / 100);

                        if ($buy_step_reached) {
                            if ($current_price_decimal >= $sensitivity_price) {
                                $cache_traling_events_value++;

                                if ($cache_traling_events_value >= 3) {
                                    $place_order = true;
                                    $cache_traling_events_value = 0;
                                }
                            } else {
                                $cache_traling_events_value = 0;
                            }
                        } else {
                            $cache_traling_events_value = 0;
                        }

                        if ($place_order) {
                            $operations[] = [$cache_trailing_count_value, $current_price_decimal];

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
                    $lots = 0;
                    $sum = 0;

                    foreach ($operations as $operation) {
                        $lots += $operation[0];
                        $sum += $operation[0] * $operation[1];
                    }

                    $avg = $sum / $lots;

                    if (!$m_v || $m_v > $avg) {
                        $m_bs = $buy_step;
                        $m_v = $avg;
                        $m_ts = $trailing_sensitivity;

                    }

                    echo $buy_step . ' | ' . $trailing_sensitivity . ' -> ' . $lots . ' lots, sum = ' . $sum . ', average_sum = ' . ($sum / $lots) . PHP_EOL;
                }
            }

            echo 'Optimum : ' . $m_bs . ' | ' . $m_ts . ' -> ' . $m_v . PHP_EOL;
        }


    }

    /**
     * Общий метод обработки статуса выполнения запроса.
     *
     * Если все хорошо - метод молчаливо заканчивает свою работу, в случае ошибки будут залогированы детали и брошено исключение
     *
     * @param stdClass|array|null $status Статус выполнения запроса
     * @param bool $echo_to_stdout Флаг необходимости вывести подробности ошибки в stdOut.  По умолчанию равно <code>false</code>
     *
     * @return void
     *
     * @throws Exception
     */
    protected function processRequestStatus($status, bool $echo_to_stdout = false): void
    {
        if (!$status) {
            throw new Exception('Response status is empty');
        }

        /** @var array $status Приводим stdClass к array */
        $status = json_decode(json_encode($status), true);

        $status_code = $status['code'] ?? null;

        if (!$status_code) {
            return;
        }

        $error = [
            'x-tracking-id' => $status['metadata']['x-tracking-id'] ?? null,
            'code' => $status['code'] ?? null,
            'details' => $status['details'] ?? null,
            'message' => $status['metadata']['message'] ?? null,
        ];

        $log_error_message = 'Tinkoff Invest Api Request Error: ' . Log::logSerialize($error);

        Log::error($log_error_message, static::MAIN_LOG_TARGET);

        if ($echo_to_stdout) {
            echo $log_error_message . PHP_EOL;
        }

        throw new Exception('Tinkoff Invest Api Request Error. Check logs for details');
    }

    /**
     * Костыльный вспомогательный метод, который контролирует, наступил ли требуемый временной период в течение торгового дня
     *
     * @param int|null $start_hour Час левой границы временного периода. Если равно <code>false</code> контроль левой границы не осуществляется
     * @param int|null $start_minute Минута левой границы временного периода. Если равно <code>false</code> трактуется как <code>0</code>
     * @param int|null $end_hour Час правой границы временного периода. Если равно <code>false</code> контроль левой границы не осуществляется
     * @param int|null $end_minute Минута правой границы временного периода. Если равно <code>false</code> трактуется как <code>0</code>
     * @param string $date_time_zone Имя временной зоны в которой указано время. По умолчанию равно <code>'Asia/Krasnoyarsk'</code>
     *
     * @return bool Флаг находимся ли мы в текущий момент в требуемом временной период в течение торгового дня
     *
     * @throws Exception
     */
    protected function isValidTradingPeriod(int $start_hour = null, int $start_minute = null, int $end_hour = null, int $end_minute = null, string $date_time_zone = 'Asia/Krasnoyarsk'): bool
    {
        $current_time = new DateTime('now', new DateTimeZone($date_time_zone));

        if (isset($start_hour)) {
            $start_time = new DateTime('now', new DateTimeZone($date_time_zone));
            $start_time->setTime($start_hour, $start_minute ?: 0);

            if ($current_time->getTimestamp() < $start_time->getTimestamp()) {
                return false;
            }
        }

        if (isset($end_hour)) {
            $end_time = new DateTime('now', new DateTimeZone($date_time_zone));
            $end_time->setTime($end_hour, $end_minute ?: 0);

            if ($current_time->getTimestamp() > $end_time->getTimestamp()) {
                return false;
            }
        }

        return true;
    }
}
