<?php

namespace d3yii2\d3imap\tests\unit;

use d3yii2\d3imap\Exception;
use d3yii2\d3imap\ImapPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImapPathTest extends TestCase
{
    public static function pathProvider(): array
    {
        return [
            'settings container' => ['{mail.inbox.eu:993/imap/ssl/novalidate-cert}INBOX', 'mail.inbox.eu', 993, 'ssl', false, 'INBOX'],
            'gmail' => ['{imap.gmail.com:993/imap/ssl}INBOX', 'imap.gmail.com', 993, 'ssl', true, 'INBOX'],
            'ssl default port' => ['{mail.example.com/ssl}', 'mail.example.com', 993, 'ssl', true, null],
            'tls is starttls' => ['{mail.example.com/imap/tls}Archive', 'mail.example.com', 143, 'starttls', true, 'Archive'],
            'no encryption' => ['{mail.example.com:143/imap/notls}INBOX.Sent', 'mail.example.com', 143, null, true, 'INBOX.Sent'],
            'port 993 means ssl' => ['{mail.example.com:993/imap}INBOX', 'mail.example.com', 993, 'ssl', true, 'INBOX'],
            'port 993 notls' => ['{mail.example.com:993/imap/notls}', 'mail.example.com', 993, null, true, null],
            'ipv6' => ['{[::1]:1993/imap/ssl/novalidate-cert}INBOX', '::1', 1993, 'ssl', false, 'INBOX'],
            'flags with values' => ['{mail.example.com:993/service=imap/user=john/ssl}Inbox/Sub folder', 'mail.example.com', 993, 'ssl', true, 'Inbox/Sub folder'],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testParse(
        string $imapPath,
        string $host,
        int $port,
        ?string $encryption,
        bool $validateCert,
        ?string $folder
    ): void {
        $path = ImapPath::parse($imapPath);

        self::assertSame($host, $path->host);
        self::assertSame($port, $path->port);
        self::assertSame($encryption, $path->encryption);
        self::assertSame($validateCert, $path->validateCert);
        self::assertSame($folder, $path->folder);
    }

    public function testFlags(): void
    {
        $path = ImapPath::parse('{mail.example.com:993/service=imap/user=john/SSL}INBOX');

        self::assertSame(['service' => 'imap', 'user' => 'john', 'ssl' => true], $path->flags);
    }

    public static function invalidPathProvider(): array
    {
        return [
            'no braces' => ['mail.example.com:993/imap/ssl'],
            'empty host' => ['{:993/imap}INBOX'],
            'pop3' => ['{mail.example.com:110/pop3}INBOX'],
            'nntp service' => ['{news.example.com:119/service=nntp}comp.mail'],
        ];
    }

    #[DataProvider('invalidPathProvider')]
    public function testInvalidPathThrows(string $imapPath): void
    {
        $this->expectException(Exception::class);

        ImapPath::parse($imapPath);
    }
}
