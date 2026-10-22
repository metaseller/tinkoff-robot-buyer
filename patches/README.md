# Telegram Bot API compatibility

`telegram-bot/api` 2.5.0 still declares four implicitly nullable parameters and
calls `curl_close()`, which is deprecated in PHP 8.5. Yii converts these notices
into exceptions, preventing Telegram clients from loading or shutting down.

`telegram-bot-api-php85.patch` adds explicit nullable types to `BotApi`, `Client`,
`HttpException` and `Events\Event`, and releases the cURL handle by dropping its
reference. It preserves the API, request data and proxy options.

Composer Patches applies the local patch during dependency installation, including
`composer install --no-dev --no-scripts`. Keep Composer plugins enabled and make
Git available. `patches.lock.json` pins the patch checksum. The Dockerfile copies
both the patch and its lock before installing dependencies.

After changing this patch on an existing checkout, run:

```sh
composer patches-relock
composer reinstall telegram-bot/api --prefer-dist --no-scripts --no-interaction
composer test
```

Use `reinstall --no-scripts` rather than `patches-repatch` on configured servers:
the latter invokes Composer install scripts, including Yii's local config setup.

Remove the patch when a stable upstream release contains both fixes, and keep
`TelegramCompatibilityTest` to cover application client creation, message
serialization, optional callbacks, HTTP errors and cleanup without network access.
