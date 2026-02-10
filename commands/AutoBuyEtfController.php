<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\helpers\DateTimeHelper;
use app\helpers\NumbersHelper;
use app\models\TIAccount;
use app\models\TIServices;
use DateInterval;
use DatePeriod;
use DateTime;
use DateTimeZone;
use Exception;
use Metaseller\TinkoffInvestApi2\dto\Price;
use Metaseller\TinkoffInvestApi2\helpers\InstrumentsHelper;
use Throwable;
use Yii;

/**
 * Консольный контроллер, обслуживающий стратегию автоматической непрерывной покупки ETF в портфель с регулируемой скоростью расходования средств
 *
 * @package app\commands
 */
class AutoBuyEtfController extends BaseController
{
    use ProgressTrait;

    /** СТРАТЕГИЯ ПОКУПКИ */

    /**
     * Метод инкрементирует в указанном аккаунте количество накопленных к покупке лотов ETF с указанным тикером на
     * указанное количество единиц
     *
     * @param string $account Идентификатор аккаунта, для которого работает стратегия
     * @param string $ticker Тикер ETF инструмента
     * @param int $lots_increment Количество лотов, на которое инкрементируем накопленное количество
     *
     * @return void
     *
     * @throws Exception
     */
    public function actionIncrementEtfTrailing(string $account, string $ticker, int $lots_increment): void
    {
        static::stdoutStart(__FUNCTION__, static::STRATEGY_ETF_LOG_TARGET);

        if (
            !static::isValidTradingPeriod(14, 0, 23, 59) &&
            !static::isValidTradingPeriod(0, 0, 3, 45)
        ) {
            static::stdoutEnd(static::STRATEGY_ETF_LOG_TARGET);

            return;
        }

        try {
            $account = TIAccount::create($account);
            $tinkoff_instruments = TIServices::instruments($account->profile);

            echo 'Ищем ETF инструмент' . PHP_EOL;

            $target_instrument = $tinkoff_instruments->etfByTicker($ticker);

            echo 'Инструмент найден' . PHP_EOL;

            if (!InstrumentsHelper::isReadyToTrade($target_instrument)) {
                echo 'Покупка не доступна' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_ETF_LOG_TARGET);

                return;
            }

            $cache_trailing_count_key = $account->accountId . '@etf@' . $ticker . '_count';
            $cache_trailing_count_value = Yii::$app->cache->get($cache_trailing_count_key) ?? 0;

            echo 'Накопленное количество к покупке ' . $ticker . ': ' . $cache_trailing_count_value . PHP_EOL;

            $cache_trailing_count_value = $cache_trailing_count_value + $lots_increment;

            echo 'Инкрементируем количество к покупке ' . $ticker . ': ' . $cache_trailing_count_value . PHP_EOL;

            Yii::$app->cache->set($cache_trailing_count_key, $cache_trailing_count_value, 6 * DateTimeHelper::SECONDS_IN_HOUR);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::STRATEGY_ETF_LOG_TARGET);
        }

        static::stdoutEnd(static::STRATEGY_ETF_LOG_TARGET);
    }

    /**
     * Метод проверяет выполнение условий трейлинг-стратегии покупки и выставляет ордер на покупку по лучшей доступной цене
     * в стакане
     *
     * @param string $account Идентификатор аккаунта, для которого работает стратегия
     * @param string $ticker Тикер ETF инструмента
     * @param int $buy_step Минимальный накопленный лимит лотов, после достижения которого выставляется заявка на покупку
     * @param float $trailing_sensitivity Величина в процентах, на которую текущая цена должна превысить трейлинг цену для
     * совершения покупки. По умолчанию равно <code>0</code>
     *
     * @throws Exception|Throwable
     */
    public function actionBuyEtfTrailing(string $account, string $ticker, int $buy_step, float $trailing_sensitivity = 0): void
    {
        static::stdoutStart(__FUNCTION__, static::STRATEGY_ETF_LOG_TARGET);

        if (
            !static::isValidTradingPeriod(14, 0, 23, 59) &&
            !static::isValidTradingPeriod(0, 0, 3, 45)
        ) {
            static::stdoutEnd(static::STRATEGY_ETF_LOG_TARGET);

            return;
        }

        try {
            $account = TIAccount::create($account);

            $tinkoff_instruments = TIServices::instruments($account->profile);
            $marketdata = TIServices::marketdata($account->profile);

            echo 'Ищем ETF инструмент' . PHP_EOL;

            $target_instrument = $tinkoff_instruments->etfByTicker($ticker);

            echo 'Инструмент найден' . PHP_EOL;

            if (!InstrumentsHelper::isReadyToTrade($target_instrument)) {
                echo 'Покупка не доступна' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_ETF_LOG_TARGET);

                return;
            }

            echo 'Получаем стакан' . PHP_EOL;

            $top_prices = $marketdata->getOrderbookTopPrices($target_instrument);

            $top_ask_price = $top_prices['ask'] ?? null;
            $top_bid_price = $top_prices['bid'] ?? null;

            if (!$top_ask_price) {
                echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

                static::stdoutEnd(static::STRATEGY_ETF_LOG_TARGET);

                return;
            }

            $current_price = Price::createFromQuotation($top_ask_price);
            $current_price_decimal = $current_price->asDecimal();

            $current_bid_decimal = $top_bid_price ? Price::createFromQuotation($top_bid_price)->asDecimal() : '-';

            $cache_trailing_count_key = $account->accountId . '@etf@' . $ticker . '_count';
            $cache_trailing_events_key = $account->accountId . '@etf@' . $ticker . '_events';
            $cache_trailing_price_key = $account->accountId . '@etf@' . $ticker . '_price';

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
                $final_day_action = ($cache_trailing_count_value > ($buy_step / 2)) && static::isValidTradingPeriod(3, 40, 3, 44);

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

                $order_id = static::placeBuyOrder($account, $target_instrument, $cache_trailing_count_value, $current_price->asQuotation());

                if (!$order_id) {
                    echo 'Ошибка отправки торговой заявки' . PHP_EOL;

                    static::stdoutEnd(static::STRATEGY_ETF_LOG_TARGET);

                    return;
                }

                echo 'Заявка с идентификатором ' . $order_id . ' отправлена' . PHP_EOL;

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

            static::storeCurrentPrice($account->accountId, $ticker, $current_price_decimal);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::STRATEGY_ETF_LOG_TARGET);
        }

        static::stdoutEnd(static::STRATEGY_ETF_LOG_TARGET);
    }

    /** ИСТОРИЧЕСКОЕ МОДЕЛИРОВАНИЕ СТРАТЕГИИ */

    /**
     * Подбор/оптимизация параметров автопокупки на указанный день
     *
     * @param string $account Алиас или идентификатор аккаунта
     * @param string $ticker Тикер инструмента
     * @param string|null $date Дата моделирования
     *
     * @throws Exception
     */
    public function actionDayParamsOptimization(string $account, string $ticker, string $date = null): void
    {
        $account = TIAccount::create($account);

        $cache_key = 'history_prices_list@account:' . $account->accountId . ':ticker:' . $ticker;

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

    /**
     * Моделирование автопокупок в портфель за период
     *
     * Период - с переданной даты до сегодняшнего дня
     *
     * @param string $account Алиас или идентификатор аккаунта
     * @param string $ticker Тикер инструмента
     * @param string|null $date Дата
     *
     * @throws Exception
     */
    public function actionPeriodParamsOptimization(string $account, string $ticker, string $date = null): void
    {
        $account = TIAccount::create($account);
        $cache_key = 'history_prices_list@account:' . $account->accountId . ':ticker:' . $ticker;

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
     * Метод сохраняет в Redis информацию о текущем состоянии цены для исторического моделирования
     *
     * @param string $account Алиас или идентификатор аккаунта
     * @param string $ticker Тикер инструмента
     * @param float $price Текущая цена
     *
     * @throws Exception
     */
    protected static function storeCurrentPrice(string $account, string $ticker, float $price): void
    {
        try {
            $account = TIAccount::create($account);
            $cache_key = 'history_prices_list@account:' . $account->accountId . ':ticker:' . $ticker;

            Yii::$app->redis->lpush($cache_key, json_encode([date('Y-m-d'), time(), $price]));
            Yii::$app->redis->ltrim($cache_key, 0, 5 * 12 * 60);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::STRATEGY_ETF_LOG_TARGET);
        }
    }

    /**
     * Вычисление параметров эффективности смоделированного портфеля
     *
     * @param array $portfolio Смоделированный портфель
     *
     * @return array Массив, содержащий среднюю цену покупки, количество лотов и потраченную сумму
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
     * Метод моделирования логики автопокупки по историческим данным
     *
     * @param array $history_data Исторические данные
     * @param int $lot_increment Параметр "Шаг инкремента"
     * @param int $increment_period Параметр "Период инкремента"
     * @param int $buy_step
     * @param float $trailing_sensitivity
     *
     * @return array Сформированный портфель
     *
     * @throws Exception
     */
    protected static function modelingTrailingBuy(array $history_data, int $lot_increment, int $increment_period, int $buy_step, float $trailing_sensitivity): array
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
                $final_day_action = ($cache_trailing_count_value > ($buy_step / 2)) && static::isValidTradingPeriodModeling($time, 3, 40, 3, 44);

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
     * Костыльный вспомогательный метод, для моделирования стратегии, который контролирует, попал ли указанный момент времени в требуемый временной период в течение торгового дня
     *
     * @param int $utc_timestamp Таймштамп по UTC целевого момента времени
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
    protected static function isValidTradingPeriodModeling(int $utc_timestamp, int $start_hour = null, int $start_minute = null, int $end_hour = null, int $end_minute = null, string $date_time_zone = 'Asia/Krasnoyarsk'): bool
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
}
