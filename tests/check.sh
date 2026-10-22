#!/bin/sh
set -eu
php --version
php requirements.php
composer validate --strict
composer check-platform-reqs
composer audit --locked
find assets commands components config controllers environments helpers models tests views widgets -name '*.php' -print0 \
    | xargs -0 -n1 php -d error_reporting=E_ALL -l > /tmp/robot-lint.log 2>&1
for source in init yii requirements.php; do
    php -d error_reporting=E_ALL -l "$source" >> /tmp/robot-lint.log 2>&1
done
if grep -v '^No syntax errors detected in ' /tmp/robot-lint.log; then
    exit 1
fi
printf 'PHP lint passed: %s files\n' "$(wc -l < /tmp/robot-lint.log)"
composer test
composer analyse
vendor/bin/codecept build
vendor/bin/codecept run unit
php tests/container-smoke.php
php yii help > /tmp/robot-help.log
php yii help operation/buy > /tmp/robot-buy-help.log
php yii help app-schedule/run > /tmp/robot-schedule-help.log
