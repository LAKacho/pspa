<?php

declare(strict_types=1);

/**
 * Проверка паука без ключа YouTube: вместо настоящего API подставляется заглушка с готовыми данными.
 * Данные пишутся в отдельные документы SPIDER_TEST_* и удаляются в конце.
 * Запуск: docker exec pspa-php-1 php bin/spider_selftest.php
 */

use Pspa\Youtube\Model\Channel;
use Pspa\Youtube\Model\Video;
use Pspa\Youtube\Spider\Spider;
use Pspa\Youtube\Spider\YoutubeApi;
use Pspa\Youtube\Storage\IndexManager;

require __DIR__ . '/bootstrap.php';

$indexes->create(IndexManager::CHANNELS);
$indexes->create(IndexManager::VIDEOS);

$fakeApi = new class('fake') extends YoutubeApi {
    public function channel(string $channelId): ?array
    {
        return $channelId === 'SPIDER_TEST_MISSING' ? null : [
            'channel' => new Channel($channelId, 'Тестовый канал паука', 'описание', 'RU', 1000),
            'uploads' => 'PL_' . $channelId,
        ];
    }

    public function videos(string $channelId, string $uploadsPlaylistId, int $max): array
    {
        return array_map(
            static fn (int $i): Video => new Video("{$channelId}_v{$i}", $channelId, "Видео {$i}", likes: 100 * $i, dislikes: 10 * $i),
            range(1, min($max, 3)),
        );
    }

    public function searchChannels(string $query, int $max = 10): array
    {
        return ['SPIDER_TEST_A', 'SPIDER_TEST_B'];
    }
};

$spider = new Spider($fakeApi, $channels, $videos);
$ok = true;
$check = static function (string $name, bool $cond) use (&$ok): void {
    echo ($cond ? 'OK   ' : 'FAIL ') . $name . PHP_EOL;
    $ok = $ok && $cond;
};

$r = $spider->crawl(['SPIDER_TEST_A', 'SPIDER_TEST_MISSING'], 20);
$indexes->refresh(IndexManager::VIDEOS);
$check('crawl: сохранён 1 канал, 3 видео, 1 пропущен', $r['channels'] === 1 && $r['videos'] === 3 && count($r['skipped']) === 1);
$check('канал есть в индексе', $channels->find('SPIDER_TEST_A')?->title === 'Тестовый канал паука');
$check('видео есть в индексе', $videos->find('SPIDER_TEST_A_v2')?->likes === 200);
$check('сумма лайков/дизлайков', $stats->totalLikesDislikes('SPIDER_TEST_A') === ['videos' => 3, 'likes' => 600, 'dislikes' => 60]);

$r = $spider->crawlBySearch('что угодно', 5, 20);
$check('crawlBySearch: 2 канала', $r['channels'] === 2);

$spider->crawl(['SPIDER_TEST_A'], 20);
$indexes->refresh(IndexManager::VIDEOS);
$check('повторный обход не создаёт дубликатов', $stats->totalLikesDislikes('SPIDER_TEST_A')['videos'] === 3);

$channels->delete('SPIDER_TEST_A');
$channels->delete('SPIDER_TEST_B');
$indexes->refresh(IndexManager::VIDEOS);
$check('очистка: тестовых видео не осталось', $stats->totalLikesDislikes('SPIDER_TEST_A')['videos'] === 0);

exit($ok ? 0 : 1);
