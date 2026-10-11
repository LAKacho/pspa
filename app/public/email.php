<?php

declare(strict_types=1);

require __DIR__ . '/../src/EmailVerifier.php';

$input = (string) ($_POST['emails'] ?? "user@gmail.com\nabc@\nx@nonexistent-xyz123.com\na..b@mail.ru\nuser@yandex.ru");
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['run'])) {
    $lines = array_filter(array_map('trim', preg_split('/\R/', $input)), fn($l) => $l !== '');
    $results = (new EmailVerifier())->verifyMany($lines);
}

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Верификация email</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 640px; margin: 40px auto; }
        textarea { width: 100%; height: 140px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        td, th { border: 1px solid #ccc; padding: 6px 10px; text-align: left; }
        .ok { color: #166534; } .bad { color: #991b1b; }
    </style>
</head>
<body>
    <h1>Верификация email</h1>
    <form method="POST">
        <p>Один email на строку:</p>
        <textarea name="emails"><?= $h($input) ?></textarea>
        <p><button type="submit">Проверить</button></p>
    </form>
    <?php if ($results): ?>
        <table>
            <tr><th>Email</th><th>Результат</th><th>Причина</th></tr>
            <?php foreach ($results as $r): ?>
                <tr>
                    <td><?= $h($r['email']) ?></td>
                    <td class="<?= $r['valid'] ? 'ok' : 'bad' ?>"><?= $r['valid'] ? 'валидный' : 'невалидный' ?></td>
                    <td><?= $h($r['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</body>
</html>
