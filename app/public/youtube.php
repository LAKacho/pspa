<?php

declare(strict_types=1);

use Pspa\Youtube\Model\Channel;
use Pspa\Youtube\Model\Video;
use Pspa\Youtube\Seeder;
use Pspa\Youtube\Storage\IndexManager;

require __DIR__ . '/../bin/bootstrap.php';

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function redirect(string $msg, string $type = 'ok', string $extra = ''): never
{
    header('Location: /youtube.php?' . http_build_query(['msg' => $msg, 'type' => $type]) . $extra);
    exit;
}

$error = null;
try {
    $client->ping();
    $indexes->create(IndexManager::CHANNELS);
    $indexes->create(IndexManager::VIDEOS);
} catch (Throwable $ex) {
    $error = 'Elasticsearch недоступен: ' . $ex->getMessage();
}

// ---- Действия (POST, затем редирект) ----
if ($error === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'seed':
                $r = (new Seeder($indexes, $channels, $videos))->run();
                redirect("Тестовые данные созданы: каналов {$r['channels']}, видео {$r['videos']}");
            case 'add_channel':
                $id = trim($_POST['channel_id'] ?? '');
                $title = trim($_POST['title'] ?? '');
                if ($id === '' || $title === '') {
                    redirect('Укажите id и название канала', 'err');
                }
                $channels->save(new Channel($id, $title, trim($_POST['description'] ?? ''), trim($_POST['country'] ?? '') ?: null, max(0, (int) ($_POST['subscribers'] ?? 0))), refresh: true);
                redirect("Канал «{$title}» сохранён");
            case 'add_video':
                $id = trim($_POST['video_id'] ?? '');
                $channelId = trim($_POST['channel_id'] ?? '');
                $title = trim($_POST['title'] ?? '');
                if ($id === '' || $channelId === '' || $title === '') {
                    redirect('Укажите id видео, канал и название', 'err');
                }
                $tags = array_values(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))));
                $videos->save(new Video($id, $channelId, $title, '', $tags, max(0, (int) ($_POST['views'] ?? 0)), max(0, (int) ($_POST['likes'] ?? 0)), max(0, (int) ($_POST['dislikes'] ?? 0))), refresh: true);
                redirect("Видео «{$title}» сохранено", 'ok', '&channel=' . urlencode($channelId));
            case 'delete_channel':
                $id = (string) ($_POST['channel_id'] ?? '');
                $channels->delete($id);
                $indexes->refresh(IndexManager::VIDEOS);
                redirect("Канал {$id} удалён вместе с видео");
            case 'delete_video':
                $id = (string) ($_POST['video_id'] ?? '');
                $channelId = (string) ($_POST['channel_id'] ?? '');
                $videos->delete($id, refresh: true);
                redirect("Видео {$id} удалено", 'ok', '&channel=' . urlencode($channelId));
        }
    } catch (Throwable $ex) {
        redirect('Ошибка: ' . $ex->getMessage(), 'err');
    }
}

// ---- Данные для страницы ----
$n = max(1, min(50, (int) ($_GET['n'] ?? 3)));
$selected = (string) ($_GET['channel'] ?? '');
$top = $allChannels = $channelVideos = $rows = [];
$selectedTotal = null;
$selectedChannel = null;
$counts = ['channels' => 0, 'videos' => 0];

if ($error === null) {
    try {
        $counts = ['channels' => $indexes->count(IndexManager::CHANNELS), 'videos' => $indexes->count(IndexManager::VIDEOS)];
        $top = $stats->topByLikeRatio($n);
        $allChannels = $channels->all(200);

        // Таблица каналов: суммы по видео + каналы без видео
        $byId = [];
        foreach ($videos->topChannelsByLikeRatio(10000) as $r) {
            $byId[$r['channel_id']] = $r;
        }
        foreach ($allChannels as $c) {
            $r = $byId[$c->channelId] ?? ['videos' => 0, 'likes' => 0, 'dislikes' => 0, 'ratio' => 0.0];
            $rows[] = ['channel' => $c] + $r;
        }

        if ($selected !== '') {
            $selectedChannel = $channels->find($selected);
            $selectedTotal = $stats->totalLikesDislikes($selected);
            $channelVideos = $videos->byChannel($selected, 100);
        }
    } catch (Throwable $ex) {
        $error = 'Ошибка при чтении данных: ' . $ex->getMessage();
    }
}

$msg = (string) ($_GET['msg'] ?? '');
$msgType = ($_GET['type'] ?? 'ok') === 'err' ? 'err' : 'ok';

function bar(int $likes, int $dislikes): string
{
    $total = $likes + $dislikes;
    $pct = $total > 0 ? round($likes / $total * 100) : 0;

    return '<div class="bar" title="' . e("лайков {$likes}, дизлайков {$dislikes}") . '"><span style="width:' . $pct . '%"></span></div>';
}

