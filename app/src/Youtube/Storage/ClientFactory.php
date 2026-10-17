<?php

declare(strict_types=1);

namespace Pspa\Youtube\Storage;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

/** Создаёт клиент Elasticsearch. Адрес берётся из переменной окружения ELASTICSEARCH_URL. */
final class ClientFactory
{
    public static function create(?string $url = null): Client
    {
        $url ??= getenv('ELASTICSEARCH_URL') ?: 'http://localhost:9200';

        return ClientBuilder::create()
            ->setHosts([$url])
            ->build();
    }
}
