<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\helpers\ArrayHelper;
use app\helpers\DateTimeHelper;
use app\helpers\NumbersHelper;
use app\models\TelegramBot;
use app\models\TIAccount;
use app\models\TIProfile;
use app\models\TIServices;
use DateTime;
use DOMDocument;
use DOMElement;
use Exception;
use Google\Protobuf\Timestamp;
use Metaseller\TinkoffInvestApi2\dto\Price;
use Metaseller\TinkoffInvestApi2\dto\Quantity;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use PHPUnit\Util\Xml;
use Throwable;
use Tinkoff\Invest\V1\Account;
use Tinkoff\Invest\V1\CandleInstrument;
use Tinkoff\Invest\V1\GetAccountsRequest;
use Tinkoff\Invest\V1\GetAccountsResponse;
use Tinkoff\Invest\V1\GetInfoRequest;
use Tinkoff\Invest\V1\GetInfoResponse;
use Tinkoff\Invest\V1\LastPriceInstrument;
use Tinkoff\Invest\V1\MarketDataRequest;
use Tinkoff\Invest\V1\MarketDataResponse;
use Tinkoff\Invest\V1\Operation;
use Tinkoff\Invest\V1\OperationsRequest;
use Tinkoff\Invest\V1\OperationsResponse;
use Tinkoff\Invest\V1\OperationState;
use Tinkoff\Invest\V1\OperationType;
use Tinkoff\Invest\V1\PortfolioPosition;
use Tinkoff\Invest\V1\PortfolioResponse;
use Tinkoff\Invest\V1\SecurityTradingStatus;
use Tinkoff\Invest\V1\SubscribeCandlesRequest;
use Tinkoff\Invest\V1\SubscribeLastPriceRequest;
use Tinkoff\Invest\V1\SubscriptionAction;
use Tinkoff\Invest\V1\SubscriptionInterval;
use Yii;
use yii\helpers\Console;

/**
 * Консольный контроллер, предназначенный для получения и вывода информации с T-Invest Api
 *
 * @package app\commands
 */
class InfoController extends BaseController
{
    use ProgressTrait;

    /**
     * @var string[] Список тикеров денежных фондов, используемых в системе
     */
    public const MONEY_ETF = [
        'LQDT',
    ];

