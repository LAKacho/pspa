<?php

declare(strict_types=1);

/**
 * Паук: наполняет базу каналами и видео с YouTube. Ключ берётся из переменной YOUTUBE_API_KEY (файл .env).
 *
 * Запуск:
 *   docker exec pspa-php-1 php bin/spider.php channel <id,id,...> [видео_на_канал]
 *   docker exec pspa-php-1 php bin/spider.php search "<текст>" [каналов] [видео_на_канал]
 */

use Pspa\Youtube\Repository\ChannelRepository;
use Pspa\Youtube\Repository\VideoRepository;
use Pspa\Youtube\Spider\Spider;
use Pspa\Youtube\Spider\YoutubeApi;
use Pspa\Youtube\Storage\ClientFactory;

require __DIR__ . '/../vendor/autoload.php';

$key = getenv('YOUTUBE_API_KEY');
if (!$key || count($argv) < 3 || !in_array($argv[1], ['channel', 'search'], true)) {
    fwrite(STDERR, "Использование: php bin/spider.php channel <id,id,...> [видео] | search \"<текст>\" [каналов] [видео]\n");
    fwrite(STDERR, "Нужна переменная окружения YOUTUBE_API_KEY (файл .env).\n");
    exit(1);
}

$client = ClientFactory::create();
$videos = new VideoRepository($client);
$channels = new ChannelRepository($client, $videos);
$spider = new Spider(new YoutubeApi($key), $channels, $videos);
$log = static function (string $message): void {
    echo $message . PHP_EOL;
};

if ($argv[1] === 'channel') {
    $result = $spider->crawl(explode(',', $argv[2]), (int) ($argv[3] ?? 20), $log);
} else {
    $result = $spider->crawlBySearch($argv[2], (int) ($argv[3] ?? 5), (int) ($argv[4] ?? 20), $log);
}

$client->indices()->refresh(['index' => ChannelRepository::INDEX]);
$client->indices()->refresh(['index' => VideoRepository::INDEX]);

echo "Готово: каналов {$result['channels']}, видео {$result['videos']}, пропущено " . count($result['skipped']) . PHP_EOL;
