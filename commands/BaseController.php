<?php

namespace app\commands;

use app\components\console\Controller;
use app\components\log\Log;
use app\components\traits\ProgressTrait;

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
}
