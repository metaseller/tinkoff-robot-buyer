<?php

return [
    'hostname' => getenv('REDIS_HOST') ?: 'localhost',
    'port' => 6379,
    'database' => 0,
];
