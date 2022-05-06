<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\helpers\DateTimeHelper;
use DateTime;
use DateTimeZone;
use Exception;
use Google\Protobuf\Internal\RepeatedField;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use Metaseller\TinkoffInvestApi2\providers\InstrumentsProvider;
use Metaseller\TinkoffInvestApi2\TinkoffClientsFactory;
use stdClass;
use Throwable;
use Tinkoff\Invest\V1\Account;
use Tinkoff\Invest\V1\GetAccountsRequest;
use Tinkoff\Invest\V1\GetAccountsResponse;
use app\components\console\Controller;
use Tinkoff\Invest\V1\GetOrderBookRequest;
use Tinkoff\Invest\V1\GetOrderBookResponse;
use Tinkoff\Invest\V1\Order;
use Tinkoff\Invest\V1\OrderDirection;
use Tinkoff\Invest\V1\OrderType;
use Tinkoff\Invest\V1\PortfolioPosition;
use Tinkoff\Invest\V1\PortfolioRequest;
use Tinkoff\Invest\V1\PortfolioResponse;
use Tinkoff\Invest\V1\PostOrderRequest;
use Tinkoff\Invest\V1\PostOrderResponse;
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
     * @var string Основная цель логгирования коммуникации с Tinkoff Invest
     */
    public const MAIN_LOG_TARGET = 'tinkoff_invest';

    /**
     * @var string Основная цель логгирования исполнения стратегии
     */
    public const STRATEGY_LOG_TARGET = 'tinkoff_invest_strategy';

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
            $tinkoff_invest = Yii::$app->tinkoffInvest;

            /**
             * @var GetAccountsResponse $response - Получаем ответ, содержащий информацию об аккаунтах
             */
            list($response, $status) = $tinkoff_invest->usersServiceClient->GetAccounts(new GetAccountsRequest())
                ->wait()
            ;

            $this->processRequestStatus($status, true);

            /** Выводим полученную информацию */
            /** @var Account $account */
            foreach ($response->getAccounts() as $account) {
                echo $account->getName() . ' => ' . $account->getId() . PHP_EOL;
            }
        } catch (Throwable $e) {
            echo 'Ошибка:' . $e->getMessage() . PHP_EOL;

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
            $tinkoff_invest = Yii::$app->tinkoffInvest;
            $client = $tinkoff_invest->operationsServiceClient;

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

            $instruments_provider = new InstrumentsProvider($tinkoff_invest, true, true, true, true);

            /** @var PortfolioPosition $position */
            foreach ($positions as $position) {
                $dictionary_instrument = $instruments_provider->instrumentByFigi($position->getFigi());

                $display = '[' . $position->getInstrumentType() . '][' . $position->getFigi() . '][' . $dictionary_instrument->getIsin() . '][' . $dictionary_instrument->getTicker() . '] ' . $dictionary_instrument->getName();

                echo $display . PHP_EOL;
                echo 'Лотов: ' . $position->getQuantityLots()->getUnits() . ', Количество: ' . QuotationHelper::toDecimal($position->getQuantity()) . PHP_EOL . PHP_EOL;
            }
        } catch (Throwable $e) {
            echo 'Ошибка:' . $e->getMessage() . PHP_EOL;

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
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        ob_start();

        if (!$this->isValidTradingPeriod(14, 8, 22, 45)) {
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

            echo 'Проверим стакан' . PHP_EOL;

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


            Yii::$app->cache->set($cache_trailing_count_key, $cache_trailing_count_value, 7 * DateTimeHelper::SECONDS_IN_DAY);
        } catch (Throwable $e) {
            echo 'Ошибка:' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        $stdout_data = ob_get_contents();
        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, static::STRATEGY_LOG_TARGET);

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
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        ob_start();

        if (!$this->isValidTradingPeriod(14, 8, 22, 45)) {
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

            $current_price = $top_ask_price;
            $current_price_decimal = QuotationHelper::toDecimal($current_price);

            $cache_trailing_count_key = $account_id . '@etf@' . $ticker . '_count';
            $cache_trailing_price_key = $account_id . '@etf@' . $ticker . '_price';

            $cache_trailing_count_value = Yii::$app->cache->get($cache_trailing_count_key) ?? 0;
            $cache_trailing_price_value = Yii::$app->cache->get($cache_trailing_price_key) ?? $current_price_decimal;

            $buy_step_reached = ($cache_trailing_count_value >= $buy_step);
            $prebuy_step_reached = ($cache_trailing_count_value >= $buy_step - 1);

            $place_order = false;

            $sensitivity_price = $cache_trailing_price_value * (1 + $trailing_sensitivity / 100);

            echo 'Накопленные данные: ' . Log::logSerialize([
                    'cache_trailing_count_value' => $cache_trailing_count_value,
                    'buy_step' => $buy_step,
                    'sensitivity' => $trailing_sensitivity . '%',
                    'cache_trailing_price_value' => $cache_trailing_price_value,
                    'sensitivity_price' => $sensitivity_price,
                    'current_price_decimal' => $current_price_decimal,
                ]) . PHP_EOL
            ;

            if ($buy_step_reached) {
                if ($current_price_decimal >= $sensitivity_price) {
                    $place_order = true;
                }
            }

            if (!$place_order) {
                /** Не переносим накопленные остатки на следующий день */
                if (($cache_trailing_count_value > ($buy_step / 2)) && !$this->isValidTradingPeriod(null, null, 22, 40)) {
                    echo 'Форсируем покупку для завершения торгового дня на объем ' . $cache_trailing_count_value . ' лотов' . PHP_EOL;

                    $place_order = true;
                }
            }

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

                Yii::$app->cache->set($cache_trailing_count_key, 0, 7 * DateTimeHelper::SECONDS_IN_DAY);

                $cache_trailing_price_value = $current_price_decimal;
            } else {
                if ($buy_step_reached) {
                    echo 'Событие покупки не наступило, цена не достигнута' . PHP_EOL;
                } else {
                    echo 'Событие покупки не наступило, мало накоплено' . PHP_EOL;
                }

                if ($prebuy_step_reached) {
                    $cache_trailing_price_value = min($cache_trailing_price_value, $current_price_decimal);
                } else {
                    $cache_trailing_price_value = $current_price_decimal;
                }
            }

            echo 'Помещаем в кэш: ' . Log::logSerialize([
                    'cache_trailing_count_value' => $cache_trailing_count_value,
                    'cache_trailing_price_value' => $cache_trailing_price_value,
                ]) . PHP_EOL
            ;

            Yii::$app->cache->set($cache_trailing_price_key, $cache_trailing_price_value, 7 * DateTimeHelper::SECONDS_IN_DAY);
        } catch (Throwable $e) {
            echo 'Ошибка:' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        $stdout_data = ob_get_contents();
        ob_end_clean();

        if ($stdout_data) {
            Log::info($stdout_data, static::STRATEGY_LOG_TARGET);

            echo $stdout_data;
        }
    }

    /**
     * Общий метод обработки статуса выполнения запроса.
     *
     * Если все хорошо - метод молчаливо заканчивает свою работу, в случае ошибки будут залогированны детали и брошено исключение
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
