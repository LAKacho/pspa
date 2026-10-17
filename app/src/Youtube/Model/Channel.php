<?php

declare(strict_types=1);

namespace Pspa\Youtube\Model;

use DateTimeImmutable;

class Channel
{
    public function __construct(
        public readonly string $channelId,
        public readonly string $title,
        public readonly string $description = '',
        public readonly ?string $country = null,
        public readonly int $subscribers = 0,
        public readonly ?DateTimeImmutable $createdAt = null,
    ) {}

    public function toArray(): array
    {
        return [
            'channel_id' => $this->channelId,
            'title' => $this->title,
            'description' => $this->description,
            'country' => $this->country,
            'subscribers' => $this->subscribers,
            'created_at' => $this->createdAt?->format(DATE_ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['channel_id'],
            (string) $data['title'],
            (string) ($data['description'] ?? ''),
            isset($data['country']) ? (string) $data['country'] : null,
            (int) ($data['subscribers'] ?? 0),
            isset($data['created_at']) ? new DateTimeImmutable((string) $data['created_at']) : null,
        );
    }
}