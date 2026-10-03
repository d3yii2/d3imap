<?php

namespace d3yii2\d3imap\tests\unit;

use d3yii2\d3imap\Exception;
use d3yii2\d3imap\ImapConnection;
use d3yii2\d3imap\Mailbox;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\IMAP;

/**
 * Mailbox behaviour without IMAP server, 127.0.0.1:1 refuses connections
 */
class MailboxTest extends TestCase
{
    public function testUnusedMailboxDoesNotConnect(): void
    {
        $start = microtime(true);
        $mailbox = new Mailbox($this->createConnection('{127.0.0.1:1/imap/notls}INBOX'));
        $mailbox->readMailParts = false;
        unset($mailbox);

        self::assertLessThan(1, microtime(true) - $start);
    }

    public function testConnectionErrorIsWrapped(): void
    {
        $mailbox = new Mailbox($this->createConnection('{127.0.0.1:1/imap/notls}INBOX'));
        try {
            $mailbox->searchMailbox();
            self::fail('Exception expected');
        } catch (Exception $e) {
            self::assertStringStartsWith('Can not connect to user@example.com at 127.0.0.1:1', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }
        $mailbox->disconnect();
    }

    public function testInvalidImapPathIsWrapped(): void
    {
        $mailbox = new Mailbox($this->createConnection('mail.example.com:993'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid IMAP path');

        $mailbox->searchMailbox();
    }

    public function testDefaultClientOptions(): void
    {
        $options = Mailbox::getDefaultClientOptions();

        self::assertSame(IMAP::FT_PEEK, $options['fetch']);
        self::assertSame(IMAP::ST_UID, $options['sequence']);
        self::assertTrue($options['fetch_flags']);
        self::assertFalse($options['rfc822']);
    }

    private function createConnection(string $imapPath): ImapConnection
    {
        $connection = new ImapConnection();
        $connection->imapPath = $imapPath;
        $connection->imapLogin = 'user@example.com';
        $connection->imapPassword = 'secret';
        $connection->timeout = 2;

        return $connection;
    }
}