    /**
     * Получение информации о пользователе
     *
     * Выводит данные в STDOUT
     *
     * @param string $profile_alias Алиас профиля
     */
    public function actionUser(string $profile_alias = 'default'): void
    {
        static::stdoutStart(__FUNCTION__);

        try {
            $tinkoff_api = TIServices::apiClient(TIProfile::create($profile_alias));

            /**
             * Создаем экземпляр запроса информации об аккаунте к сервису
             *
             * Запрос не принимает никаких параметров на вход
             */
            $request = new GetInfoRequest();

            /**
             * @var GetInfoResponse $response - Получаем ответ, содержащий информацию о пользователе
             */
            list($response, $status) = $tinkoff_api->usersServiceClient->GetInfo($request)->wait();
            static::processRequestStatus($status, true);

            /** Выводим полученную информацию */

            echo Log::logSerialize([
                'user_info' => [
                    'prem_status' => $response->getPremStatus(),
                    'qual_status' => $response->getQualStatus(),
                    'qualified_for_work_with' => ArrayHelper::repeatedFieldToArray($response->getQualifiedForWorkWith()),
            ]]);
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        static::stdoutEnd();
    }

    /**
     * Получение идентификаторов портфелей
     *
     * Выводит данные в STDOUT
     *
     * @param string $profile_alias Алиас профиля
     */
    public function actionAccounts(string $profile_alias = 'default'): void
    {
        static::stdoutStart(__FUNCTION__);

        try {
            $tinkoff_api = TIServices::apiClient(TIProfile::create($profile_alias));

            /**
             * @var GetAccountsResponse $response - Получаем ответ, содержащий информацию об аккаунтах
             */
            list($response, $status) = $tinkoff_api->usersServiceClient->GetAccounts(new GetAccountsRequest())
                ->wait()
            ;

            static::processRequestStatus($status, true);

            /** Выводим полученную информацию */

            /** @var Account $account */
            foreach ($response->getAccounts() as $account) {
                echo $account->getName() . ' => ' . $account->getId() . PHP_EOL;
            }
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        static::stdoutEnd();
    }

    /**
     * Получает HTML страницу с MOEX, парсит первую таблицу и возвращает данные в виде ассоциативного массива
     */
    public function actionImoex(float $weight_limit = 0.5): void
    {
        $data_url = 'https://iss.moex.com/iss/statistics/engines/stock/markets/index/analytics/IMOEX.html?limit=100';

        echo 'Получаем данные со странички: ' . $data_url . PHP_EOL . PHP_EOL;

        $data = static::parsingImoexWeights();

        if (!$data) {
            echo 'Ошибка получения данных';

            return;
        }

        arsort($data);

        foreach ($data as $ticker => $weight)
        {
            $this->stdout($ticker . "\t" . $weight . PHP_EOL, $weight >= $weight_limit ? Console::FG_GREEN : Console::FG_RED);
        }

        echo PHP_EOL;
    }

    /**
     * Получение состава портфеля
     *
     * Выводит данные в STDOUT
     *
     * @param string $account Идентификатор или алиас аккаунта (портфеля)
     */
    public function actionPortfolio(string $account): void
    {
        static::stdoutStart(__FUNCTION__);

        try {
            $account = TIAccount::create($account);

            $portfolio = TIServices::portfolio($account->profile);
            $instruments = TIServices::instruments($account->profile);

            /**
             * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
             */
            $response = $portfolio->getPortfolio($account->accountId);


            $shares_price = Price::createFromMoneyValue($response->getTotalAmountShares());
            $bonds_price = Price::createFromMoneyValue($response->getTotalAmountBonds());
            $etf_price = Price::createFromMoneyValue($response->getTotalAmountETF());
            $futures_price = Price::createFromMoneyValue($response->getTotalAmountFutures());
            $currencies = Price::createFromMoneyValue($response->getTotalAmountCurrencies());

            $total_portfolio_volume = $shares_price->asDecimal() + $bonds_price->asDecimal() + $etf_price->asDecimal() + $futures_price->asDecimal() + $currencies->asDecimal();

            echo 'Общая оценка стоимости портфеля: ' . NumbersHelper::printFloat($total_portfolio_volume) . PHP_EOL . PHP_EOL;

            echo 'Акции: ' . $shares_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $shares_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Облигации: ' . $bonds_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $bonds_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Фонды: ' . $etf_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $etf_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Фьючерсы: ' . $futures_price->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $futures_price->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL;
            echo 'Деньги: ' . $currencies->asString(2) . ($total_portfolio_volume > 0 ? ' (' . NumbersHelper::printFloat(100 * $currencies->asDecimal() / $total_portfolio_volume, 2, false) . '%)' : '') . PHP_EOL . PHP_EOL;

            $shares_portfolio_volume = $shares_price->asDecimal();
            $bond_portfolio_volume = $bonds_price->asDecimal();

            $main_portfolio_volume = $shares_portfolio_volume + $bonds_price->asDecimal();


            echo 'Соотношение Акций / Облигаций: ' . ($main_portfolio_volume > 0 ? NumbersHelper::printFloat(100 * $shares_portfolio_volume / $main_portfolio_volume) . '% / ' . NumbersHelper::printFloat(100 * $bond_portfolio_volume / $main_portfolio_volume) . '%' : '- / -') . PHP_EOL . PHP_EOL;

            echo 'Оценка долей акций (В сравнении с IMOEX): ' . PHP_EOL;

            $positions = ArrayHelper::repeatedFieldToArray($response->getPositions());

            $positions_percentage = [];

            $imoex_weights = static::parsingImoexWeights();

            /** @var PortfolioPosition $position */
            foreach ($positions as $position) {
                if ($position->getInstrumentType() === 'share') {
                    $price = Price::createFromMoneyValue($position->getCurrentPrice());
                    $position_price = $price->asDecimal() * Quantity::createFromQuotation($position->getQuantity())->asDecimal();

                    $percentage = $shares_portfolio_volume > 0 ? 100 * $position_price / $shares_portfolio_volume : -1;
                    $imoex_percentage = $imoex_weights[$position->getTicker()] ?? -1;

                    unset($imoex_weights[$position->getTicker()]);

                    $positions_percentage[$position->getTicker()] = [
                        'price' => $position_price,
                        'percentage' => $percentage,
                        'imoex_percentage' => $imoex_percentage,
                        'diff' => $percentage > 0 && $imoex_percentage > 0 ? $percentage - $imoex_percentage : 0,
                    ];
                }
            }

            arsort($positions_percentage);
            arsort($imoex_weights);

            foreach ($imoex_weights as $ticker => $weight) {
                $positions_percentage[$ticker] = [
                    'price' => 0,
                    'percentage' => -1,
                    'imoex_percentage' => $weight,
                    'diff' => $weight,
                ];
            }

            printf("%-6s => %-10s | %6s => %6s (%s)",
                'TICKER',
                'SUM',
                'PORTF.',
                'IMOEX',
                'DIFF',
            );

            echo PHP_EOL;
            printf("================================================");

            echo PHP_EOL;

            foreach ($positions_percentage as $ticker => $value) {
                printf("%-5s => %10s | %6s => %6s (%s)",
                    $ticker,
                    NumbersHelper::printFloat($value['price'], 2, false),
                    ($value['percentage'] > 0 ? NumbersHelper::printFloat($value['percentage'], 2, false) : '---') . '%',
                    ($value['imoex_percentage'] > 0 ? NumbersHelper::printFloat($value['imoex_percentage'], 2, false) : '---') . '%',
                    ($value['diff'] > 0 ? '+' : '') . NumbersHelper::printFloat($value['diff'], 2, false) . '%',
                );

                echo PHP_EOL;
            }

            echo PHP_EOL;
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        static::stdoutEnd();
    }

    /**
     * Метод получения списка последних операций по портфелю
     *
     * Метод отправляет данные в TelegramBot
     *
     * @param string $account Идентификатор или алиас аккаунта (портфеля)
     *
     * @throws Exception
     */
    public function actionOperations(string $account): void
    {
        static::stdoutStart(__FUNCTION__);

        try {
            list($bot, $chat_id) = TelegramBot::create($account);

            if (!$bot || !$chat_id) {
                throw new Exception('Telegram bot is not configured for this account');
            }

            $account = TIAccount::create($account);
            $account_id = $account->accountId;

            echo 'Запрос операций по счету ' . $account_id . PHP_EOL;

            $tinkoff_api = TIServices::apiClient($account->profile);
            $tinkoff_instruments = TIServices::instruments($account->profile);

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
            static::processRequestStatus($status, true);

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

            if ($operations_to_notify) {
                list($response, $status) = $tinkoff_api->usersServiceClient->GetAccounts(new GetAccountsRequest())
                    ->wait()
                ;

                static::processRequestStatus($status, true);

                $account_name = '-';

                /** @var Account $account_model */
                /** @var GetAccountsResponse $response */
                foreach ($response->getAccounts() as $account_model) {
                    if ($account_model->getId() === $account_id) {
                        $account_name = $account_model->getName();

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

                    if ($quantity = $operation->getQuantity() - $operation->getQuantityRest()) {
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
                        $portfolio_currency = $this->portfolioMoney($account_id);

                        $message .= PHP_EOL . 'Свободных средств: ';

                        foreach ($portfolio_currency as $cur => $values) {
                            /** @var Price $available */
                            $available = $values['available'];
                            $available_decimal = $available->asDecimal();

                            /** @var Price $blocked */
                            $blocked = $values['blocked'];
                            $blocked_decimal = $blocked->asDecimal();

                            $message .= ' `' . static::escapeMarkdown($available_decimal . ' ' . $cur . ($blocked_decimal > 0 ? ' (Заблокировано: ' . $blocked_decimal . ' ' . $cur . ')' : '')) . '`' . PHP_EOL;
                        }
                    } catch (Throwable $e) {
                        var_dump($e->getMessage());
                    }

                    try {
                        $portfolio_money_etf = static::portfolioMoneyETF($account_id, static::tickersInstruments($account->profile, 'etf', static::MONEY_ETF));

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

        static::stdoutEnd();
    }

    /**
     * Метод выводит в stdout информацию о пополнениях указанного счета
     *
     * Метод выводит информацию с разбивкой по годам. Дополнительно за последний год запрашивается информация помесячно.
     *
     * @param string $account Идентификатор аккаунта/портфеля
     * @param int $from_year Начиная с какого года формировать статистику пополнений
     *
     * @return void
     *
     * TODO: Метод за каждый год/месяц делает отдельный API запрос, гораздо более разумно в реальном проекте оптимизировать этот кусок и
     *       запрашивать данные "большими" периодами с меньшим количеством запросов к API, а затем разбирать ответ по годам/месяцам.
     *       Для демонстрации функционала и моих нужд с такой "избыточностью" запросов к API было проще и быстрее.
     */
    public function actionFunding(string $account, int $from_year = 2021): void
    {
        static::stdoutStart(__FUNCTION__);

        try {
            $account = TIAccount::create($account);
            $tinkoff_api = TIServices::apiClient($account->profile);

            echo 'Запрашиваем информацию о пополнениях счета ' . $account->accountId . PHP_EOL;

            $current_year = (int) date('Y');
            $current_month = (int) date('m');

            $total = 0;
            $bot_message = '';

            for ($year = $from_year; $year <= $current_year; $year++) {
                $sum = 0;

                for ($month = 1; $month <= ($year < $current_year ? 12 : $current_month); $month++) {
                    $request = new OperationsRequest();

                    $request->setAccountId($account->accountId);
                    $request->setFrom((new Timestamp())->setSeconds(strtotime($year . "-" . ($month < 10 ? '0' : '') . $month . "-01 00:00:00")));

                    if ($month === 12) {
                        $request->setTo((new Timestamp())->setSeconds(strtotime(($year + 1) . "-01-01 00:00:00")));
                    } else {
                        $request->setTo((new Timestamp())->setSeconds(strtotime($year . "-" . ($month + 1 < 10 ? '0' : '') . ($month + 1) . "-01 00:00:00")));
                    }

                    $request->setState(OperationState::OPERATION_STATE_EXECUTED);

                    list($reply, $status) = $tinkoff_api->operationsServiceClient->GetOperations($request)
                        ->wait();

                    static::processRequestStatus($status, true);

                    /** @var OperationsResponse $reply */
                    /** @var Operation $operation */
                    foreach ($reply->getOperations() as $operation) {
                        if ($operation->getOperationType() === OperationType::OPERATION_TYPE_INPUT || $operation->getOperationType() === OperationType::OPERATION_TYPE_INP_MULTI) {
                            $sum += QuotationHelper::toDecimal($operation->getPayment());
                        }
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

                $request->setAccountId($account->accountId);
                $request->setFrom((new Timestamp())->setSeconds(strtotime($current_year . "-" . ($month < 10 ? '0' : '') . $month . "-01 00:00:00")));

                if ($month === 12) {
                    $request->setTo((new Timestamp())->setSeconds(strtotime(($current_year + 1) . "-01-01 00:00:00")));
                } else {
                    $request->setTo((new Timestamp())->setSeconds(strtotime($current_year . "-" . ($month + 1 < 10 ? '0' : '') . ($month + 1) . "-01 00:00:00")));
                }

                $request->setState(OperationState::OPERATION_STATE_EXECUTED);

                list($reply, $status) = $tinkoff_api->operationsServiceClient->GetOperations($request)
                    ->wait();

                static::processRequestStatus($status, true);

                $sum = 0;

                /** @var OperationsResponse $reply */
                /** @var Operation $operation */
                foreach ($reply->getOperations() as $operation) {
                    if ($operation->getOperationType() === OperationType::OPERATION_TYPE_INPUT || $operation->getOperationType() === OperationType::OPERATION_TYPE_INP_MULTI) {
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

        static::stdoutEnd();
    }

    /**
     * Метод демонстрирует возможность подписаться на стрим MarketDataStream для получения событий по свечам для инструмента с переданным тикером
     *
     * Получаются и выводятся в stdout события по минутным интервалам
     *
     * @param string $ticker Тикер инструмента для получения свечей
     * @param string $profile_alias Алиас профиля
     */
    public function actionCandlesStream(string $ticker, string $profile_alias = 'default'): void
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
            $profile = TIProfile::create($profile_alias);
            $tinkoff_api = TIServices::apiClient($profile);
            $tinkoff_instruments = TIServices::instruments($profile);

            echo 'Ищем инструмент с тикером ' . $ticker . PHP_EOL;

            $target_instrument = $tinkoff_instruments->searchByTicker($ticker);

            echo 'Инструмент найден: ' . $target_instrument->serializeToJsonString() . PHP_EOL;

            if ($target_instrument->getBuyAvailableFlag() === false) {
                echo 'Покупка сейчас не доступна' . PHP_EOL;

                return;
            } else {
                echo 'Покупка доступна' . PHP_EOL;
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
                                ->setInstrumentId($target_instrument->getFigi())
                        ])
                )
                ->setSubscribeCandlesRequest(
                    (new SubscribeCandlesRequest())
                        ->setSubscriptionAction(SubscriptionAction::SUBSCRIPTION_ACTION_SUBSCRIBE)
                        ->setInstruments([
                            (new CandleInstrument())
                                ->setInstrumentId($target_instrument->getFigi())
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
                    echo 'Событие стрима "last price": ' . $last_price->serializeToJsonString() . PHP_EOL;
                }

                if ($candle !== null) {
                    echo 'Событие стрима "candle": ' . $candle->serializeToJsonString() . PHP_EOL;
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
     * Метод для тестирования
     *
     * Выводит информацию о ETF по переданному тикеру в STDOUT
     *
     * @param string $account Алиас или идентификатор аккаунта
     * @param string|null $ticker Тикер
     */
    public function actionTest(string $account, string $ticker = null): void
    {
        static::stdoutStart(__FUNCTION__);

        try {
            $account = TIAccount::create($account);

            $portfolio = TIServices::portfolio($account->profile);

            var_dump($portfolio->getPortfolioMoney($account->accountId)['rub']['available']->asDecimal());

            if ($ticker) {
                $instruments = TIServices::instruments($account->profile);

                echo 'Ищем инструмент' . PHP_EOL;

                $target_instrument = $instruments->etfByTicker($ticker);

                echo 'Найден инструмент: ' . $target_instrument->serializeToJsonString() . PHP_EOL;
            }
        } catch (Throwable $e) {
            echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;

            Log::error('Error on action ' . __FUNCTION__ . ': ' . $e->getMessage(), static::MAIN_LOG_TARGET);
        }

        static::stdoutEnd();
    }
}
