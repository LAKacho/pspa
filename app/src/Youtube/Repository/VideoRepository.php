<?php

declare(strict_types=1);

namespace Pspa\Youtube\Repository;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Pspa\Youtube\Model\Video;
use Pspa\Youtube\Storage\IndexManager;

/**
 * Единственное место, где код знает про индекс videos и его запросы:
 * добавление, удаление и агрегации по видео.
 */
final class VideoRepository
{
    /** Максимум каналов в одной агрегации по терминам. */
    private const MAX_CHANNELS = 10000;

    public function __construct(private readonly Client $client)
    {
    }

    public function save(Video $video, bool $refresh = false): void
    {
        $this->client->index([
            'index' => IndexManager::VIDEOS,
            'id' => $video->videoId,
            'body' => $video->toArray(),
            'refresh' => $refresh,
        ]);
    }

    public function find(string $videoId): ?Video
    {
        try {
            $response = $this->client->get(['index' => IndexManager::VIDEOS, 'id' => $videoId]);
        } catch (ClientResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }

        return Video::fromArray($response->asArray()['_source']);
    }

    /**
     * Видео канала (до $limit штук), новые первыми.
     *
     * @return list<Video>
     */
    public function byChannel(string $channelId, int $limit = 100): array
    {
        $response = $this->client->search([
            'index' => IndexManager::VIDEOS,
            'body' => [
                'size' => $limit,
                'query' => ['term' => ['channel_id' => $channelId]],
                'sort' => [['published_at' => 'desc']],
            ],
        ])->asArray();

        return array_map(
            static fn (array $hit): Video => Video::fromArray($hit['_source']),
            $response['hits']['hits'],
        );
    }

    /** Удаляет видео. Возвращает true, если оно существовало. */
    public function delete(string $videoId, bool $refresh = false): bool
    {
        try {
            $this->client->delete([
                'index' => IndexManager::VIDEOS,
                'id' => $videoId,
                'refresh' => $refresh,
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /** Удаляет все видео канала. Возвращает число удалённых документов. */
    public function deleteByChannel(string $channelId): int
    {
        $response = $this->client->deleteByQuery([
            'index' => IndexManager::VIDEOS,
            'body' => ['query' => ['term' => ['channel_id' => $channelId]]],
            'refresh' => true,
        ]);

        return (int) $response->asArray()['deleted'];
    }

    /**
     * Сумма лайков и дизлайков по всем видео канала.
     *
     * @return array{videos: int, likes: int, dislikes: int}
     */
    public function sumLikesDislikes(string $channelId): array
    {
        $response = $this->client->search([
            'index' => IndexManager::VIDEOS,
            'body' => [
                'size' => 0,
                'track_total_hits' => true,
                'query' => ['term' => ['channel_id' => $channelId]],
                'aggs' => [
                    'likes' => ['sum' => ['field' => 'likes']],
                    'dislikes' => ['sum' => ['field' => 'dislikes']],
                ],
            ],
        ])->asArray();

        return [
            'videos' => (int) $response['hits']['total']['value'],
            'likes' => (int) $response['aggregations']['likes']['value'],
            'dislikes' => (int) $response['aggregations']['dislikes']['value'],
        ];
    }

    /**
     * Топ каналов по отношению суммы лайков к сумме дизлайков.
     * Если дизлайков нет, делитель принимается равным 1 (иначе деление на ноль).
     * Всё считает сам Elasticsearch: terms по channel_id, sum лайков и дизлайков,
     * bucket_script для отношения и bucket_sort для выбора первых N.
     *
     * @return list<array{channel_id: string, videos: int, likes: int, dislikes: int, ratio: float}>
     */
    public function topChannelsByLikeRatio(int $limit): array
    {
        $response = $this->client->search([
            'index' => IndexManager::VIDEOS,
            'body' => [
                'size' => 0,
                'aggs' => [
                    'by_channel' => [
                        'terms' => ['field' => 'channel_id', 'size' => self::MAX_CHANNELS],
                        'aggs' => [
                            'likes' => ['sum' => ['field' => 'likes']],
                            'dislikes' => ['sum' => ['field' => 'dislikes']],
                            'ratio' => [
                                'bucket_script' => [
                                    'buckets_path' => ['l' => 'likes', 'd' => 'dislikes'],
                                    'script' => 'params.d == 0 ? params.l : params.l / params.d',
                                ],
                            ],
                            'top' => [
                                'bucket_sort' => [
                                    'sort' => [
                                        ['ratio' => ['order' => 'desc']],
                                        ['likes' => ['order' => 'desc']],
                                    ],
                                    'size' => $limit,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->asArray();

        return array_map(static fn (array $b): array => [
            'channel_id' => (string) $b['key'],
            'videos' => (int) $b['doc_count'],
            'likes' => (int) $b['likes']['value'],
            'dislikes' => (int) $b['dislikes']['value'],
            'ratio' => (float) $b['ratio']['value'],
        ], $response['aggregations']['by_channel']['buckets']);
    }
}
