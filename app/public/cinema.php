<?php

declare(strict_types=1);

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

try {
    $pdo = new PDO(
        'mysql:host=mysql;dbname=pspa_db;charset=utf8mb4',
        'pspa_user',
        'pspa_password',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(503);
    exit('База данных недоступна, попробуйте через несколько секунд.');
}

// Выручка по фильмам (тот же запрос, что в db/query.sql, но без LIMIT 1)
$revenue = $pdo->query(
    'SELECT m.id, m.title, COUNT(t.id) AS tickets, COALESCE(SUM(t.price_paid), 0) AS revenue
     FROM movie m
     LEFT JOIN screening s ON s.movie_id = m.id
     LEFT JOIN ticket t ON t.screening_id = s.id
     GROUP BY m.id, m.title
     ORDER BY revenue DESC, m.title'
)->fetchAll();
$top = $revenue[0] ?? null;
$maxRevenue = max(1.0, (float)($top['revenue'] ?? 0));

$screenings = $pdo->query(
    'SELECT s.id, s.starts_at, m.title, h.name AS hall,
            (SELECT COUNT(*) FROM seat WHERE hall_id = s.hall_id) AS total_seats,
            (SELECT COUNT(*) FROM ticket t WHERE t.screening_id = s.id) AS sold
     FROM screening s
     JOIN movie m ON m.id = s.movie_id
     JOIN hall h ON h.id = s.hall_id
     ORDER BY s.starts_at'
)->fetchAll();

$selectedId = (int)($_GET['screening'] ?? ($screenings[0]['id'] ?? 0));
$selected = null;
foreach ($screenings as $s) {
    if ((int)$s['id'] === $selectedId) {
        $selected = $s;
    }
}

$seatRows = [];
$legend = [];
if ($selected) {
    $stmt = $pdo->prepare(
        'SELECT se.row_no, se.seat_no, st.name AS type_name, sp.price, (t.id IS NOT NULL) AS sold
         FROM screening sc
         JOIN seat se ON se.hall_id = sc.hall_id
         JOIN seat_type st ON st.id = se.seat_type_id
         JOIN screening_price sp ON sp.screening_id = sc.id AND sp.seat_type_id = se.seat_type_id
         LEFT JOIN ticket t ON t.screening_id = sc.id AND t.seat_id = se.id
         WHERE sc.id = ?
         ORDER BY se.row_no, se.seat_no'
    );
    $stmt->execute([$selectedId]);
    foreach ($stmt->fetchAll() as $r) {
        $seatRows[$r['row_no']][] = $r;
        $legend[$r['type_name']] = $r['price'];
    }
}

function seatClass(string $type): string
{
    return match ($type) {
        'VIP' => 'vip',
        'Диван' => 'sofa',
        default => 'std',
    };
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Кинотеатр</title>
<style>
  :root { --bg:#f6f7f9; --card:#fff; --text:#1d2330; --muted:#6b7385; --line:#e3e6ec; --accent:#3b6cf6;
          --std:#cfe9d6; --vip:#f2d77c; --sofa:#c9b8f0; --sold:#e9b4b4; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#14171f; --card:#1d222d; --text:#e8ebf2; --muted:#98a1b5; --line:#2c3344; --accent:#7c9bff;
            --std:#2f5a3d; --vip:#7a6a28; --sofa:#4d4378; --sold:#6a3434; }
  }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:15px/1.5 system-ui,sans-serif; }
  main { max-width:960px; margin:0 auto; padding:24px 16px 64px; }
  h1 { margin:0 0 20px; } h2 { margin:0 0 12px; font-size:18px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:20px; overflow-x:auto; }
  .top { font-size:22px; font-weight:600; } .muted { color:var(--muted); }
  table { width:100%; border-collapse:collapse; }
  th, td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); }
  .bar { height:10px; background:var(--accent); border-radius:5px; min-width:2px; }
  tr.sel td { background:color-mix(in srgb, var(--accent) 12%, transparent); }
  a { color:var(--accent); }
  .screen { text-align:center; margin:8px auto 16px; padding:4px; max-width:360px; background:var(--line);
            border-radius:0 0 40px 40px; color:var(--muted); font-size:12px; }
  .row { display:flex; justify-content:center; align-items:center; gap:6px; margin:6px 0; }
  .row b { width:22px; color:var(--muted); font-weight:400; font-size:12px; text-align:right; }
  .seat { width:32px; height:32px; border-radius:6px; font-size:11px; display:flex; align-items:center; justify-content:center; }
  .seat.std { background:var(--std); } .seat.vip { background:var(--vip); } .seat.sofa { background:var(--sofa); width:44px; }
  .seat.sold { background:var(--sold); text-decoration:line-through; opacity:.85; }
  .legend { display:flex; gap:16px; flex-wrap:wrap; justify-content:center; margin-top:16px; font-size:13px; }
  .legend i { display:inline-block; width:14px; height:14px; border-radius:3px; vertical-align:-2px; margin-right:4px; }
