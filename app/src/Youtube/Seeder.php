<?php

declare(strict_types=1);

namespace Pspa\Youtube;

use DateTimeImmutable;
use Pspa\Youtube\Model\Channel;
use Pspa\Youtube\Model\Video;
use Pspa\Youtube\Repository\ChannelRepository;
use Pspa\Youtube\Repository\VideoRepository;
use Pspa\Youtube\Storage\IndexManager;

/** Пересоздаёт индексы и заполняет их тестовыми каналами и видео. */
final class Seeder
{
    private const VIDEOS_PER_CHANNEL = 8;

    // Канал => [название, страна, подписчики, лайков на видео, дизлайков на видео]
    private const CHANNELS = [
        'UC_tech'  => ['Tech Review',  'US', 1_200_000, 900, 30],
        'UC_cook'  => ['Кухня дома',   'RU',   450_000, 700, 70],
        'UC_games' => ['Игровой мир',  'RU',   980_000, 800, 400],
        'UC_music' => ['Music Planet', 'GB', 2_500_000, 1500, 100],
        'UC_news'  => ['Новости дня',  'RU',   300_000, 200, 600],
        'UC_nodis' => ['Тихий канал',  'DE',    15_000, 120, 0],
    ];

    public function __construct(
        private readonly IndexManager $indexes,
        private readonly ChannelRepository $channels,
        private readonly VideoRepository $videos,
    ) {
    }

    /** @return array{channels: int, videos: int} */
    public function run(): array
    {
        $this->indexes->recreate(IndexManager::CHANNELS);
        $this->indexes->recreate(IndexManager::VIDEOS);

        $now = new DateTimeImmutable();

        foreach (self::CHANNELS as $channelId => [$title, $country, $subscribers, $likes, $dislikes]) {
            $this->channels->save(new Channel(
                channelId: $channelId,
                title: $title,
                description: "Описание канала «{$title}»",
                country: $country,
                subscribers: $subscribers,
                createdAt: $now->modify('-3 years'),
            ));

            for ($i = 1; $i <= self::VIDEOS_PER_CHANNEL; $i++) {
                // небольшой разброс, чтобы суммы не были круглыми
                $this->videos->save(new Video(
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

        $this->indexes->refresh(IndexManager::CHANNELS);
        $this->indexes->refresh(IndexManager::VIDEOS);

        return ['channels' => count(self::CHANNELS), 'videos' => count(self::CHANNELS) * self::VIDEOS_PER_CHANNEL];
    }
}
