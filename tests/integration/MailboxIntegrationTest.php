<?php

namespace d3yii2\d3imap\tests\integration;

use d3yii2\d3imap\Exception;
use d3yii2\d3imap\ImapConnection;
use d3yii2\d3imap\IncomingMail;
use d3yii2\d3imap\Mailbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\helpers\FileHelper;

/**
 * Reads the mailbox set by MAILER_IMAP_TRANSPORT_* in app_env/.env. Read only: \Seen flags must stay unchanged.
 * Optional environment: D3IMAP_TEST_FOLDER (default INBOX), D3IMAP_TEST_MAILS - count of latest mails to read (default 5)
 */
#[Group('integration')]
class MailboxIntegrationTest extends TestCase
{
    private ?Mailbox $mailbox = null;
    private string $attachmentsDir = '';

    protected function setUp(): void
    {
        self::skipIfNotConfigured();
        $this->attachmentsDir = sys_get_temp_dir() . '/d3imap-it-' . uniqid('', true);
        FileHelper::createDirectory($this->attachmentsDir);
        $this->mailbox = new Mailbox(self::createConnection($this->attachmentsDir));
    }

    protected function tearDown(): void
    {
        if ($this->mailbox) {
            $this->mailbox->disconnect();
        }
        if ($this->attachmentsDir) {
            FileHelper::removeDirectory($this->attachmentsDir);
        }
    }

    public static function skipIfNotConfigured(): void
    {
        foreach (['MAILER_IMAP_TRANSPORT_HOST', 'MAILER_IMAP_TRANSPORT_USERNAME', 'MAILER_IMAP_TRANSPORT_PASSWORD'] as $name) {
            if (!self::env($name)) {
                self::markTestSkipped($name . ' is not set');
            }
        }
    }

    /**
     * Connection like SettingEmailContainer::getImapPath() builds it
     */
    public static function createConnection(?string $attachmentsDir = null, ?string $folder = null): ImapConnection
    {
        $encryption = strtolower((string)self::env('MAILER_IMAP_TRANSPORT_ENCRYPTION'));
        if ($encryption === 'ssl') {
            $flags = '/ssl';
        } elseif (in_array($encryption, ['tls', 'starttls'], true)) {
            $flags = '/tls';
        } else {
            $flags = '/notls';
        }
        $connection = new ImapConnection();
        $connection->imapPath = '{' . self::env('MAILER_IMAP_TRANSPORT_HOST')
            . ':' . (self::env('MAILER_IMAP_TRANSPORT_PORT') ?: 993)
            . '/imap' . $flags . '/novalidate-cert}INBOX';
        $connection->activeFolder = $folder ?? self::env('D3IMAP_TEST_FOLDER');
        $connection->imapLogin = self::env('MAILER_IMAP_TRANSPORT_USERNAME');
        $connection->imapPassword = self::env('MAILER_IMAP_TRANSPORT_PASSWORD');
        $connection->serverEncoding = 'utf-8';
        $connection->attachmentsDir = $attachmentsDir;

        return $connection;
    }

