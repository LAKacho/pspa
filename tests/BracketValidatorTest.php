<?php

require __DIR__ . '/../app/src/BracketValidator.php';

use App\BracketValidator;
use App\InvalidBracketSequenceException;

$validCases = [
    '()',
    '()()',
    '(())',
    '(()())',
    '(()()())',
    '(()()()())((()))',
];

foreach ($validCases as $case) {
    BracketValidator::validate($case);
}

$invalidCases = [
    ')(',
    '(()',
    '())(',
    '((()()))))',
    '',
];

foreach ($invalidCases as $case) {
    try {
        BracketValidator::validate($case);
        throw new RuntimeException('Expected InvalidBracketSequenceException for: ' . $case);
    } catch (InvalidBracketSequenceException $exception) {
        continue;
    }
}

echo "Bracket tests passed\n";
