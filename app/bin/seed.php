<?php

declare(strict_types=1);

/**
 * Заполняет индексы тестовыми каналами и видео и показывает статистику.
 * Запуск: docker exec pspa-php-1 php bin/seed.php
 */

use Pspa\Youtube\Model\Channel;
use Pspa\Youtube\Model\Video;
use Pspa\Youtube\Repository\ChannelRepository;
use Pspa\Youtube\Repository\VideoRepository;
use Pspa\Youtube\Stats\ChannelStats;
use Pspa\Youtube\Storage\ClientFactory;

require __DIR__ . '/../vendor/autoload.php';

$client = ClientFactory::create();
$videos = new VideoRepository($client);
$channels = new ChannelRepository($client, $videos);
$stats = new ChannelStats($videos, $channels);

// id канала => [название, страна, подписчики, лайков на видео, дизлайков на видео]
$data = [
    'UC_tech'  => ['Tech Review',  'US', 1_200_000, 900, 30],
    'UC_cook'  => ['Кухня дома',   'RU',   450_000, 700, 70],
    'UC_games' => ['Игровой мир',  'RU',   980_000, 800, 400],
    'UC_music' => ['Music Planet', 'GB', 2_500_000, 1500, 100],
    'UC_news'  => ['Новости дня',  'RU',   300_000, 200, 600],
    'UC_nodis' => ['Тихий канал',  'DE',    15_000, 120, 0],   // дизлайков нет
];

foreach ($data as $id => [$title, $country, $subscribers, $likes, $dislikes]) {
    $channels->save(new Channel($id, $title, "Описание канала «{$title}»", $country, $subscribers, new DateTimeImmutable('-3 years')));

    for ($i = 1; $i <= 8; $i++) {
        $videos->save(new Video(
            videoId: "{$id}_v{$i}",
            channelId: $id,
            title: "{$title}: выпуск {$i}",
            tags: [strtolower($country), "выпуск-{$i}"],
            views: ($likes + $dislikes) * 20 + $i * 100,
            likes: $likes + $i * 3,
            dislikes: $dislikes > 0 ? $dislikes + $i : 0,
            publishedAt: new DateTimeImmutable("-{$i} weeks"),
        ), refresh: $i === 8);
    }
}

echo 'Загружено каналов: ' . count($data) . ', видео: ' . count($data) * 8 . PHP_EOL . PHP_EOL;

$total = $stats->totalLikesDislikes('UC_tech');
echo "UC_tech: видео {$total['videos']}, лайков {$total['likes']}, дизлайков {$total['dislikes']}" . PHP_EOL . PHP_EOL;

echo 'Топ 3 по отношению лайков к дизлайкам:' . PHP_EOL;
foreach ($stats->topByLikeRatio(3) as $i => $row) {
    printf("%d. %s (%s): лайков %d, дизлайков %d, отношение %.2f\n", $i + 1, $row['title'], $row['channel_id'], $row['likes'], $row['dislikes'], $row['ratio']);
}
