<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\helpers\DateTimeHelper;
use app\models\TIAccount;
use app\models\TIServices;
use DateTime;
use DateTimeZone;
use Exception;
use Google\Protobuf\Internal\RepeatedField;
use Metaseller\TinkoffInvestApi2\helpers\InstrumentsHelper;
use Tinkoff\Invest\V1\GetOrderBookRequest;
use Tinkoff\Invest\V1\GetOrderBookResponse;
use Tinkoff\Invest\V1\Order;
use Tinkoff\Invest\V1\SecurityTradingStatus;
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

    /** МОДЕЛИРОВАНИЕ СТРАТЕГИИ */

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
