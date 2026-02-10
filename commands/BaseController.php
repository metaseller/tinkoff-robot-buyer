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
use Metaseller\TinkoffInvestApi2\helpers\InstrumentsHelper;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use stdClass;
use Throwable;
use Tinkoff\Invest\V1\Bond;
use Tinkoff\Invest\V1\Currency;
use Tinkoff\Invest\V1\Etf;
use Tinkoff\Invest\V1\MoneyValue;
use Tinkoff\Invest\V1\OrderDirection;
use Tinkoff\Invest\V1\OrderType;
use Tinkoff\Invest\V1\PortfolioPosition;
use Tinkoff\Invest\V1\PortfolioResponse;
use Tinkoff\Invest\V1\PositionsRequest;
use Tinkoff\Invest\V1\PositionsResponse;
use Tinkoff\Invest\V1\PostOrderRequest;
use Tinkoff\Invest\V1\PostOrderResponse;
use Tinkoff\Invest\V1\Quotation;
use Tinkoff\Invest\V1\Share;
use Yii;

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
     * Получение общего количества денежных средств в портфеле с учетом денежных фондов
     *
     * @param string $account Алиас или идентификатор аккаунта (портфеля)
     * @param string $currency Валюта
     * @param Etf|null $money_etf_instrument Инструмент фонда денежных средств
     *
     * @return array Рассчитанное количество средств с информацией о средствах в фонде денежных средств
     *
     * @throws ValidateException|Throwable
     */
    protected static function portfolioMoneyTotal(string $account, string $currency = 'rub', Etf $money_etf_instrument = null): array
    {
        echo 'Calculate portfolio money' . PHP_EOL;

        $portfolio_currency = static::portfolioMoney($account);
        $commission = static::COMMISSION;

        $portfolio_money = $portfolio_currency[$currency]['available']->asDecimal();

        $etf_money = 0;
        $single_etf_price = 0;
        $money_etf_quantity = 0;

        if ($money_etf_instrument) {
            $money_etf_ticker = $money_etf_instrument->getTicker();
            $portfolio_money_etf = static::portfolioMoneyETF($account, [$money_etf_instrument]);

            if (!empty($portfolio_money_etf[$money_etf_ticker])) {
                $money_etf_quantity = $portfolio_money_etf[$money_etf_ticker]['quantity'] ?? 0;

                if ($money_etf_quantity > 0) {
                    $single_etf_price = $portfolio_money_etf[$money_etf_ticker]['price'] * (1 - $commission) / $money_etf_quantity;
                    $etf_money += $portfolio_money_etf[$money_etf_ticker]['price'] * (1 - $commission);
                }
            }
        }

        echo 'Portfolio money:' . $portfolio_money . PHP_EOL;
        echo 'Etf money parked:' . $etf_money . PHP_EOL;

        return [$portfolio_money, $etf_money, $single_etf_price, $money_etf_quantity];
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
     * Метод размещения заявки на покупку инструмента
     *
     * @param TIAccount $account DTO кредов аккаунта
     * @param Bond|Etf|Share|Currency|$instrument Инструмент к покупке
     * @param int $quantity Количество лотов
     * @param Quotation $price Лимит цены
     *
     * @return string|null Идентификатор заявки или <code>null</code> в случае неуспеха
     *
     * @throws Exception
     */
    protected static function placeBuyOrder(TIAccount $account, $instrument, int $quantity, Quotation $price): ?string
    {
        $tinkoff_api = TIServices::apiClient($account->profile);

        $post_order_request = new PostOrderRequest();
        $post_order_request->setInstrumentId($instrument->getFigi());
        $post_order_request->setQuantity($quantity);
        $post_order_request->setPrice($price);
        $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_BUY);
        $post_order_request->setAccountId($account->accountId);
        $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

        $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

        $post_order_request->setOrderId($order_id);

        /** @var PostOrderResponse $response */
        list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
        static::processRequestStatus($status);

        if (!$response) {
            return null;
        }

        return $response->getOrderId();
    }

    /**
     * Метод размещения лимитной заявки на продажу инструмента
     *
     * @param TIAccount $account DTO кредов аккаунта
     * @param Bond|Etf|Share|Currency|$instrument Инструмент к продаже
     * @param int $quantity Количество лотов
     * @param Quotation $price Лимит цены
     *
     * @return string|null Идентификатор заявки или <code>null</code> в случае неуспеха
     *
     * @throws Exception
     */
    protected static function placeSellOrder(TIAccount $account, $instrument, int $quantity, Quotation $price): ?string
    {
        $tinkoff_api = TIServices::apiClient($account->profile);

        $post_order_request = new PostOrderRequest();
        $post_order_request->setInstrumentId($instrument->getFigi());
        $post_order_request->setQuantity($quantity);
        $post_order_request->setPrice($price);
        $post_order_request->setDirection(OrderDirection::ORDER_DIRECTION_SELL);
        $post_order_request->setAccountId($account->accountId);
        $post_order_request->setOrderType(OrderType::ORDER_TYPE_LIMIT);

        $order_id = Yii::$app->security->generateRandomLettersNumbers(32);

        $post_order_request->setOrderId($order_id);

        /** @var PostOrderResponse $response */
        list($response, $status) = $tinkoff_api->ordersServiceClient->PostOrder($post_order_request)->wait();
        static::processRequestStatus($status);

        if (!$response) {
            return null;
        }

        return $response->getOrderId();
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
    protected function buyTaskLogic(string $account, string $type, string $ticker, int $lots = 1, float $price = null): void
    {
        echo 'Запрос покупки  ' . $type . ' ' . $ticker . ' на счет ' . $account . PHP_EOL;

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
        echo 'Получаем стакан' . PHP_EOL;

        $top_prices = $marketdata->getOrderbookTopPrices($target_instrument);

        $top_ask_price = $top_prices['ask'] ?? null;
        $top_bid_price = $top_prices['bid'] ?? null;

        if (!$top_ask_price) {
            echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

            throw new Exception('Orderbook is empty');
        }

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

        $order_id = static::placeBuyOrder($account, $target_instrument, $lots, $current_buy_price);

        if (!$order_id) {
            echo 'Ошибка отправки торговой заявки' . PHP_EOL;

            throw new Exception('Buy order error');
        }

        echo 'Заявка с идентификатором ' . $order_id . ' отправлена' . PHP_EOL;
    }

    /**
     * Логика метода выставления лимитной заявки на продажу инструмента
     *
     * @param string $account Алиас или идентификатор аккаунта
     * @param string $type Тип инструмента из списка <code>['etf', 'share', 'bond']</code>
     * @param string $ticker Тикер инструмента
     * @param int $lots Количество лотов к продаже
     * @param float|null $price Целевая цена, если передано <code>null</code>, будет выставлена заявка с лимитом лучшей цены
     *
     * @throws Exception|Throwable
     */
    protected function sellTaskLogic(string $account, string $type, string $ticker, int $lots = 1, float $price = null): void
    {
        $account = TIAccount::create($account);
        echo 'Запрос продажи  ' . $type . ' ' . $ticker . ' со счета ' . $account->accountId . PHP_EOL;

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

        if (!InstrumentsHelper::isReadyToTrade($target_instrument, true, true, false, true)) {
            echo 'Продажа не доступна' . PHP_EOL;

            throw new Exception('Impossible to sell');
        }

        echo 'Продажа доступна' . PHP_EOL;
        echo 'Получаем стакан' . PHP_EOL;

        $top_prices = $marketdata->getOrderbookTopPrices($target_instrument);

        $top_ask_price = $top_prices['ask'] ?? null;
        $top_bid_price = $top_prices['bid'] ?? null;

        if (!$top_bid_price) {
            echo 'Стакан пуст или биржа закрыта' . PHP_EOL;

            throw new Exception('Orderbook is empty');
        }

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

        $order_id = static::placeSellOrder($account, $target_instrument, $lots, $current_sell_price);

        if (!$order_id) {
            echo 'Ошибка отправки торговой заявки' . PHP_EOL;

            throw new Exception('Buy order error');
        }

        echo 'Заявка с идентификатором ' . $order_id . ' отправлена' . PHP_EOL;
    }
}
