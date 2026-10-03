<?php

namespace d3yii2\d3imap\tests\unit;

use d3yii2\d3imap\Exception;
use d3yii2\d3imap\ImapConnection;
use PHPUnit\Framework\TestCase;

class ImapConnectionTest extends TestCase
{
    public function testAccountConfigFromImapPath(): void
    {
        $connection = new ImapConnection();
        $connection->imapPath = '{mail.inbox.eu:993/imap/ssl/novalidate-cert}INBOX';
        $connection->imapLogin = 'user@example.com';
        $connection->imapPassword = 'secret';

        self::assertSame(
            [
                'host' => 'mail.inbox.eu',
                'port' => 993,
                'protocol' => 'imap',
                'encryption' => 'ssl',
                'validate_cert' => false,
                'username' => 'user@example.com',
                'password' => 'secret',
                'timeout' => 30,
            ],
            $connection->getAccountConfig()
        );
    }

    public function testOverridesWinOverImapPath(): void
    {
        $connection = new ImapConnection();
        $connection->imapPath = '{mail.example.com:993/imap/ssl}INBOX';
        $connection->host = 'imap.other.com';
        $connection->port = 143;
        $connection->encryption = '';
        $connection->validateCert = false;
        $connection->timeout = 5;

        $config = $connection->getAccountConfig();

        self::assertSame('imap.other.com', $config['host']);
        self::assertSame(143, $config['port']);
        self::assertFalse($config['encryption']);
        self::assertFalse($config['validate_cert']);
        self::assertSame(5, $config['timeout']);
    }

    public function testHostWithoutImapPath(): void
    {
        $connection = new ImapConnection();
        $connection->imapPath = null;
        $connection->host = 'imap.example.com';

        $config = $connection->getAccountConfig();

        self::assertSame(993, $config['port']);
        self::assertSame('ssl', $config['encryption']);
        self::assertTrue($config['validate_cert']);
        self::assertSame('INBOX', $connection->getFolderPath());
    }

    public function testMissingHostThrows(): void
    {
        $this->expectException(Exception::class);

        (new ImapConnection())->getAccountConfig();
    }

    public function testFolderPath(): void
    {
        $connection = new ImapConnection();
        $connection->imapPath = '{mail.example.com:993/imap/ssl}Archive';
        self::assertSame('Archive', $connection->getFolderPath());

        $connection->activeFolder = 'INBOX.Invoices';
        self::assertSame('INBOX.Invoices', $connection->getFolderPath());

        $connection->imapPath = '{mail.example.com:993/imap/ssl}';
        $connection->activeFolder = null;
        self::assertSame('INBOX', $connection->getFolderPath());
    }
}
