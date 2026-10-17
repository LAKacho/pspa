<?php

declare(strict_types=1);

use Pspa\Youtube\Repository\ChannelRepository;
use Pspa\Youtube\Repository\VideoRepository;
use Pspa\Youtube\Stats\ChannelStats;
use Pspa\Youtube\Storage\ClientFactory;
use Pspa\Youtube\Storage\IndexManager;

require __DIR__ . '/../vendor/autoload.php';

$client = ClientFactory::create();
$indexes = new IndexManager($client);
$videos = new VideoRepository($client);
$channels = new ChannelRepository($client, $videos);
$stats = new ChannelStats($videos, $channels);
