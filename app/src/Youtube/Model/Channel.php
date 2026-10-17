<?php

declare(strict_types=1);

namespace Pspa\Youtube\Model;

use DateTimeImmutable;

final class Channel
{
    public function __construct(
        public readonly string $channelId,
        public readonly string $title,
        public readonly string $description = '',
        public readonly ?string $country = null,
        public readonly int $subscribers = 0,
        public readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    /** Документ для Elasticsearch (имена полей как в app/es/channels.json). */
    public function toArray(): array
    {
        return [
            'channel_id' => $this->channelId,
            'title' => $this->title,
            'description' => $this->description,
            'country' => $this->country,
            'subscribers' => $this->subscribers,
            'created_at' => ($this->createdAt ?? new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            channelId: (string) $data['channel_id'],
            title: (string) $data['title'],
            description: (string) ($data['description'] ?? ''),
            country: $data['country'] ?? null,
            subscribers: (int) ($data['subscribers'] ?? 0),
            createdAt: isset($data['created_at']) ? new DateTimeImmutable($data['created_at']) : null,
        );
    }
}
