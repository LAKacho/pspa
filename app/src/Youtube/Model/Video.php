<?php

declare(strict_types=1);

namespace Pspa\Youtube\Model;

use DateTimeImmutable;

class Video
{
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
    ) {}

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
            'published_at' => $this->publishedAt?->format(DATE_ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['video_id'],
            (string) $data['channel_id'],
            (string) $data['title'],
            (string) ($data['description'] ?? ''),
            (array) ($data['tags'] ?? []),
            (int) ($data['views'] ?? 0),
            (int) ($data['likes'] ?? 0),
            (int) ($data['dislikes'] ?? 0),
            isset($data['published_at']) ? new DateTimeImmutable((string) $data['published_at']) : null,
        );
    }
}