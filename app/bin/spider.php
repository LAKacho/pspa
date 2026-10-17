<?php

declare(strict_types=1);

/**
 * Паук: наполняет базу каналами и видео с YouTube.
 * Нужен ключ YouTube Data API v3 в переменной окружения YOUTUBE_API_KEY.
 *
 * Запуск:
 *   docker exec -e YOUTUBE_API_KEY=... pspa-php-1 php bin/spider.php channel <channel_id>[,<channel_id>...] [видео_на_канал]
 *   docker exec -e YOUTUBE_API_KEY=... pspa-php-1 php bin/spider.php search "<текст>" [каналов] [видео_на_канал]
 */

use Pspa\Youtube\Spider\Spider;
use Pspa\Youtube\Spider\YoutubeApi;
use Pspa\Youtube\Storage\IndexManager;

require __DIR__ . '/bootstrap.php';

$key = getenv('YOUTUBE_API_KEY');
if (!$key || count($argv) < 3 || !in_array($argv[1], ['channel', 'search'], true)) {
    fwrite(STDERR, "Использование: php bin/spider.php channel <id,id,...> [видео] | search \"<текст>\" [каналов] [видео]\n");
    fwrite(STDERR, "Нужна переменная окружения YOUTUBE_API_KEY.\n");
    exit(1);
}

$indexes->create(IndexManager::CHANNELS);
$indexes->create(IndexManager::VIDEOS);

$spider = new Spider(new YoutubeApi($key), $channels, $videos);
$log = static fn (string $m) => print($m . PHP_EOL);

if ($argv[1] === 'channel') {
    $result = $spider->crawl(explode(',', $argv[2]), (int) ($argv[3] ?? 20), $log);
} else {
    $result = $spider->crawlBySearch($argv[2], (int) ($argv[3] ?? 5), (int) ($argv[4] ?? 20), $log);
}

$indexes->refresh(IndexManager::CHANNELS);
$indexes->refresh(IndexManager::VIDEOS);

echo "Готово: каналов {$result['channels']}, видео {$result['videos']}, пропущено " . count($result['skipped']) . PHP_EOL;
