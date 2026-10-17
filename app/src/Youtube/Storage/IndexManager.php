<?php

declare(strict_types=1);

namespace Pspa\Youtube\Storage;

use Elastic\Elasticsearch\Client;

/** Создание и удаление индексов по JSON-описаниям из app/es/. */
final class IndexManager
{
    public const CHANNELS = 'channels';
    public const VIDEOS = 'videos';

    public function __construct(
        private readonly Client $client,
        private readonly string $mappingsDir = __DIR__ . '/../../../es',
    ) {
    }

    /** Создаёт индекс, если его нет. Возвращает true, если индекс был создан. */
    public function create(string $index): bool
    {
        if ($this->exists($index)) {
            return false;
        }

        $file = "{$this->mappingsDir}/{$index}.json";
        $body = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $this->client->indices()->create(['index' => $index, 'body' => $body]);

        return true;
    }

    public function delete(string $index): void
    {
        if ($this->exists($index)) {
            $this->client->indices()->delete(['index' => $index]);
        }
    }

    /** Пересоздаёт индекс с нуля (все данные удаляются). */
    public function recreate(string $index): void
    {
        $this->delete($index);
        $this->create($index);
    }

    public function exists(string $index): bool
    {
        return $this->client->indices()->exists(['index' => $index])->asBool();
    }

    /** Число документов в индексе (0, если индекса нет). */
    public function count(string $index): int
    {
        if (!$this->exists($index)) {
            return 0;
        }

        return (int) $this->client->count(['index' => $index])->asArray()['count'];
    }

    /** Делает записанные документы видимыми для поиска сразу. */
    public function refresh(string $index): void
    {
        $this->client->indices()->refresh(['index' => $index]);
    }
}
