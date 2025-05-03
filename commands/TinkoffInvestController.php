<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\helpers\ArrayHelper;
use app\helpers\DateTimeHelper;
use app\helpers\NumbersHelper;
use DateInterval;
use DatePeriod;
use DateTime;
use DateTimeZone;
use Exception;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Timestamp;
use Metaseller\TinkoffInvestApi2\exceptions\InstrumentNotFoundException;
use Metaseller\TinkoffInvestApi2\exceptions\ValidateException;
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
     * @param string $credentials_alias Алиас токена доступа в файле {@see ./credentials.php}
     *
     * @return void
     */
    public function actionUserInfo(string $credentials_alias = 'default'): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        ob_start();

        try {
            $tinkoff_api = static::tinkoffApiClientByAlias($credentials_alias);

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

    public function actionTest(string $account_id, string $ticker): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        ob_start();

        try {
            $tinkoff_api = static::tinkoffApiClientByAccountId($account_id);
            $tinkoff_instruments = InstrumentsProvider::create($tinkoff_api);

            echo 'Ищем инструмент' . PHP_EOL;

            $target_instrument = $tinkoff_instruments->etfByTicker($ticker);

            echo 'Найден инструмент: ' . $target_instrument->serializeToJsonString() . PHP_EOL;

            dd($this->getPortfolioMoneyETF($account_id));

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
     * Консольное действие, которые выводит в stdout список идентификаторов ваших портфеле
     *
     * @param string $credentials_alias Алиас токена доступа в файле {@see ./credentials.php}
     *
     * @return void
     */
    public function actionAccounts(string $credentials_alias = 'default'): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        ob_start();

        try {
            $tinkoff_api = static::tinkoffApiClientByAlias($credentials_alias);

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
            $tinkoff_api = static::tinkoffApiClientByAccountId($account_id);
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
            $tinkoff_api = static::tinkoffApiClientByAccountId($account_id);
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
            $tinkoff_api = static::tinkoffApiClientByAccountId($account_id);
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

            $this->storeCurrentPrice($account_id, $ticker, $current_price_decimal);
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
            $tinkoff_api = static::tinkoffApiClientByAccountId($account_id);

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
            $tinkoff_api = static::tinkoffApiClientByAlias();
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
        $tinkoff_api = static::tinkoffApiClientByAlias();
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

    protected function isValidTradingPeriodModeling(int $utc_timestamp, int $start_hour = null, int $start_minute = null, int $end_hour = null, int $end_minute = null, string $date_time_zone = 'Asia/Krasnoyarsk'): bool
    {
        $current_time = new DateTime();
        $current_time->setTimezone(new DateTimeZone('UTC'));
        $current_time->setTimestamp($utc_timestamp);
        $current_time->setTimezone(new DateTimeZone($date_time_zone));

        if (isset($start_hour)) {
            $start_time = clone $current_time;
            $start_time->setTime($start_hour, $start_minute ?: 0);

            if ($current_time->getTimestamp() < $start_time->getTimestamp()) {
                return false;
            }
        }

        if (isset($end_hour)) {
            $end_time = clone $current_time;
            $end_time->setTime($end_hour, $end_minute ?: 0);

            if ($current_time->getTimestamp() > $end_time->getTimestamp()) {
                return false;
            }
        }

        return true;
    }

    public function actionParkFreeMoney(string $account_id, string $type, string $ticker, int $lots = null, float $price = null): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        try {
            $tinkoff_api = static::tinkoffApiClientByAccountId($account_id);
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

            list($portfolio_currency, $portfolio_currency_decimal) = $this->getPortfolioMoney($account_id);
            $currency_decimal = $portfolio_currency_decimal[$target_instrument->getCurrency()]['available'] ?? 0;

            echo 'Свободно средств на счете: ' . $currency_decimal . PHP_EOL;

            $comission = 0.0005;

            $can_buy_lots = (int) floor(($currency_decimal / ($current_buy_price_decimal * (1 + $comission))) / $target_instrument->getLot());
            $can_buy_lots = max(0, $can_buy_lots - (int) ($can_buy_lots / (100 * $current_buy_price_decimal)));

            $can_buy_lots = min($lots ?? $can_buy_lots, $can_buy_lots);

            echo 'Вычислено к покупке ' . $can_buy_lots . ' лотов' . PHP_EOL;

            $commission_estimate = (int) floor($can_buy_lots * $current_buy_price_decimal * $comission * 100);

            echo '$commission_estimate = ' . $commission_estimate . PHP_EOL;

            if ($commission_estimate <= 1) {
                $commission_correction = 1.5;

                $optimal_buy_lots = (int) floor($commission_correction / ($current_buy_price_decimal * $comission * 100));

                echo '$commission_correction = ' . $commission_correction . PHP_EOL;
                echo '$optimal_buy_lots = ' . $optimal_buy_lots . PHP_EOL;
            } else {
                $commission_correction = (float) $commission_estimate + 0.5;
                $optimal_buy_lots = (int) floor($commission_correction / ($current_buy_price_decimal * $comission * 100));

                echo '$commission_correction = ' . $commission_correction . PHP_EOL;
                echo '$optimal_buy_lots = ' . $optimal_buy_lots . PHP_EOL;

                if ($optimal_buy_lots > $can_buy_lots) {
                    $commission_correction = (float) $commission_estimate - 0.5;
                    $optimal_buy_lots = (int) floor($commission_correction / ($current_buy_price_decimal * $comission * 100));

                    echo '$commission_correction = ' . $commission_correction . PHP_EOL;
                    echo '$optimal_buy_lots = ' . $optimal_buy_lots . PHP_EOL;
                }
            }

            echo 'Оптимально по комиссии к покупке ' . $optimal_buy_lots . ' лотов' . PHP_EOL;

            if (($optimal_buy_lots > 0) && ($can_buy_lots >= $optimal_buy_lots)) {
                $post_order_request = new PostOrderRequest();
                $post_order_request->setFigi($target_instrument->getFigi());
                $post_order_request->setQuantity($optimal_buy_lots);
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
            }
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::MAIN_LOG_TARGET);
        }
    }

    public function actionBuy(string $account_id, string $type, string $ticker, int $lots = 1, float $price = null): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        try {
            echo 'Запрос покупки  ' . $type . ' ' . $ticker . ' на счет ' . $account_id . PHP_EOL;

            $tinkoff_api = static::tinkoffApiClientByAccountId($account_id);
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

    public function actionSell(string $account_id, string $type, string $ticker, int $lots = 1, float $price = null): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        try {
            echo 'Запрос продажи  ' . $type . ' ' . $ticker . ' со счета ' . $account_id . PHP_EOL;

            $tinkoff_api = static::tinkoffApiClientByAccountId($account_id);
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
            $orderbook_request->setFigi($target_instrument->getFigi());

            /** @var GetOrderBookResponse $response */
            list($response, $status) = $tinkoff_api->marketDataServiceClient->GetOrderBook($orderbook_request)->wait();
            $this->processRequestStatus($status, true);

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
            $post_order_request->setFigi($target_instrument->getFigi());
            $post_order_request->setQuantity($lots);
            $post_order_request->setPrice($current_sell_price);
            $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_SELL);
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
    public function actionOperations(string $account_shortcut, string $credentials_alias = 'default'): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        if (!$account_id = Yii::$app->params['tinkoff_invest']['credentials'][$credentials_alias]['accounts_shortcuts'][$account_shortcut] ?? false) {
            return;
        }

        $bot = null;

        $telegram_config = Yii::$app->params['telegram'] ?? [];
        $token = $telegram_config[$account_shortcut]['token'] ?? null;
        $chat_id = $telegram_config[$account_shortcut]['chat_id'] ?? null;

        if ($token && $chat_id) {
            $bot = new TelegramBotApi(['apiToken' => $token]);
        }

        try {
            echo 'Запрос операций по счету ' . $account_id . PHP_EOL;

            $tinkoff_api = static::tinkoffApiClientByAlias($credentials_alias);
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

                    if ($quantity = $operation->getQuantity()) {
                        $message .= ' (' . $quantity . ' шт)';
                    }

                    if ($payment = $operation->getPayment()) {
                        $price = QuotationHelper::toDecimal($payment);

                        $message .= ' `' . static::escapeMarkdown($price . ' ' . $operation->getCurrency()) . '`';
                    }

                    if (!empty($quantity) && !empty($price) && $quantity > 0) {
                        $one_price = abs($price / $quantity);

                        $message .= ' (`' . static::escapeMarkdown(NumbersHelper::printFloat($one_price, 2, false) . ' ' . $operation->getCurrency()) . '` за шт.)';
                    }

                    try {
                        list($portfolio_currency, $portfolio_currency_decimal) = $this->getPortfolioMoney($account_id);

                        $message .= PHP_EOL . 'Свободных средств: ';

                        foreach ($portfolio_currency_decimal as $cur => $values) {
                            $blocked = $values['blocked'] ?? 0;
                            $message .= ' `' . static::escapeMarkdown($values['available'] . ' ' . $cur . ($blocked > 0 ? ' (Заблокировано: ' . $blocked . ' ' . $cur . ')' : '')) . '`' . PHP_EOL;
                        }
                    } catch (Throwable $e) {}

                    try {
                        $portfolio_money_etf = $this->getPortfolioMoneyETF($account_id);

                        if (!empty($portfolio_money_etf)) {
                            $message .= 'Припарковано: ';

                            foreach ($portfolio_money_etf as $money_etf_ticker => $values) {
                                $message .= ' `' . static::escapeMarkdown(NumbersHelper::printFloat($values['price'] ?? 0, 2) . ' rub [' . $money_etf_ticker . '] ' . ((int) $values['quantity'] ?? 0) . ' шт.') . '`' . PHP_EOL;
                            }
                        }
                    } catch (Throwable $e) {}

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

    /**
     * Метод получения экземпляра клиента к tinkoffInvestApi по алиасу токена доступа
     *
     * @param string $credentials_alias Алиас токена доступа в файле {@see ./credentials.php}
     *
     * @return void
     *
     * @throws ValidateException
     */
    protected static function tinkoffApiClientByAlias(string $credentials_alias = 'default'): TinkoffInvestApi
    {
        $credentials = Yii::$app->params['tinkoff_invest']['credentials'][$credentials_alias] ?? [];
        $token = $credentials['secret_key'] ?? null;

        if (!$token) {
            throw new ValidateException('Unknown credential alias');
        }

        return new TinkoffInvestApi([
            'apiToken' => $token,
            'appName' => Yii::$app->params['tinkoff_invest']['app_name'] ?? 'metaseller.tinkoff-invest-api-v2-yii2',
        ]);
    }

    /**
     * Метод получения экземпляра клиента к tinkoffInvestApi по идентификатору аккаунта
     *
     * @param string $account_id Идентификатор аккаунта
     *
     * @return void
     *
     * @throws ValidateException
     */
    protected static function tinkoffApiClientByAccountId(string $account_id): TinkoffInvestApi
    {
        $credentials = Yii::$app->params['tinkoff_invest']['credentials'] ?? [];

        foreach ($credentials as $credentials_alias => $credentials_data) {
            if (($credentials_data['account_id'] ?? '') === $account_id) {
                return static::tinkoffApiClientByAlias($credentials_alias);
            }
        }

        foreach ($credentials as $credentials_alias => $credentials_data) {
            foreach ($credentials_data['accounts_shortcuts'] ?? [] as $shortcut => $shortcut_account_id)
            if ($shortcut_account_id === $account_id) {
                return static::tinkoffApiClientByAlias($credentials_alias);
            }
        }

        throw new ValidateException('Account is not found in credentials config');
    }

    protected function getPortfolioMoney(string $account_id): array
    {
        $portfolio_currency = [];
        $portfolio_currency_decimal = [];

        $tinkoff_api = static::tinkoffApiClientByAccountId($account_id);
        $client = $tinkoff_api->operationsServiceClient;

        $request = new PositionsRequest();
        $request->setAccountId($account_id);

        /**
         * @var PositionsResponse $response - Получаем ответ, содержащий информацию о портфеле
         */
        list($response, $status) = $client->GetPositions($request)->wait();
        $this->processRequestStatus($status, true);

        /** @var MoneyValue $money */
        foreach ($response->getMoney() as $money) {
            $currency = $money->getCurrency();

            $blocked_mocked = (new MoneyValue())
                ->setCurrency($currency)
                ->setNano(0)
                ->setUnits(0)
            ;

            $portfolio_currency[$currency]['available'] = $money;
            $portfolio_currency[$currency]['blocked'] = $blocked_mocked;

            $portfolio_currency_decimal[$currency]['available'] = QuotationHelper::toDecimal($money);
            $portfolio_currency_decimal[$currency]['blocked'] = 0;

        }

        /** @var MoneyValue $blocked */
        foreach ($response->getBlocked() as $blocked) {
            $currency = $blocked->getCurrency();

            $portfolio_currency[$currency]['blocked'] = $blocked;
            $portfolio_currency_decimal[$currency]['blocked'] = QuotationHelper::toDecimal($blocked);
        }

        return [$portfolio_currency, $portfolio_currency_decimal];
    }

    protected function getPortfolioMoneyETF(string $account_id): array
    {
        $tinkoff_api = static::tinkoffApiClientByAccountId($account_id);
        $client = $tinkoff_api->operationsServiceClient;

        $request = new PortfolioRequest();
        $request->setAccountId($account_id);

        /**
         * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
         */
        list($response, $status) = $client->GetPortfolio($request)->wait();
        $this->processRequestStatus($status, true);

        $money_ETFs = [];

        /** @var PortfolioPosition $position */
        foreach ($response->getPositions() as $position) {
            switch ($position->getFigi()) {
                case 'BBG00RPRPX12':
                    $money_ETFs['LQDT']['quantity'] = QuotationHelper::toDecimal($position->getQuantity());
                    $money_ETFs['LQDT']['price'] = $money_ETFs['LQDT']['quantity'] * QuotationHelper::toDecimal($position->getCurrentPrice());

                    break;
            }
        }

        return $money_ETFs;
    }

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

                    echo $trailing_sensitivity . PHP_EOL;

                    if ($trailing_sensitivity === 0.48) {
                        var_dump($portfolio);
                        echo PHP_EOL;
                    }

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

                echo $trailing_sensitivity . PHP_EOL;

                var_dump($portfolio);
                echo PHP_EOL;

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
