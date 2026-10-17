<?php

declare(strict_types=1);

namespace Pspa\Youtube\Repository;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Pspa\Youtube\Model\Channel;

class ChannelRepository
{
    public const INDEX = 'channels';

    public function __construct(
        private readonly Client $client,
        private readonly VideoRepository $videos,
    ) {}

    public function save(Channel $channel, bool $refresh = false): void
    {
        $this->client->index([
            'index' => self::INDEX,
            'id' => $channel->channelId,
            'body' => $channel->toArray(),
            'refresh' => $refresh,
        ]);
    }

    public function find(string $channelId): ?Channel
    {
        try {
            $response = $this->client->get([
                'index' => self::INDEX,
                'id' => $channelId,
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }

            throw $e;
        }

        return Channel::fromArray($response['_source']);
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
            'index' => self::INDEX,
            'body' => ['ids' => array_values($channelIds)],
        ]);

        $result = [];
        foreach ($response['docs'] as $doc) {
            if ($doc['found'] ?? false) {
                $result[$doc['_id']] = Channel::fromArray($doc['_source']);
            }
        }

        return $result;
    }

    public function delete(string $channelId, bool $withVideos = true): bool
    {
        if ($withVideos) {
            $this->videos->deleteByChannel($channelId);
        }

        try {
            $this->client->delete([
                'index' => self::INDEX,
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