    public static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return $value === false || $value === '' || $value === null ? null : (string)$value;
    }

    public function testListFolders(): void
    {
        $folders = $this->mailbox->getListingFolders();

        self::assertNotEmpty($folders);
        self::assertContains('INBOX', array_map('strtoupper', $folders));
    }

    public function testStatusAndCount(): void
    {
        $status = $this->mailbox->statusMailbox();

        foreach (['messages', 'unseen', 'uidnext', 'uidvalidity'] as $property) {
            self::assertObjectHasProperty($property, $status);
            self::assertIsInt($status->$property);
        }
        self::assertGreaterThanOrEqual(0, $this->mailbox->countMails());
    }

    public function testSearch(): void
    {
        $all = $this->mailbox->searchMailbox();
        $unseen = $this->mailbox->searchMailboxUnseen();

        self::assertContainsOnlyInt($all);
        self::assertSame(count($all), count(array_unique($all)));
        self::assertSame([], array_values(array_diff($unseen, $all)), 'UNSEEN must be a subset of ALL');
        self::assertSame([], $this->mailbox->searchMailbox('SUBJECT "d3imap-no-such-subject-' . uniqid() . '"'));
    }

    public function testReadLatestMailsWithoutChangingSeenFlags(): void
    {
        $uids = $this->getLatestUids();
        $unseenBefore = array_values(array_intersect($this->mailbox->searchMailboxUnseen(), $uids));
        $this->mailbox->readMailParts = false;

        foreach ($uids as $uid) {
            $mail = $this->mailbox->getMail($uid, false);
            $this->assertHeader($mail, $uid);
            self::assertNull($mail->textPlain);
            self::assertNull($mail->textHtml);
            self::assertSame([], $mail->getAttachments());

            $this->mailbox->getMailParts($mail);
            $this->assertParts($mail);
        }

        $unseenAfter = array_values(array_intersect($this->mailbox->searchMailboxUnseen(), $uids));
        self::assertSame($unseenBefore, $unseenAfter, 'Reading mails must not change \Seen flags');
    }

    public function testGetMailReadsParts(): void
    {
        $uids = $this->getLatestUids();
        $uid = end($uids);
        $unseenBefore = in_array($uid, $this->mailbox->searchMailboxUnseen(), true);

        $mail = $this->mailbox->getMail($uid, false);

        $this->assertHeader($mail, $uid);
        $this->assertParts($mail);
        self::assertSame($unseenBefore, in_array($uid, $this->mailbox->searchMailboxUnseen(), true));
    }

    public function testSaveMail(): void
    {
        $uids = $this->getLatestUids();
        $uid = end($uids);
        $unseenBefore = in_array($uid, $this->mailbox->searchMailboxUnseen(), true);
        $file = $this->attachmentsDir . '/mail.eml';

        self::assertTrue($this->mailbox->saveMail($uid, $file));

        self::assertMatchesRegularExpression('/^(From|Date|Subject|Message-ID):/mi', file_get_contents($file));
        self::assertSame($unseenBefore, in_array($uid, $this->mailbox->searchMailboxUnseen(), true));
    }

    public function testMissingFolder(): void
    {
        $mailbox = new Mailbox(self::createConnection(null, 'd3imap-missing-' . uniqid()));
        try {
            $mailbox->searchMailbox();
            self::fail('Exception expected');
        } catch (Exception $e) {
            self::assertStringContainsString('not found', $e->getMessage());
        } finally {
            $mailbox->disconnect();
        }
    }

    /**
     * @return int[]
     */
    private function getLatestUids(): array
    {
        $uids = array_slice($this->mailbox->searchMailbox(), -(int)(self::env('D3IMAP_TEST_MAILS') ?: 5));
        if (!$uids) {
            self::markTestSkipped('Folder ' . $this->mailbox->getFolderPath() . ' is empty');
        }

        return $uids;
    }

    private function assertHeader(IncomingMail $mail, int $uid): void
    {
        self::assertSame($uid, $mail->id);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $mail->date);
        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $mail->messageId, 'messageId must be ASCII');
        self::assertIsString($mail->subject);
        self::assertTrue(mb_check_encoding($mail->subject, 'UTF-8'));
        self::assertIsString($mail->fromAddress);
        foreach ([$mail->to, $mail->cc, $mail->replyTo] as $recipients) {
            foreach ($recipients as $email => $name) {
                self::assertMatchesRegularExpression('/^[^@\s]+@[^@\s]+$/', (string)$email);
                self::assertSame(strtolower($email), $email);
            }
        }
    }

    private function assertParts(IncomingMail $mail): void
    {
        self::assertTrue(
            $mail->textPlain !== null || $mail->textHtml !== null || count($mail->getAttachments()) > 0,
            'Mail UID ' . $mail->id . ' has no body and no attachments'
        );
        foreach ([$mail->textPlain, $mail->textHtml] as $text) {
            if ($text !== null) {
                self::assertTrue(mb_check_encoding($text, 'UTF-8'), 'Mail UID ' . $mail->id . ' text is not UTF-8');
            }
        }
        foreach ($mail->getAttachments() as $attachment) {
            self::assertNotSame('', pathinfo($attachment->name, PATHINFO_EXTENSION));
            self::assertFileExists($attachment->filePath);
            self::assertSame($this->attachmentsDir, dirname($attachment->filePath));
        }
    }
}
