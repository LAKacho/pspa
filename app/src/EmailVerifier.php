<?php

declare(strict_types=1);

class EmailVerifier
{
    private const MAX_EMAIL_LENGTH = 254;
    private const MAX_LOCAL_LENGTH = 64;

    private const LOCAL_PATTERN = '/^[\p{L}\p{N}!#$%&\'*+\/=?^_`{|}~-]+(?:\.[\p{L}\p{N}!#$%&\'*+\/=?^_`{|}~-]+)*$/u';
    private const DOMAIN_PATTERN = '/^(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+[\p{L}\p{N}-]{2,63}$/u';

    /** @var callable(string): bool */
    private $mxResolver;

    /** @var array<string, bool> */
    private array $mxCache = [];

    /**
     * @param callable(string): bool|null $mxResolver подмена DNS-проверки (для тестов)
     */
    public function __construct(?callable $mxResolver = null)
    {
        $this->mxResolver = $mxResolver ?? [self::class, 'hasMxRecord'];
    }

    public static function isValidFormat(string $email): bool
    {
        if ($email === '' || strlen($email) > self::MAX_EMAIL_LENGTH) {
            return false;
        }

        if (substr_count($email, '@') !== 1) {
            return false;
        }

        [$local, $domain] = explode('@', $email, 2);

        if ($local === '' || strlen($local) > self::MAX_LOCAL_LENGTH) {
            return false;
        }

        return preg_match(self::LOCAL_PATTERN, $local) === 1
            && preg_match(self::DOMAIN_PATTERN, $domain) === 1;
    }

    public static function hasMxRecord(string $domain): bool
    {
        $hosts = [];

        return @getmxrr($domain, $hosts) && $hosts !== [];
    }

    private static function toAscii(string $domain): string
    {
        if (!function_exists('idn_to_ascii') || preg_match('/^[\x20-\x7e]+$/', $domain) === 1) {
            return $domain;
        }

        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? $domain : $ascii;
    }

    /**
     * @return array{email: string, valid: bool, reason: string}
     */
    public function verify(string $email): array
    {
        $email = trim($email);

        if (!self::isValidFormat($email)) {
            return ['email' => $email, 'valid' => false, 'reason' => 'bad format'];
        }

        $domain = self::toAscii(substr($email, strrpos($email, '@') + 1));

        if (!array_key_exists($domain, $this->mxCache)) {
            $this->mxCache[$domain] = (bool) ($this->mxResolver)($domain);
        }

        if (!$this->mxCache[$domain]) {
            return ['email' => $email, 'valid' => false, 'reason' => 'no MX record'];
        }

        return ['email' => $email, 'valid' => true, 'reason' => 'ok'];
    }

    /**
     * @param string[] $emails
     * @return array<int, array{email: string, valid: bool, reason: string}>
     */
    public function verifyMany(array $emails): array
    {
        return array_map(fn($email) => $this->verify((string) $email), array_values($emails));
    }
}
