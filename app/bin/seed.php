<?php

declare(strict_types=1);

/**
 * Пересоздаёт индексы channels и videos и заполняет их тестовыми данными.
 * Запуск: docker exec pspa-php-1 php bin/seed.php
 */

use Pspa\Youtube\Model\Channel;
use Pspa\Youtube\Model\Video;
use Pspa\Youtube\Storage\IndexManager;

require __DIR__ . '/bootstrap.php';

$indexes->recreate(IndexManager::CHANNELS);
$indexes->recreate(IndexManager::VIDEOS);

// Канал => [название, страна, подписчики, лайков на видео, дизлайков на видео]
$channelData = [
    'UC_tech'   => ['Tech Review',    'US', 1_200_000, 900, 30],
    'UC_cook'   => ['Кухня дома',     'RU',   450_000, 700, 70],
    'UC_games'  => ['Игровой мир',    'RU',   980_000, 800, 400],
    'UC_music'  => ['Music Planet',   'GB', 2_500_000, 1500, 100],
    'UC_news'   => ['Новости дня',    'RU',   300_000, 200, 600],
    'UC_nodis'  => ['Тихий канал',    'DE',    15_000, 120, 0],
];

$videosPerChannel = 8;
$now = new DateTimeImmutable();

foreach ($channelData as $channelId => [$title, $country, $subscribers, $likes, $dislikes]) {
    $channels->save(new Channel(
        channelId: $channelId,
        title: $title,
        description: "Описание канала «{$title}»",
        country: $country,
        subscribers: $subscribers,
        createdAt: $now->modify('-3 years'),
    ));

    for ($i = 1; $i <= $videosPerChannel; $i++) {
        // небольшой разброс, чтобы суммы не были круглыми
        $videos->save(new Video(
            videoId: "{$channelId}_v{$i}",
            channelId: $channelId,
            title: "{$title}: выпуск {$i}",
            description: "Видео {$i} канала {$title}",
            tags: [strtolower($country), 'выпуск-' . $i],
            views: ($likes + $dislikes) * 20 + $i * 100,
            likes: $likes + $i * 3,
            dislikes: $dislikes > 0 ? $dislikes + $i : 0,
            publishedAt: $now->modify("-{$i} weeks"),
        ));
    }
}

$indexes->refresh(IndexManager::CHANNELS);
$indexes->refresh(IndexManager::VIDEOS);

echo 'Создано каналов: ' . count($channelData) . ', видео: ' . count($channelData) * $videosPerChannel . PHP_EOL;
