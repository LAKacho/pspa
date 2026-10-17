<?php

declare(strict_types=1);

namespace Pspa\Youtube\Spider;

use DateTimeImmutable;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Pspa\Youtube\Model\Channel;
use Pspa\Youtube\Model\Video;
use RuntimeException;

/**
 * Клиент YouTube Data API v3 (нужен ключ API) и сервиса Return YouTube Dislike.
 * YouTube с 2021 года не отдаёт дизлайки через свой API, поэтому они берутся из стороннего сервиса.
 */
class YoutubeApi
{
    private const API_URL = 'https://www.googleapis.com/youtube/v3/';
    private const DISLIKE_URL = 'https://returnyoutubedislikeapi.com/votes';

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClient $http = new HttpClient(['timeout' => 15]),
    ) {}

    /**
     * Данные канала и id плейлиста его загрузок (null, если канала нет).
     *
     * @return array{channel: Channel, uploads: ?string}|null
     */
    public function channel(string $channelId): ?array
    {
        $data = $this->get('channels', [
            'part' => 'snippet,statistics,contentDetails',
            'id' => $channelId,
        ]);
        $item = $data['items'][0] ?? null;
        if ($item === null) {
            return null;
        }

        return [
            'channel' => new Channel(
                channelId: $item['id'],
                title: $item['snippet']['title'],
                description: $item['snippet']['description'] ?? '',
                country: $item['snippet']['country'] ?? null,
                subscribers: (int) ($item['statistics']['subscriberCount'] ?? 0),
                createdAt: new DateTimeImmutable($item['snippet']['publishedAt']),
            ),
            'uploads' => $item['contentDetails']['relatedPlaylists']['uploads'] ?? null,
        ];
    }

    /**
     * Последние $max видео канала со статистикой и дизлайками.
     *
     * @return list<Video>
     */
    public function videos(string $channelId, string $uploadsPlaylistId, int $max): array
    {
        $ids = [];
        $pageToken = null;
        do {
            $page = $this->get('playlistItems', array_filter([
                'part' => 'contentDetails',
                'playlistId' => $uploadsPlaylistId,
                'maxResults' => min(50, $max - count($ids)),
                'pageToken' => $pageToken,
            ]));
            foreach ($page['items'] ?? [] as $item) {
                $ids[] = $item['contentDetails']['videoId'];
            }
            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken !== null && count($ids) < $max);

        $videos = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $data = $this->get('videos', [
                'part' => 'snippet,statistics',
                'id' => implode(',', $chunk),
            ]);
            foreach ($data['items'] ?? [] as $item) {
                $videos[] = new Video(
                    videoId: $item['id'],
                    channelId: $channelId,
                    title: $item['snippet']['title'],
                    description: $item['snippet']['description'] ?? '',
                    tags: $item['snippet']['tags'] ?? [],
                    views: (int) ($item['statistics']['viewCount'] ?? 0),
                    likes: (int) ($item['statistics']['likeCount'] ?? 0),
                    dislikes: $this->dislikes($item['id']),
                    publishedAt: new DateTimeImmutable($item['snippet']['publishedAt']),
                );
            }
        }

        return $videos;
    }

    /**
     * Id каналов, найденных поиском по тексту (стоит 100 единиц квоты).
     *
     * @return list<string>
     */
    public function searchChannels(string $query, int $max = 10): array
    {
        $data = $this->get('search', [
            'part' => 'snippet',
            'type' => 'channel',
            'q' => $query,
            'maxResults' => min(50, $max),
        ]);

        return array_map(static fn (array $i): string => $i['snippet']['channelId'], $data['items'] ?? []);
    }

    /** Число дизлайков из Return YouTube Dislike. При ошибке сервиса возвращает 0. */
    public function dislikes(string $videoId): int
    {
        try {
            $response = $this->http->get(self::DISLIKE_URL, ['query' => ['videoId' => $videoId]]);
            $data = json_decode((string) $response->getBody(), true);

            return (int) ($data['dislikes'] ?? 0);
        } catch (GuzzleException) {
            return 0;
        }
    }

    private function get(string $method, array $query): array
    {
        try {
            $response = $this->http->get(self::API_URL . $method, ['query' => $query + ['key' => $this->apiKey]]);
        } catch (GuzzleException $e) {
            throw new RuntimeException("Ошибка YouTube API ({$method}): " . $e->getMessage(), 0, $e);
        }

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
