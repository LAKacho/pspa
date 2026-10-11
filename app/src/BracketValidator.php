<?php

declare(strict_types=1);

namespace App;

class InvalidBracketSequenceException extends \InvalidArgumentException
{
}

final class BracketValidator
{
    public static function validate(string $input): void
    {
        if ($input === '') {
            throw new InvalidBracketSequenceException('The string is empty.');
        }

        $balance = 0;
        $length = strlen($input);

        for ($index = 0; $index < $length; $index++) {
            $char = $input[$index];

            if ($char === '(') {
                $balance++;
                continue;
            }

            if ($char === ')') {
                $balance--;

                if ($balance < 0) {
                    throw new InvalidBracketSequenceException('The bracket sequence is invalid: closing bracket before opening bracket.');
                }

                continue;
            }

            throw new InvalidBracketSequenceException('The string contains characters other than parentheses.');
        }

        if ($balance !== 0) {
            throw new InvalidBracketSequenceException('The bracket sequence is invalid: opening and closing counts do not match.');
        }
    }
}