function fmt(int|float $v): string
{
    return number_format($v, 0, ',', ' ');
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Анализ YouTube-каналов</title>
<style>
:root{--bg:#f6f7f9;--card:#fff;--text:#1c2024;--muted:#6b7280;--line:#e5e7eb;--accent:#2563eb;--good:#16a34a;--bad:#dc2626}
@media (prefers-color-scheme:dark){:root{--bg:#0f1115;--card:#181b21;--text:#e6e8eb;--muted:#9aa3af;--line:#2a2f38;--accent:#60a5fa;--good:#4ade80;--bad:#f87171}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:1100px;margin:0 auto;padding:24px 16px 60px}
h1{font-size:24px;margin:0 0 4px}h2{font-size:17px;margin:0 0 12px}
.sub{color:var(--muted);margin:0 0 20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:18px;margin-bottom:18px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.stat{border:1px solid var(--line);border-radius:8px;padding:12px}
.stat b{display:block;font-size:22px}.stat span{color:var(--muted);font-size:13px}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}
.bar{height:8px;width:110px;background:var(--bad);border-radius:4px;overflow:hidden;display:inline-block;vertical-align:middle}
.bar span{display:block;height:100%;background:var(--good)}
a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
input,select,button{font:inherit;padding:7px 10px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--text)}
button{cursor:pointer;background:var(--accent);color:#fff;border-color:var(--accent)}
button.danger{background:transparent;color:var(--bad);border-color:var(--bad);padding:3px 9px;font-size:13px}
button.plain{background:transparent;color:var(--accent)}
form.inline{display:inline;margin:0}
.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.forms{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px}
.forms label{display:block;font-size:13px;color:var(--muted);margin-top:8px}
.forms input,.forms select{width:100%}
.msg{padding:10px 14px;border-radius:8px;margin-bottom:18px}
.msg.ok{background:color-mix(in srgb,var(--good) 15%,transparent);border:1px solid var(--good)}
.msg.err{background:color-mix(in srgb,var(--bad) 15%,transparent);border:1px solid var(--bad)}
.rank{display:inline-block;width:24px;height:24px;line-height:24px;text-align:center;border-radius:50%;background:var(--accent);color:#fff;font-size:13px;margin-right:8px}
.muted{color:var(--muted)}
.scroll{overflow-x:auto}
</style>
</head>
<body>
<main>
<h1>Анализ YouTube-каналов</h1>
<p class="sub">Elasticsearch: индексы <code>channels</code> и <code>videos</code>. <a href="/cinema.php">Кинотеатр</a> · <a href="/email.php">Email</a></p>

<?php if ($msg !== ''): ?><div class="msg <?= $msgType ?>"><?= e($msg) ?></div><?php endif; ?>
<?php if ($error !== null): ?>
  <div class="msg err"><?= e($error) ?><br>Проверьте, что запущен контейнер: <code>docker compose -f docker-compose.yml up -d</code></div>
<?php else: ?>

<section class="card">
  <div class="grid">
    <div class="stat"><b><?= fmt($counts['channels']) ?></b><span>каналов в индексе</span></div>
    <div class="stat"><b><?= fmt($counts['videos']) ?></b><span>видео в индексе</span></div>
    <div class="stat"><b style="color:var(--good)">подключен</b><span>Elasticsearch</span></div>
  </div>
  <form method="post" class="row" style="margin-top:14px">
    <input type="hidden" name="action" value="seed">
    <button type="submit" onclick="return confirm('Индексы будут пересозданы, текущие данные удалятся. Продолжить?')">Заполнить тестовыми данными</button>
    <span class="muted">пересоздаёт индексы: 6 каналов, 48 видео</span>
  </form>
</section>

<section class="card">
  <h2>Топ каналов по отношению лайков к дизлайкам</h2>
  <form method="get" class="row" style="margin-bottom:12px">
    <label for="n">Сколько показать (N):</label>
    <input id="n" type="number" name="n" min="1" max="50" value="<?= $n ?>" style="width:80px">
    <?php if ($selected !== ''): ?><input type="hidden" name="channel" value="<?= e($selected) ?>"><?php endif; ?>
    <button type="submit">Показать</button>
    <span class="muted">отношение = сумма лайков / сумма дизлайков (если дизлайков нет, делитель 1)</span>
  </form>
  <?php if ($top === []): ?>
    <p class="muted">Данных пока нет. Нажмите «Заполнить тестовыми данными».</p>
  <?php else: ?>
  <div class="scroll"><table>
    <tr><th>Канал</th><th class="n">Видео</th><th class="n">Лайки</th><th class="n">Дизлайки</th><th>Доля лайков</th><th class="n">Отношение</th></tr>
    <?php foreach ($top as $i => $r): ?>
      <tr>
        <td><span class="rank"><?= $i + 1 ?></span><a href="?n=<?= $n ?>&channel=<?= urlencode($r['channel_id']) ?>#channel"><?= e($r['title'] ?? $r['channel_id']) ?></a></td>
        <td class="n"><?= fmt($r['videos']) ?></td>
        <td class="n"><?= fmt($r['likes']) ?></td>
        <td class="n"><?= fmt($r['dislikes']) ?></td>
        <td><?= bar($r['likes'], $r['dislikes']) ?></td>
        <td class="n"><b><?= number_format($r['ratio'], 2, ',', ' ') ?></b><?= $r['dislikes'] === 0 ? ' <span class="muted" title="дизлайков нет, делитель = 1">*</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Все каналы</h2>
  <?php if ($rows === []): ?>
    <p class="muted">Каналов нет.</p>
  <?php else: ?>
  <div class="scroll"><table>
    <tr><th>Канал</th><th>Страна</th><th class="n">Подписчики</th><th class="n">Видео</th><th class="n">Лайки</th><th class="n">Дизлайки</th><th></th></tr>
    <?php foreach ($rows as $r): /** @var Channel $c */ $c = $r['channel']; ?>
      <tr>
        <td><a href="?n=<?= $n ?>&channel=<?= urlencode($c->channelId) ?>#channel"><?= e($c->title) ?></a><br><span class="muted"><?= e($c->channelId) ?></span></td>
        <td><?= e($c->country ?? '—') ?></td>
        <td class="n"><?= fmt($c->subscribers) ?></td>
        <td class="n"><?= fmt($r['videos']) ?></td>
        <td class="n"><?= fmt($r['likes']) ?></td>
        <td class="n"><?= fmt($r['dislikes']) ?></td>
        <td class="n">
          <form method="post" class="inline" onsubmit="return confirm('Удалить канал и все его видео?')">
            <input type="hidden" name="action" value="delete_channel">
            <input type="hidden" name="channel_id" value="<?= e($c->channelId) ?>">
            <button class="danger" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($selected !== ''): ?>
<section class="card" id="channel">
  <h2>Канал: <?= e($selectedChannel?->title ?? $selected) ?> <span class="muted">(<?= e($selected) ?>)</span></h2>
  <div class="grid" style="margin-bottom:14px">
    <div class="stat"><b><?= fmt($selectedTotal['videos']) ?></b><span>видео</span></div>
    <div class="stat"><b style="color:var(--good)"><?= fmt($selectedTotal['likes']) ?></b><span>лайков (сумма)</span></div>
    <div class="stat"><b style="color:var(--bad)"><?= fmt($selectedTotal['dislikes']) ?></b><span>дизлайков (сумма)</span></div>
  </div>
  <?php if ($channelVideos === []): ?>
    <p class="muted">У канала нет видео.</p>
  <?php else: ?>
  <div class="scroll"><table>
    <tr><th>Видео</th><th>Теги</th><th class="n">Просмотры</th><th class="n">Лайки</th><th class="n">Дизлайки</th><th>Опубликовано</th><th></th></tr>
    <?php foreach ($channelVideos as $v): ?>
      <tr>
        <td><?= e($v->title) ?><br><span class="muted"><?= e($v->videoId) ?></span></td>
        <td class="muted"><?= e(implode(', ', $v->tags)) ?></td>
        <td class="n"><?= fmt($v->views) ?></td>
        <td class="n"><?= fmt($v->likes) ?></td>
        <td class="n"><?= fmt($v->dislikes) ?></td>
        <td><?= e($v->publishedAt?->format('d.m.Y')) ?></td>
        <td class="n">
          <form method="post" class="inline" onsubmit="return confirm('Удалить видео?')">
            <input type="hidden" name="action" value="delete_video">
            <input type="hidden" name="video_id" value="<?= e($v->videoId) ?>">
            <input type="hidden" name="channel_id" value="<?= e($selected) ?>">
            <button class="danger" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="forms">
  <section class="card">
    <h2>Добавить канал</h2>
    <form method="post">
      <input type="hidden" name="action" value="add_channel">
      <label>ID канала<input name="channel_id" required placeholder="UC_demo"></label>
      <label>Название<input name="title" required placeholder="Мой канал"></label>
      <label>Описание<input name="description"></label>
      <label>Страна (код)<input name="country" maxlength="2" placeholder="RU"></label>
      <label>Подписчики<input type="number" name="subscribers" min="0" value="0"></label>
      <p><button type="submit">Сохранить канал</button></p>
    </form>
  </section>
  <section class="card">
    <h2>Добавить видео</h2>
    <form method="post">
      <input type="hidden" name="action" value="add_video">
      <label>ID видео<input name="video_id" required placeholder="demo_v1"></label>
      <label>Канал
        <select name="channel_id" required>
          <?php foreach ($allChannels as $c): ?>
            <option value="<?= e($c->channelId) ?>" <?= $c->channelId === $selected ? 'selected' : '' ?>><?= e($c->title) ?> (<?= e($c->channelId) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Название<input name="title" required></label>
      <label>Теги (через запятую)<input name="tags" placeholder="обзор, тест"></label>
      <div class="row">
        <label style="flex:1">Просмотры<input type="number" name="views" min="0" value="0"></label>
        <label style="flex:1">Лайки<input type="number" name="likes" min="0" value="0"></label>
        <label style="flex:1">Дизлайки<input type="number" name="dislikes" min="0" value="0"></label>
      </div>
      <p><button type="submit">Сохранить видео</button></p>
    </form>
  </section>
</div>

<?php endif; ?>
</main>
</body>
</html>
