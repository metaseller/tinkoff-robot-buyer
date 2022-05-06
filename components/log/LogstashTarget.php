<?php

namespace app\components\log;

use Exception;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\log\Logger;
use yii\log\LogRuntimeException;
use yii\web\User;
use app\helpers\ArrayHelper;
use yii\log\FileTarget;

/**
 * Компонент цели лога, отвечающий за сохранение логов в Logstash
 *
 * @package app\components\log
 */
class LogstashTarget extends FileTarget
{
    /**
     * @inheritDoc
     */
    public $fileMode = 0775;

    /**
     * @inheritDoc
     */
    public $dirMode = 0775;

    /**
     * @inheritDoc
     */
    public $maxFileSize = 10240; // in KB

    /**
     * @inheritDoc
     */
    public $maxLogFiles = 10;

    /**
     * @var bool Признак необходимости логгировать информацию о текущем пользователе
     */
    public $logUser = false;

    /**
     * @var array Дополнительный контекст для лога
     */
    public $context = [];

    /**
     * @var string Файл по умолчанию, в который необходимо сохранить лог в случае ошибки
     */
    public $logFile = '@runtime/logs/app.log';

    /**
     * @inheritDoc
     */
    public function export()
    {
        try {
            foreach ($this->messages as $message) {
                fwrite(fopen('php://stdout', 'w'), $this->stdoutFormatMessage($message) . "\r\n");
            }
        } catch (Throwable $error) {
            $this->emergencyExport(
                [
                    'error' => $error->getMessage(),
                    'errorNumber' => $error->getCode(),
                    'trace' => $error->getTraceAsString(),
                ]
            );
        } finally {
            // TODO: Дополнительная запись локально в файл на первое время, потом убрать
            if (!defined('YII_ENV')) {
                $this->emergencyExport();
            }
        }
    }

    /**
     * @inheritDoc
     *
     * @throws InvalidConfigException
     * @throws LogRuntimeException
     */
    public function collect($messages, $final)
    {
        $this->messages = array_merge(
            $this->messages,
            $this->filterMessages($messages, $this->getLevels(), $this->categories, $this->except)
        );

        $count = count($this->messages);

        if ($count > 0 && ($final == true || ($this->exportInterval > 0 && $count >= $this->exportInterval))) {
            $this->addContextToMessages();
            $this->export();
            $this->messages = [];
        }
    }

    /**
     * @inheritDoc
     */
    public function stdoutFormatMessage($message)
    {
        return json_encode($this->prepareMessage($message), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @inheritDoc
     *
     * @throws Exception
     */
    public function formatMessage($message)
    {
        $formatted_message = '[' . Log::logRowNumber() . '] ' . parent::formatMessage($message);

        /**
         * Этот кусок вызывается для тех настроек логов, которые не логгируют значения GLOBALS
         */
        if (empty($this->logVars) && !Log::$is_global_constants_logged) {
            if (!empty($_POST)) {
                $formatted_message .= "\n    " . 'POST: ' . Log::logSerialize($_POST);
            }

            if (!empty($_GET)) {
                $formatted_message .= "\n    " . 'GET: ' . Log::logSerialize($_GET);
            }

            if (isset($_SERVER) && $url = ArrayHelper::getValue($_SERVER, 'REQUEST_URI')) {
                $formatted_message .= "\n    " . 'URL CALL: ' . $url;
            }

            Log::$is_global_constants_logged = true;
        }

        return $formatted_message;
    }

    /**
     * Метод для записи лога в файл, в случае непредвиденной ошибки
     *
     * @param array|null $data Дополнительная информация, которую нужно залоггировать. По умолчанию равно <code>null</code>
     *
     * @throws InvalidConfigException
     * @throws LogRuntimeException
     */
    public function emergencyExport(array $data = null): void
    {
        if ($data) {
            $this->emergencyPrepareMessages($data);
        }

        parent::export();
    }

    /**
     * Метод добавляет к сообщению лога дополнительную контекстную информацию
     *
     * @throws InvalidConfigException
     */
    protected function addContextToMessages(): void
    {
        if (!$context = $this->getContextMessage()) {
            return;
        }

        foreach ($this->messages as &$message) {
            $message[0] = ArrayHelper::merge($this->parseText($message[0]), $context);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws InvalidConfigException
     */
    protected function getContextMessage()
    {
        $context = $this->context;

        if ($this->logUser === true && ($user = Yii::$app->get('user', false)) !== null) {
            /** @var User $user */
            $context['userId'] = $user->getId();
        }

        foreach ($this->logVars as $name) {
            if (empty($GLOBALS[$name])) {
                continue;
            }

            if ($name === '_SERVER') {
                $context[$name] = [];

                $server_log_attributes = [
                    'HOME', 'USER', 'HTTP_REFERER', 'HTTP_ORIGIN', 'HTTP_CONTENT_TYPE', 'HTTP_X_PROJECT_ID', 'HTTP_AUTHORIZATION',
                    'HTTP_USER_AGENT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_X_ROISTAT', 'HTTP_ACCEPT', 'HTTP_HOST', 'SERVER_SOFTWARE',
                    'REQUEST_SCHEME', 'SERVER_PROTOCOL', 'DOCUMENT_ROOT', 'DOCUMENT_URI', 'REQUEST_URI', 'REQUEST_METHOD', 'REQUEST_TIME',
                ];

                foreach ($server_log_attributes as $server_log_attribute) {
                    if ($value = ($GLOBALS[$name][$server_log_attribute] ?? null)) {
                        $context[$name][$server_log_attribute] = $value;
                    }
                }
            } else{
                $context[$name] = &$GLOBALS[$name];
            }
        }

        return $context;
    }

    /**
     * Метод, конвертирующий данные логгируемого сообщения в массив
     *
     * @param mixed $text Текст логгируемого сообщения
     *
     * @return array Массив данных логгируемого сообщения
     */
    protected function parseText($text): array
    {
        $type = gettype($text);

        switch ($type) {
            case 'array':
                return $text;
            case 'string':
                return ['@message' => $text];
            case 'object':
                return get_object_vars($text);
            default:
                return ['@message' => Log::logSerialize($text, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT, '')];
        }
    }

    /**
     * Метод, подготавливающий сообщение лога для сохранения
     *
     * @param array $message Логгируемое сообщение
     *
     * @return array Ассоциативный массив данных лога
     */
    protected function prepareMessage(array $message): array
    {
        list($text, $level, $category, $timestamp) = $message;

        $level = Logger::getLevelName($level);
        $timestamp = date('c', $timestamp);

        $result = ArrayHelper::merge($this->parseText($text), ['level' => $level, 'category' => $category, '@timestamp' => $timestamp]);

        if (isset($message[4])) {
            $result['trace'] = $message[4];
        }

        return $result;
    }

    /**
     * Метод, подготавливающий сообщение лога для сохранения в файл в случае ошибки
     *
     * @param array $data Дополнительная информация, которую нужно залоггировать
     */
    protected function emergencyPrepareMessages(array $data): void
    {
        foreach ($this->messages as &$message) {
            $message[0] = ArrayHelper::merge($message[0], ['emergency' => $data]);
        }
    }
}
