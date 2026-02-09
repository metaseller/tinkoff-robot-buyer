<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\helpers\ArrayHelper;
use app\helpers\DateTimeHelper;
use app\helpers\NumbersHelper;
use app\models\TInvestServices;
use DateInterval;
use DatePeriod;
use DateTime;
use DateTimeZone;
use Exception;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Timestamp;
use Metaseller\TinkoffInvestApi2\dto\Price;
use Metaseller\TinkoffInvestApi2\exceptions\InstrumentNotFoundException;
use Metaseller\TinkoffInvestApi2\exceptions\ValidateException;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use Metaseller\TinkoffInvestApi2\providers\InstrumentsProvider;
use SonkoDmitry\Yii\TelegramBot\Component as TelegramBotApi;
use stdClass;
use Throwable;
use Tinkoff\Invest\V1\Account;
use Tinkoff\Invest\V1\Bond;
use Tinkoff\Invest\V1\CancelOrderRequest;
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
use Tinkoff\Invest\V1\GetOrdersRequest;
use Tinkoff\Invest\V1\GetOrdersResponse;
use Tinkoff\Invest\V1\InstrumentsRequest;
use Tinkoff\Invest\V1\InstrumentStatus;
use Tinkoff\Invest\V1\InstrumentType;
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
use Tinkoff\Invest\V1\OrderState;
use Tinkoff\Invest\V1\OrderType;
use Tinkoff\Invest\V1\PortfolioPosition;
use Tinkoff\Invest\V1\PortfolioRequest;
use Tinkoff\Invest\V1\PortfolioResponse;
use Tinkoff\Invest\V1\PositionsMoney;
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
 * Консольный контроллер, предназначенный для получения и вывода информации с Tinkoff Invest Api
 *
 * @package app\commands
 */
class InfoController extends BaseController
{
    use ProgressTrait;

    /**
     * Консольное действие, которые выводит в stdout Информацию о пользователе
     *
     * @param string $credentials_alias Алиас токена доступа в файле {@see ./credentials.php}
     *
     * @return void
     */
    public function actionUser(string $credentials_alias = 'default'): void
    {
        Log::info('Start action ' . __FUNCTION__, static::MAIN_LOG_TARGET);

        ob_start();

        try {
            $tinkoff_api = TInvestServices::clientByAlias($credentials_alias);

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
            static::processRequestStatus($status, true);

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
}
