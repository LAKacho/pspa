<?php

declare(strict_types=1);

namespace Pspa\Youtube\Stats;

use Pspa\Youtube\Repository\ChannelRepository;
use Pspa\Youtube\Repository\VideoRepository;

/** Статистика по каналам. Сам в Elasticsearch не ходит: все запросы делают репозитории. */
final class ChannelStats
{
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly ChannelRepository $channels,
    ) {
    }

    /**
     * Суммарное количество лайков и дизлайков канала по всем его видео.
     *
     * @return array{videos: int, likes: int, dislikes: int}
     */
    public function totalLikesDislikes(string $channelId): array
    {
        return $this->videos->sumLikesDislikes($channelId);
    }

    /**
     * Топ N каналов с лучшим отношением лайков к дизлайкам.
     * Название канала подставляется из индекса channels (если канал там есть).
     *
     * @return list<array{channel_id: string, title: ?string, videos: int, likes: int, dislikes: int, ratio: float}>
     */
    public function topByLikeRatio(int $n): array
    {
        if ($n < 1) {
            throw new \InvalidArgumentException('N должно быть не меньше 1');
        }

        $top = $this->videos->topChannelsByLikeRatio($n);
        $known = $this->channels->findMany(array_column($top, 'channel_id'));

        return array_map(static fn (array $row): array => [
            'channel_id' => $row['channel_id'],
            'title' => ($known[$row['channel_id']] ?? null)?->title,
        ] + $row, $top);
    }
}
