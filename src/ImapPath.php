<?php

namespace d3yii2\d3imap;

/**
 * Parsed c-client mailbox specification, e.g. {mail.example.com:993/imap/ssl/novalidate-cert}INBOX
 * @see https://www.php.net/manual/en/function.imap-open.php
 */
class ImapPath
{
    public const PORT_IMAP = 143;
    public const PORT_IMAPS = 993;

    public const ENCRYPTION_SSL = 'ssl';
    public const ENCRYPTION_STARTTLS = 'starttls';

    public string $host = '';
    public int $port = self::PORT_IMAP;

    /** @var string|null ssl, starttls or null for an unencrypted connection */
    public ?string $encryption = null;
    public bool $validateCert = true;
    public ?string $folder = null;

    /** @var array all flags, lower case name => value (true for flags without value) */
    public array $flags = [];

    /**
     * @throws Exception
     */
    public static function parse(string $imapPath): self
    {
        if (!preg_match('/^\{([^}]+)\}(.*)$/s', trim($imapPath), $matches)) {
            throw new Exception('Invalid IMAP path "' . $imapPath . '", expected {host[:port][/flags]}[folder]');
        }
        $parts = explode('/', $matches[1]);
        $address = array_shift($parts);
        if (!preg_match('/^(\[[^\]]+\]|[^:\[\]]+)(?::(\d+))?$/', trim($address), $addressMatches)) {
            throw new Exception('Invalid host in IMAP path "' . $imapPath . '"');
        }

        $path = new self();
        $path->host = trim($addressMatches[1], '[]');
        $path->folder = $matches[2] !== '' ? $matches[2] : null;
        foreach ($parts as $part) {
            $nameValue = explode('=', $part, 2);
            $path->flags[strtolower(trim($nameValue[0]))] = $nameValue[1] ?? true;
        }

        $protocol = strtolower((string)($path->flags['service'] ?? ''));
        foreach (['pop3', 'nntp'] as $unsupported) {
            if ($protocol === $unsupported || isset($path->flags[$unsupported])) {
                throw new Exception('Protocol "' . $unsupported . '" is not supported, use IMAP: ' . $imapPath);
            }
        }

        $port = isset($addressMatches[2]) ? (int)$addressMatches[2] : null;
        if (isset($path->flags['ssl'])) {
            $path->encryption = self::ENCRYPTION_SSL;
        } elseif (isset($path->flags['tls'])) {
            $path->encryption = self::ENCRYPTION_STARTTLS;
        } elseif (!isset($path->flags['notls']) && $port === self::PORT_IMAPS) {
            $path->encryption = self::ENCRYPTION_SSL;
        }
        $path->port = $port ?? self::defaultPort($path->encryption);

        if (isset($path->flags['novalidate-cert'])) {
            $path->validateCert = false;
        }

        return $path;
    }

    public static function defaultPort(?string $encryption): int
    {
        return in_array($encryption, [self::ENCRYPTION_SSL, 'tls'], true) ? self::PORT_IMAPS : self::PORT_IMAP;
    }
}
