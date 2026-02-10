<?php

namespace app\commands;

use app\components\console\Controller;
use app\components\log\Log;
use app\models\TIAccount;
use app\models\TIProfile;
use app\models\TIServices;
use DateTime;
use DateTimeZone;
use Exception;
use Metaseller\TinkoffInvestApi2\dto\Price;
use Metaseller\TinkoffInvestApi2\dto\Quantity;
use Metaseller\TinkoffInvestApi2\exceptions\InstrumentNotFoundException;
use Metaseller\TinkoffInvestApi2\exceptions\ValidateException;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use stdClass;
use Throwable;
use Tinkoff\Invest\V1\Bond;
use Tinkoff\Invest\V1\Etf;
use Tinkoff\Invest\V1\MoneyValue;
use Tinkoff\Invest\V1\PortfolioPosition;
use Tinkoff\Invest\V1\PortfolioResponse;
use Tinkoff\Invest\V1\PositionsRequest;
use Tinkoff\Invest\V1\PositionsResponse;
use Tinkoff\Invest\V1\Share;

/**
 * Контроллер, содержащий общий базовый функционал взаимодействия с Tinkoff Invest Api
 *
 * @package app\commands
 */
abstract class BaseController extends Controller
{
    /**
     * @var float Расчетный размер комиссии за операции
     */
    public const COMMISSION = 0.0004;

    /**
     * @var string Основная цель логирования коммуникации с Tinkoff Invest
     */
    public const MAIN_LOG_TARGET = 'api';

    /**
     * @var string Основная цель логирования исполнения стратегии автопокупки ETF
     */
    public const STRATEGY_ETF_LOG_TARGET = 'strategy-etf';

    /**
     * @var string Основная цель логирования исполнения стратегии управления портфелем
     */
    public const STRATEGY_MANAGE_LOG_TARGET = 'strategy-manage';

    /**
     * Общий метод обработки статуса выполнения запроса.
     *
     * Если все хорошо - метод молчаливо заканчивает свою работу, в случае ошибки будут залоггированы детали и брошено исключение
     *
     * @param stdClass|array|null $status Статус выполнения запроса
     * @param bool $echo_to_stdout Флаг необходимости вывести подробности ошибки в stdOut.  По умолчанию равно <code>false</code>
     *
     * @throws Exception
     */
    protected static function processRequestStatus($status, bool $echo_to_stdout = false): void
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

        $log_error_message = 'T-Invest Api Request Error: ' . Log::logSerialize($error);

        Log::error($log_error_message, static::MAIN_LOG_TARGET);

        if ($echo_to_stdout) {
            echo $log_error_message . PHP_EOL;
        }

        throw new Exception('T-Invest Api Request Error. Check logs for details');
    }

    /**
     * Метод конвертации массива тикеров в массив инструментов
     *
     * @param TIProfile $profile Профиль
     * @param string $type Типа инструмента из списка <code>['etf', 'share', 'bond']</code>
     * @param string[] $tickers Массив тикеров (инструментов указанного типа)
     *
     * @return Etf[]|Share[]|Bond[] Массив инструментов
     *
     * @throws InstrumentNotFoundException
     * @throws Exception
     */
    protected function tickersInstruments(TIProfile $profile, string $type, array $tickers): array
    {
        $instruments = TIServices::instruments($profile);

        $instrument_models = [];

        foreach ($tickers as $ticker) {
            switch ($type) {
                case 'etf':
                    $instrument_models[$ticker] = $instruments->etfByTicker($ticker);

                    break;
                case 'share':
                    $instrument_models[$ticker] = $instruments->shareByTicker($ticker);

                    break;
                case 'bond':
                    $instrument_models[$ticker] = $instruments->bondByTicker($ticker);

                    break;
                default:
                    throw new Exception('Unsupported instrument type');
            }
        }

        return $instrument_models;
    }

    /**
     * Метод получения денежных позиций портфеля
     *
     * @param string $account Алиас или идентификатор аккаунта
     *
     * @return array[] Массив с информацией о количестве денежных средств вида
     *  <pre>
     *      [
     *          (string) $currency => [
     *              'available' => {@link Price},
     *              'blocked' => {@link Price},
     *          ],
     *          ...
     *       ]
     *   </pre>
     *
     * @throws ValidateException
     * @throws Exception
     * @throws Throwable
     */
    protected static function portfolioMoney(string $account): array
    {
        $account = TIAccount::create($account);
        $portfolio = TIServices::portfolio($account->profile);

        return $portfolio->getPortfolioMoney($account->accountId);
    }

    /**
     * Получение стоимости денежных фондов в портфеле
     *
     * @param string $account Алиас или идентификатор аккаунта (портфеля)
     * @param Etf[] $money_etf_instruments Целевой список инструментов
     *
     * @return array Массив стоимостей вида
     *  <pre>
     *      [
     *          'LQDT' => [
     *              'quantity' => (float),
     *              'price' => (float),
     *          ],
     *          ...
     *      ]
     *  </pre>
     *
     * @throws ValidateException
     * @throws Exception|Throwable
     */
    protected static function portfolioMoneyETF(string $account, array $money_etf_instruments = []): array
    {
        $account = TIAccount::create($account);
        $portfolio = TIServices::portfolio($account->profile);

        /**
         * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
         */
        $response = $portfolio->getPortfolio($account->accountId);

        $target_figi = [];

        foreach ($money_etf_instruments as $money_etf_instrument) {
            if ($money_etf_instrument instanceof Etf) {
                $target_figi[$money_etf_instrument->getFigi()] = $money_etf_instrument->getTicker();
            }
        }

        $money_ETFs = [];

        if (empty($target_figi)) {
            return $money_ETFs;
        }

        /** @var PortfolioPosition $position */
        foreach ($response->getPositions() as $position) {
            if ($money_etf_ticker = $target_figi[$position->getFigi()] ?? null) {
                $quantity = Quantity::createFromQuotation($position->getQuantity());
                $price = Price::createFromMoneyValue($position->getCurrentPrice());

                $money_ETFs[$money_etf_ticker]['quantity'] = $quantity->asDecimal();
                $money_ETFs[$money_etf_ticker]['price'] = $quantity->asDecimal() * $price->asDecimal();
            }
        }

        return $money_ETFs;
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
    protected static function isValidTradingPeriod(int $start_hour = null, int $start_minute = null, int $end_hour = null, int $end_minute = null, string $date_time_zone = 'Asia/Krasnoyarsk'): bool
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

    /**
     * Подготовка вывода в начале выполнения функции
     *
     * @param string $function Имя функции
     * @param string|null $log_target Цель лога
     */
    protected static function stdoutStart(string $function, ?string $log_target = self::MAIN_LOG_TARGET): void
    {
        if ($log_target) {
            Log::info('Start action ' . $function, $log_target);
        }

        ob_start();
    }

    /**
     * Завершение вывода в конце выполнения функции
     *
     * @param string|null $log_target Цель лога
     */
    protected static function stdoutEnd(?string $log_target = self::MAIN_LOG_TARGET): void
    {
        $stdout_data = ob_get_contents();

        ob_end_clean();

        if ($stdout_data) {
            if ($log_target) {
                Log::info($stdout_data, $log_target);
            }

            echo $stdout_data;
        }
    }

    /**
     * Экранирование спецсимволов markdown в тексте
     *
     * @param string $text Текст для экранирования
     *
     * @return string Текст после экранирования
     */
    public static function escapeMarkdown(string $text): string
    {
        return str_replace(["_", "*", "`"], ["\\_", "\\*", "\\`"], $text);
    }
}
