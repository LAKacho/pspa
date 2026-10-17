<?php

declare(strict_types=1);

namespace Pspa\Youtube\Model;

use DateTimeImmutable;

final class Video
{
    /** @param list<string> $tags */
    public function __construct(
        public readonly string $videoId,
        public readonly string $channelId,
        public readonly string $title,
        public readonly string $description = '',
        public readonly array $tags = [],
        public readonly int $views = 0,
        public readonly int $likes = 0,
        public readonly int $dislikes = 0,
        public readonly ?DateTimeImmutable $publishedAt = null,
    ) {
    }

    /** Документ для Elasticsearch (имена полей как в app/es/videos.json). */
    public function toArray(): array
    {
        return [
            'video_id' => $this->videoId,
            'channel_id' => $this->channelId,
            'title' => $this->title,
            'description' => $this->description,
            'tags' => $this->tags,
            'views' => $this->views,
            'likes' => $this->likes,
            'dislikes' => $this->dislikes,
            'published_at' => ($this->publishedAt ?? new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            videoId: (string) $data['video_id'],
            channelId: (string) $data['channel_id'],
            title: (string) $data['title'],
            description: (string) ($data['description'] ?? ''),
            tags: array_values($data['tags'] ?? []),
            views: (int) ($data['views'] ?? 0),
            likes: (int) ($data['likes'] ?? 0),
            dislikes: (int) ($data['dislikes'] ?? 0),
            publishedAt: isset($data['published_at']) ? new DateTimeImmutable($data['published_at']) : null,
        );
    }
}
