<?php

declare(strict_types=1);

namespace Pspa\Youtube\Storage;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

final class ClientFactory
{
    public static function create(): Client
    {
        $url = (string) getenv('ELASTICSEARCH_URL');

        return ClientBuilder::create()
            ->setHosts([$url])
            ->build();
    }
}