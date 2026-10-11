<?php

declare(strict_types=1);

require __DIR__ . '/../src/BracketValidator.php';

use App\BracketValidator;
use App\InvalidBracketSequenceException;

session_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidBracketSequenceException('Only POST requests are supported.');
    }

    $value = $_POST['string'] ?? null;

    if (!is_string($value)) {
        throw new InvalidBracketSequenceException('The POST parameter "string" is required.');
    }

    $input = trim($value);

    if ($input === '') {
        throw new InvalidBracketSequenceException('The POST parameter "string" must not be empty.');
    }

    BracketValidator::validate($input);

    $_SESSION['last_status'] = 'valid';
    http_response_code(200);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "OK: bracket sequence is valid.\n";
    exit;
} catch (InvalidBracketSequenceException $exception) {
    $_SESSION['last_status'] = 'invalid';
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bad Request: ' . $exception->getMessage() . "\n";
    exit;
}
