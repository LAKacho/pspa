<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/src/EmailVerifier.php';

$failed = 0;

function check(string $name, $expected, $actual): void
{
    global $failed;
    $ok = $expected === $actual;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? '[ OK ] ' : '[FAIL] ') . $name . ($ok ? '' : ' (ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true) . ')') . PHP_EOL;
}

// DNS подменён: MX есть только у известных доменов, тесты не зависят от сети
$knownDomains = ['gmail.com', 'mail.ru', 'example.com'];
$calls = 0;
$verifier = new EmailVerifier(function (string $domain) use ($knownDomains, &$calls): bool {
    $calls++;
    return in_array($domain, $knownDomains, true);
});

$cases = [
    ['user@gmail.com', true, 'ok'],
    ['  user@gmail.com  ', true, 'ok'],
    ['first.last+tag@example.com', true, 'ok'],
    ['abc@', false, 'bad format'],
    ['', false, 'bad format'],
    ['   ', false, 'bad format'],
    ['no-at-sign.com', false, 'bad format'],
    ['a@@gmail.com', false, 'bad format'],
    ['a..b@mail.ru', false, 'bad format'],
    ['.a@mail.ru', false, 'bad format'],
    ['a.@mail.ru', false, 'bad format'],
    ['a b@mail.ru', false, 'bad format'],
    ['a@nodot', false, 'bad format'],
    ['a@-bad.com', false, 'bad format'],
    [str_repeat('a', 65) . '@gmail.com', false, 'bad format'],
    [str_repeat('a', 250) . '@gmail.com', false, 'bad format'],
    ['x@nonexistent-xyz123.com', false, 'no MX record'],
];

foreach ($cases as [$email, $valid, $reason]) {
    $r = $verifier->verify($email);
    check("verify('" . substr($email, 0, 30) . "') valid", $valid, $r['valid']);
    check("verify('" . substr($email, 0, 30) . "') reason", $reason, $r['reason']);
}

// verifyMany
$list = ['user@gmail.com', 'abc@', 'x@nonexistent-xyz123.com'];
$result = $verifier->verifyMany($list);
check('verifyMany: количество', 3, count($result));
check('verifyMany: порядок', [true, false, false], array_column($result, 'valid'));

// кэш: gmail.com резолвится один раз
$calls = 0;
$cached = new EmailVerifier(function (string $d) use (&$calls): bool {
    $calls++;
    return true;
});
$cached->verifyMany(['a@gmail.com', 'b@gmail.com', 'c@gmail.com']);
check('кэш MX по домену', 1, $calls);

// реальный DNS (нужен интернет)
if (in_array('--dns', $argv ?? [], true)) {
    $real = new EmailVerifier();
    check('реальный DNS: gmail.com', true, $real->verify('user@gmail.com')['valid']);
    check('реальный DNS: несуществующий домен', 'no MX record', $real->verify('x@nonexistent-xyz123.com')['reason']);
}

echo PHP_EOL . ($failed === 0 ? 'Все тесты пройдены' : "Провалено тестов: $failed") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
