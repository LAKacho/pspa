<?php

declare(strict_types=1);

/**
 * Удаляет канал вместе с его видео.
 * Запуск: docker exec pspa-php-1 php bin/delete.php <channel_id>
 */

require __DIR__ . '/bootstrap.php';

$channelId = $argv[1] ?? null;
if ($channelId === null) {
    fwrite(STDERR, "Использование: php bin/delete.php <channel_id>\n");
    exit(1);
}

$before = $stats->totalLikesDislikes($channelId)['videos'];
$deleted = $channels->delete($channelId);
$indexes->refresh('channels');
$indexes->refresh('videos');

echo $deleted
    ? "Канал {$channelId} удалён вместе с видео ({$before} шт.)" . PHP_EOL
    : "Канал {$channelId} не найден (видео удалено: {$before})" . PHP_EOL;
