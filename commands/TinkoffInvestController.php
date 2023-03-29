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
use Metaseller\TinkoffInvestApi2\exceptions\InstrumentNotFoundException;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use Metaseller\TinkoffInvestApi2\providers\InstrumentsProvider;
use Metaseller\yii2TinkoffInvestApi2\TinkoffInvestApi;
use SonkoDmitry\Yii\TelegramBot\Component as TelegramBotApi;
use stdClass;
use Throwable;
use Tinkoff\Invest\V1\Account;
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
use Tinkoff\Invest\V1\InstrumentsRequest;
use Tinkoff\Invest\V1\InstrumentStatus;
use Tinkoff\Invest\V1\LastPriceInstrument;
use Tinkoff\Invest\V1\MarketDataRequest;
use Tinkoff\Invest\V1\MarketDataResponse;
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

    /**
     * @throws InstrumentNotFoundException
     */
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

    public function actionBuy(string $account_id, string $type, string $ticker, int $lots = 1, float $price = null): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        try {
            echo 'Запрос покупки  ' . $type . ' ' . $ticker . ' на счет ' . $account_id . PHP_EOL;

            $tinkoff_api = Yii::$app->tinkoffInvest;
            $tinkoff_instruments = InstrumentsProvider::create($tinkoff_api);

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

            if ($target_instrument->getBuyAvailableFlag()) {
                echo 'Покупка доступна' . PHP_EOL;
            } else {
                echo 'Покупка не доступна' . PHP_EOL;

                throw new Exception('Impossible to buy');
            }

            $trading_status = $target_instrument->getTradingStatus();

            if ($trading_status !== SecurityTradingStatus::SECURITY_TRADING_STATUS_NORMAL_TRADING) {
                echo 'Не подходящий Trading Status: ' . SecurityTradingStatus::name($trading_status) . PHP_EOL;

                throw new Exception('Impossible to buy');
            }

            echo 'Получаем стакан' . PHP_EOL;

            $orderbook_request = new GetOrderBookRequest();
            $orderbook_request->setDepth(1);
            $orderbook_request->setFigi($target_instrument->getFigi());

            /** @var GetOrderBookResponse $response */
            list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
            $this->processRequestStatus($status, true);

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
            echo 'Попытаемся купить ' . $lots . ' лотов по цене (' . $current_buy_price_decimal . ')' . PHP_EOL;

            $post_order_request = new PostOrderRequest();
            $post_order_request->setFigi($target_instrument->getFigi());
            $post_order_request->setQuantity($lots);
            $post_order_request->setPrice($current_buy_price);
            $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_BUY);
            $post_order_request->setAccountId($account_id);
            $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

            $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

            $post_order_request->setOrderId($order_id);

            /** @var PostOrderResponse $response */
            list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
            $this->processRequestStatus($status, true);

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
     * @throws Exception
     */
    public function actionOperations(string $account_shortcut): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        if (!$account_id = Yii::$app->params['tinkoff_invest']['account_shortcuts'][$account_shortcut] ?? false) {
            return;
        }

        $bot = null;

        $telegram_config = Yii::$app->params['telegram'] ?? [];
        $token = $telegram_config['token'] ?? null;
        $chat_id = $telegram_config['chat_id'] ?? null;

        if ($token && $chat_id) {
            $bot = new TelegramBotApi(['apiToken' => $token]);
        }

        try {
            echo 'Запрос операций по счету ' . $account_id . PHP_EOL;

            $tinkoff_api = Yii::$app->tinkoffInvest;
            $tinkoff_instruments = InstrumentsProvider::create($tinkoff_api);

            $request = new OperationsRequest();
            $request->setAccountId($account_id);

            $from = (new DateTime())->modify('-3 day')->format('Y-m-d');
            $to = (new DateTime())->format('Y-m-d');

            $request = new OperationsRequest();

            $request->setAccountId($account_id);
            $request->setFrom((new Timestamp())->setSeconds(strtotime($from . " 00:00:00")));
            $request->setTo((new Timestamp())->setSeconds(strtotime($to . " 23:59:59")));

            /** @var OperationsResponse $response */
            list($response, $status) = $tinkoff_api->operationsServiceClient->GetOperations($request)->wait();
            $this->processRequestStatus($status, true);

            $operations_to_notify = [];

            /** @var Operation $operation */
            foreach ($response->getOperations() as $operation) {
                if ($operation->getState() === OperationState::OPERATION_STATE_EXECUTED) {
                    $operation_id = $operation->getId();
                    $operation_cache_key = $account_id . '_operation_' . $operation_id . '_processed';

                    $processed = Yii::$app->cache->get($operation_cache_key);

                    if (!$processed) {
                        $operations_to_notify[$operation_cache_key] = $operation;
                    }
                }
            }

            if ($operations_to_notify && $bot) {
                list($response, $status) = $tinkoff_api->usersServiceClient->GetAccounts(new GetAccountsRequest())
                    ->wait()
                ;

                $this->processRequestStatus($status, true);

                $account_name = '-';

                /** @var Account $account */
                /** @var GetAccountsResponse $response */
                foreach ($response->getAccounts() as $account) {
                    if ($account->getId() === $account_id) {
                        $account_name = $account->getName();

                        break;
                    }
                }

                $operations_to_notify = array_reverse($operations_to_notify, true);

                foreach ($operations_to_notify as $operation_cache_key => $operation) {
                    $prefix = '\[`' . $account_name . '`]';

                    $instrument = null;

                    $instrument_type = '-';

                    if ($figi = $operation->getFigi()) {
                        switch ($operation->getInstrumentType()) {
                            case 'bond':
                                $instrument = $tinkoff_instruments->bondByFigi($figi);
                                $instrument_type = 'Облигация';

                                break;
                            case 'share':
                                $instrument = $tinkoff_instruments->shareByFigi($figi);
                                $instrument_type = 'Акция';

                                break;
                            case 'etf':
                                $instrument = $tinkoff_instruments->etfByFigi($figi);
                                $instrument_type = 'Фонд';

                                break;
                        }
                    }

                    if ($instrument) {
                        $prefix .= '\[`' . $instrument_type . '`]\[`' . $instrument->getTicker() . '`]\[`' . static::escapeMarkdown($instrument->getName()) . '`]';
                    }

                    $message = $prefix . ' _' . static::escapeMarkdown($operation->getType()) . '_';

                    if ($payment = $operation->getPayment()) {
                        $price = QuotationHelper::toDecimal($payment);

                        $message .= ' `' . static::escapeMarkdown($price . ' ' . $operation->getCurrency()) . '`';
                    }

                    if ($bot->sendMessage($chat_id, $message, 'Markdown')) {
                        Yii::$app->cache->set($operation_cache_key, 1, 5 * DateTimeHelper::SECONDS_IN_DAY);
                    }
                }
            }

        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::MAIN_LOG_TARGET);
        }
    }

    public static function escapeMarkdown(string $text): string
    {
        return str_replace(["_", "*", "`"], ["\\_", "\\*", "\\`"], $text);
    }
}
