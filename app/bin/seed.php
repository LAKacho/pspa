<?php

declare(strict_types=1);

/**
 * Пересоздаёт индексы channels и videos и заполняет их тестовыми данными.
 * Запуск: docker exec pspa-php-1 php bin/seed.php
 */

use Pspa\Youtube\Seeder;

require __DIR__ . '/bootstrap.php';

$result = (new Seeder($indexes, $channels, $videos))->run();

echo "Создано каналов: {$result['channels']}, видео: {$result['videos']}" . PHP_EOL;
