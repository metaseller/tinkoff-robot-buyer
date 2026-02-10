<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\models\TIAccount;
use app\models\TIServices;
use Exception;
use Metaseller\TinkoffInvestApi2\helpers\InstrumentsHelper;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use Throwable;
use Tinkoff\Invest\V1\Quotation;

/**
 * Консольный контроллер, обслуживающий ручные операции с портфелем
 *
 * @package app\commands
 */
class OperationController extends BaseController
{
    use ProgressTrait;

    /**
     * Метод выставления лимитной заявки на покупку инструмента
     *
     * @param string $account Алиас или идентификатор аккаунта
     * @param string $type Тип инструмента из списка <code>['etf', 'share', 'bond']</code>
     * @param string $ticker Тикер инструмента
     * @param int $lots Количество лотов к покупке
     * @param float|null $price Целевая цена, если передано <code>null</code>, будет выставлена заявка с лимитом лучшей цены
     *
     * @throws Exception|Throwable
     */
    public function actionBuy(string $account, string $type, string $ticker, int $lots = 1, float $price = null): void
    {
        static::stdoutStart(__FUNCTION__);

        try {
            $this->buyTaskLogic($account, $type, $ticker, $lots, $price);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::MAIN_LOG_TARGET);
        }

        static::stdoutEnd();
    }

    /**
     * Метод выставления лимитной заявки на продажу инструмента
     *
     * @param string $account Алиас или идентификатор аккаунта
     * @param string $type Тип инструмента из списка <code>['etf', 'share', 'bond']</code>
     * @param string $ticker Тикер инструмента
     * @param int $lots Количество лотов к продаже
     * @param float|null $price Целевая цена, если передано <code>null</code>, будет выставлена заявка с лимитом лучшей цены
     *
     * @throws Exception|Throwable
     */
    public function actionSell(string $account, string $type, string $ticker, int $lots = 1, float $price = null): void
    {
        static::stdoutStart(__FUNCTION__);

        try {
            $this->sellTaskLogic($account, $type, $ticker, $lots, $price);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage() . $e->getTraceAsString(), static::MAIN_LOG_TARGET);
        }

        static::stdoutEnd();
    }
}
