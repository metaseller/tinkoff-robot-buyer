<?php

use app\models\TelegramBot;
use PHPUnit\Framework\TestCase;
use SonkoDmitry\Yii\TelegramBot\Component;
use TelegramBot\Api\Client;
use TelegramBot\Api\Exception as TelegramException;
use TelegramBot\Api\Events\Event;
use TelegramBot\Api\HttpException;
use TelegramBot\Api\Types\Update;

final class TelegramCompatibilityTest extends TestCase
{
    private array $params;
    private array $clients;

    protected function setUp(): void
    {
        $this->params = Yii::$app->params;
        $this->clients = (new ReflectionProperty(TelegramBot::class, '_clients'))->getValue();
        // Fail on vendor deprecations too; PHPUnit's source filter excludes vendor.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }, E_ALL);
        Yii::$app->params['telegram'] = [
            'test-account' => ['token' => '123456:offline-token', 'chat_id' => '987654'],
        ];
        (new ReflectionProperty(TelegramBot::class, '_clients'))->setValue(null, []);
    }

    protected function tearDown(): void
    {
        try {
            (new ReflectionProperty(TelegramBot::class, '_clients'))->setValue(null, $this->clients);
            Yii::$app->params = $this->params;
        } finally {
            restore_error_handler();
        }
    }

    public function testApplicationCreatesCachesAndRefreshesClientWithoutNetwork(): void
    {
        [$client, $chat] = TelegramBot::create('test-account');
        self::assertInstanceOf(Component::class, $client);
        self::assertSame('987654', $chat);
        self::assertSame($client, TelegramBot::create('123456')[0]);
        self::assertNotSame($client, TelegramBot::create('test-account', true)[0]);

        // Production uses these same methods to configure its proxy.
        $client->setCurlOption(CURLOPT_PROXY, 'http://127.0.0.1:1');
        $client->setCurlOption(CURLOPT_PROXYUSERPWD, 'offline:offline');
        unset($client); // Exercise the destructor under E_ALL on PHP 8.5.

        Yii::$app->params['telegram'] = [];
        self::assertSame([null, null], TelegramBot::create('test-account'));
    }

    public function testNotificationBuildsAndParsesTelegramRequestWithoutSending(): void
    {
        $client = new class(['apiToken' => '123456:offline-token']) extends Component {
            public array $requests = [];

            protected function executeCurl(array $options)
            {
                // Exercise PHP/cURL option validation, without curl_exec().
                curl_setopt_array($this->curl, $options);
                $this->requests[] = $options;
                return json_encode(['ok' => true, 'result' => [
                    'message_id' => 7,
                    'date' => 1,
                    'chat' => ['id' => 987654, 'type' => 'private'],
                    'text' => 'Offline message',
                ]], JSON_THROW_ON_ERROR);
            }
        };
        (new ReflectionProperty(TelegramBot::class, '_clients'))->setValue(null, ['test-account' => $client]);

        TelegramBot::notifyTelegram('test-account', 'Offline message', 'HTML');
        self::assertCount(1, $client->requests);
        $request = $client->requests[0];
        self::assertStringEndsWith('/sendMessage', $request[CURLOPT_URL]);
        self::assertTrue($request[CURLOPT_POST]);
        self::assertSame('987654', $request[CURLOPT_POSTFIELDS]['chat_id']);
        self::assertSame('Offline message', $request[CURLOPT_POSTFIELDS]['text']);
        self::assertSame('HTML', $request[CURLOPT_POSTFIELDS]['parse_mode']);
        self::assertSame(7, $client->sendMessage('987654', 'Offline message')->getMessageId());

        // BotApi::call() must also continue to accept an omitted/null payload.
        self::assertSame(7, $client->call('getMe')['message_id']);
        self::assertNull($client->requests[2][CURLOPT_POSTFIELDS]);
        unset($client);
    }

    public function testErrorsAndOptionalEventCallbacksRemainCompatible(): void
    {
        $previous = new TelegramException('Previous');
        $error = new HttpException('Rate limited', 429, $previous, ['retry_after' => 10]);
        self::assertSame($previous, $error->getPrevious());
        self::assertSame(['retry_after' => 10], $error->getParameters());
        self::assertNull((new HttpException('No previous'))->getPrevious());

        $client = new Client('123456:offline-token');
        self::assertSame($client, $client->on(static function (): void {}));
        $event = new Event(static fn (): string => 'handled');
        self::assertNull($event->getChecker());
        self::assertFalse($event->executeChecker(new Update()));
        self::assertSame('handled', $event->executeAction(new Update()));
        unset($client);
    }

    public function testEveryTelegramClassLoadsWithoutDeprecations(): void
    {
        $directory = dirname(__DIR__, 2) . '/vendor/telegram-bot/api/src/';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($directory), -4);
            $class = 'TelegramBot\\Api\\' . str_replace('/', '\\', $relative);
            self::assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class), $class);
        }
    }
}