</style>
</head>
<body>
<main>
  <h1>Управление кинотеатром</h1>

  <section class="card">
    <h2>Самый прибыльный фильм</h2>
    <?php if ($top): ?>
      <div class="top"><?= h($top['title']) ?> — <?= number_format((float)$top['revenue'], 2, ',', ' ') ?> ₽</div>
      <div class="muted">Проданных билетов: <?= (int)$top['tickets'] ?></div>
    <?php endif; ?>
    <table style="margin-top:16px">
      <tr><th>Фильм</th><th>Билетов</th><th style="width:45%">Выручка</th></tr>
      <?php foreach ($revenue as $r): ?>
        <tr>
          <td><?= h($r['title']) ?></td>
          <td><?= (int)$r['tickets'] ?></td>
          <td>
            <div class="bar" style="width:<?= (int)round((float)$r['revenue'] / $maxRevenue * 100) ?>%"></div>
            <small class="muted"><?= number_format((float)$r['revenue'], 2, ',', ' ') ?> ₽</small>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </section>

  <section class="card">
    <h2>Сеансы</h2>
    <table>
      <tr><th>Начало</th><th>Фильм</th><th>Зал</th><th>Занято</th></tr>
      <?php foreach ($screenings as $s): ?>
        <tr class="<?= (int)$s['id'] === $selectedId ? 'sel' : '' ?>">
          <td><a href="?screening=<?= (int)$s['id'] ?>"><?= h(date('d.m H:i', strtotime($s['starts_at']))) ?></a></td>
          <td><?= h($s['title']) ?></td>
          <td><?= h($s['hall']) ?></td>
          <td><?= (int)$s['sold'] ?> / <?= (int)$s['total_seats'] ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </section>

  <?php if ($selected): ?>
  <section class="card">
    <h2>Схема зала: <?= h($selected['title']) ?>, <?= h($selected['hall']) ?>, <?= h(date('d.m H:i', strtotime($selected['starts_at']))) ?></h2>
    <div class="screen">ЭКРАН</div>
    <?php foreach ($seatRows as $rowNo => $seats): ?>
      <div class="row"><b><?= (int)$rowNo ?></b>
        <?php foreach ($seats as $seat): ?>
          <div class="seat <?= seatClass($seat['type_name']) ?><?= $seat['sold'] ? ' sold' : '' ?>"
               title="Ряд <?= (int)$rowNo ?>, место <?= (int)$seat['seat_no'] ?> · <?= h($seat['type_name']) ?> · <?= h($seat['price']) ?> ₽<?= $seat['sold'] ? ' · занято' : '' ?>"><?= (int)$seat['seat_no'] ?></div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <div class="legend">
      <?php foreach ($legend as $name => $price): ?>
        <span><i style="background:var(--<?= seatClass($name) ?>)"></i><?= h($name) ?> — <?= number_format((float)$price, 0, ',', ' ') ?> ₽</span>
      <?php endforeach; ?>
      <span><i style="background:var(--sold)"></i>Занято</span>
    </div>
  </section>
  <?php endif; ?>
</main>
</body>
</html>
