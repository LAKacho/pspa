<?php

declare(strict_types=1);

/**
 * Статистика по каналам.
 * Запуск: docker exec pspa-php-1 php bin/stats.php [N] [channel_id]
 *   N          сколько каналов показать в топе (по умолчанию 3)
 *   channel_id канал для суммы лайков и дизлайков (по умолчанию UC_tech)
 */

require __DIR__ . '/bootstrap.php';

$n = (int) ($argv[1] ?? 3);
$channelId = $argv[2] ?? 'UC_tech';

$total = $stats->totalLikesDislikes($channelId);
echo "Канал {$channelId}: видео {$total['videos']}, лайков {$total['likes']}, дизлайков {$total['dislikes']}" . PHP_EOL . PHP_EOL;

echo "Топ {$n} каналов по отношению лайков к дизлайкам:" . PHP_EOL;
foreach ($stats->topByLikeRatio($n) as $i => $row) {
    printf(
        "%d. %-14s (%s)  лайков %5d  дизлайков %5d  отношение %.2f%s\n",
        $i + 1,
        $row['title'] ?? '?',
        $row['channel_id'],
        $row['likes'],
        $row['dislikes'],
        $row['ratio'],
        $row['dislikes'] === 0 ? '  (дизлайков нет, делитель = 1)' : '',
    );
}
