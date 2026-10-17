<?php

declare(strict_types=1);

namespace Pspa\Youtube\Repository;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Pspa\Youtube\Model\Channel;
use Pspa\Youtube\Storage\IndexManager;

/** Единственное место, где код знает про индекс channels. */
final class ChannelRepository
{
    public function __construct(
        private readonly Client $client,
        private readonly VideoRepository $videos,
    ) {
    }

    public function save(Channel $channel, bool $refresh = false): void
    {
        $this->client->index([
            'index' => IndexManager::CHANNELS,
            'id' => $channel->channelId,
            'body' => $channel->toArray(),
            'refresh' => $refresh,
        ]);
    }

    public function find(string $channelId): ?Channel
    {
        try {
            $response = $this->client->get(['index' => IndexManager::CHANNELS, 'id' => $channelId]);
        } catch (ClientResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }

        return Channel::fromArray($response->asArray()['_source']);
    }

    /**
     * Все каналы (до $limit штук), по названию.
     *
     * @return list<Channel>
     */
    public function all(int $limit = 100): array
    {
        $response = $this->client->search([
            'index' => IndexManager::CHANNELS,
            'body' => [
                'size' => $limit,
                'query' => ['match_all' => new \stdClass()],
                'sort' => [['title.raw' => 'asc']],
            ],
        ])->asArray();

        return array_map(
            static fn (array $hit): Channel => Channel::fromArray($hit['_source']),
            $response['hits']['hits'],
        );
    }

    /**
     * Находит несколько каналов одним запросом.
     *
     * @param list<string> $channelIds
     * @return array<string, Channel> каналы по channel_id (отсутствующие пропускаются)
     */
    public function findMany(array $channelIds): array
    {
        if ($channelIds === []) {
            return [];
        }

        $response = $this->client->mget([
            'index' => IndexManager::CHANNELS,
            'body' => ['ids' => array_values($channelIds)],
        ])->asArray();

        $result = [];
        foreach ($response['docs'] as $doc) {
            if ($doc['found'] ?? false) {
                $result[$doc['_id']] = Channel::fromArray($doc['_source']);
            }
        }

        return $result;
    }

    /**
     * Удаляет канал. По умолчанию вместе с его видео, чтобы в базе не оставалось сирот.
     * Возвращает true, если канал существовал.
     */
    public function delete(string $channelId, bool $withVideos = true): bool
    {
        if ($withVideos) {
            $this->videos->deleteByChannel($channelId);
        }

        try {
            $this->client->delete([
                'index' => IndexManager::CHANNELS,
                'id' => $channelId,
                'refresh' => true,
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        }

        return true;
    }
}
