<?php

declare(strict_types=1);

namespace Pspa\Youtube\Spider;

use Pspa\Youtube\Repository\ChannelRepository;
use Pspa\Youtube\Repository\VideoRepository;
use Throwable;

/** Паук: обходит каналы на YouTube и сохраняет каналы и их видео в Elasticsearch через репозитории. */
class Spider
{
    public function __construct(
        private readonly YoutubeApi $api,
        private readonly ChannelRepository $channels,
        private readonly VideoRepository $videos,
    ) {}

    /**
     * Обходит каналы по списку id. Повторный запуск обновляет документы (id документа = id на YouTube).
     *
     * @param list<string> $channelIds
     * @param (callable(string): void)|null $log
     * @return array{channels: int, videos: int, skipped: list<string>}
     */
    public function crawl(array $channelIds, int $videosPerChannel, ?callable $log = null): array
    {
        $log ??= static function (string $message): void {};
        $result = ['channels' => 0, 'videos' => 0, 'skipped' => []];

        foreach (array_unique($channelIds) as $channelId) {
            try {
                $found = $this->api->channel($channelId);
                if ($found === null || $found['uploads'] === null) {
                    $result['skipped'][] = $channelId;
                    $log("Канал {$channelId}: не найден или без видео, пропускаю");
                    continue;
                }

                $this->channels->save($found['channel']);
                $videos = $this->api->videos($channelId, $found['uploads'], $videosPerChannel);
                foreach ($videos as $video) {
                    $this->videos->save($video);
                }

                $result['channels']++;
                $result['videos'] += count($videos);
                $log("Канал {$found['channel']->title}: сохранено видео " . count($videos));
            } catch (Throwable $e) {
                $result['skipped'][] = $channelId;
                $log("Канал {$channelId}: ошибка, пропускаю ({$e->getMessage()})");
            }
        }

        return $result;
    }

    /**
     * Находит каналы поиском по тексту и обходит их.
     *
     * @param (callable(string): void)|null $log
     * @return array{channels: int, videos: int, skipped: list<string>}
     */
    public function crawlBySearch(string $query, int $maxChannels, int $videosPerChannel, ?callable $log = null): array
    {
        return $this->crawl($this->api->searchChannels($query, $maxChannels), $videosPerChannel, $log);
    }
}
