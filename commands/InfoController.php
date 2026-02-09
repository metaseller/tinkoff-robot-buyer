<?php

namespace app\commands;

use app\components\log\Log;
use app\components\traits\ProgressTrait;
use app\helpers\ArrayHelper;
use app\models\TInvestServices;
use Metaseller\TinkoffInvestApi2\helpers\QuotationHelper;
use Metaseller\TinkoffInvestApi2\providers\InstrumentsProvider;
use Throwable;
use Tinkoff\Invest\V1\Account;
use Tinkoff\Invest\V1\GetAccountsRequest;
use Tinkoff\Invest\V1\GetAccountsResponse;
use Tinkoff\Invest\V1\GetInfoRequest;
use Tinkoff\Invest\V1\GetInfoResponse;
use Tinkoff\Invest\V1\PortfolioPosition;
use Tinkoff\Invest\V1\PortfolioRequest;
use Tinkoff\Invest\V1\PortfolioResponse;

/**
 * Консольный контроллер, предназначенный для получения и вывода информации с T-Invest Api
 *
 * @package app\commands
 */
class InfoController extends BaseController
{
    use ProgressTrait;

    /**
     * Получение информации о пользователе
     *
     * Выводит данные в STDOUT
     *
     * @param string $profile Профиль
     */
    public function actionUser(string $profile = 'default'): void
    {
        static::stdoutStart(__FUNCTION__);

        try {
            $tinkoff_api = TInvestServices::createTinkoffApiClient($profile);

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
     * @param string $profile Профиль
     */
    public function actionAccounts(string $profile = 'default'): void
    {
        static::stdoutStart(__FUNCTION__);

        try {
            $tinkoff_api = TInvestServices::createTinkoffApiClient($profile);

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
            $portfolio = TInvestServices::portfolio($account);
            $instruments = TInvestServices::instruments($account);

            $positions = $portfolio->getPortfolioPositions($account_id)






            $tinkoff_api = TInvestServices::clientByAccount($account_id);
            $client = $tinkoff_api->operationsServiceClient;

            $request = new PortfolioRequest();
            $request->setAccountId($account_id);

            /**
             * @var PortfolioResponse $response - Получаем ответ, содержащий информацию о портфеле
             */
            list($response, $status) = $client->GetPortfolio($request)->wait();
            static::processRequestStatus($status, true);

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
}
