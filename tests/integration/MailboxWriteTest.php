<?php

namespace d3yii2\d3imap\tests\integration;

use d3yii2\d3imap\Mailbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;
use yii\helpers\FileHelper;

/**
 * Changes the mailbox, runs only with D3IMAP_TEST_WRITE=1.
 * Creates temporary folders "<INBOX><delimiter>d3imap-test-*", appends a test mail there, sets and clears flags,
 * moves, deletes and expunges it and finally deletes the folders. Other folders are not changed.
 */
#[Group('integration')]
#[Group('write')]
class MailboxWriteTest extends TestCase
{
    private const FOLDER_PREFIX = 'd3imap-test-';

    private ?Mailbox $mailbox = null;
    private string $attachmentsDir = '';
    private string $folderPath = '';
    private string $delimiter = '/';

    /** @var string[] */
    private array $createdFolders = [];

    protected function setUp(): void
    {
        if (MailboxIntegrationTest::env('D3IMAP_TEST_WRITE') !== '1') {
            self::markTestSkipped('Set D3IMAP_TEST_WRITE=1 to run tests which change the mailbox');
        }
        MailboxIntegrationTest::skipIfNotConfigured();

        $this->attachmentsDir = sys_get_temp_dir() . '/d3imap-wt-' . uniqid('', true);
        FileHelper::createDirectory($this->attachmentsDir);

        $inbox = (new Mailbox(MailboxIntegrationTest::createConnection()))->getFolder();
        $this->delimiter = $inbox->delimiter ?: '/';
        $this->folderPath = $this->createFolder($inbox->full_name . $this->delimiter . self::FOLDER_PREFIX . date('YmdHis') . '-' . bin2hex(random_bytes(3)));
        $this->mailbox = new Mailbox(MailboxIntegrationTest::createConnection($this->attachmentsDir, $this->folderPath));
    }

    protected function tearDown(): void
    {
        if ($this->mailbox) {
            $this->mailbox->disconnect();
        }
        if ($this->createdFolders) {
            $mailbox = new Mailbox(MailboxIntegrationTest::createConnection());
            $client = $mailbox->getImapClient();
            foreach (array_reverse($this->createdFolders) as $path) {
                if (strpos($path, self::FOLDER_PREFIX) === false) {
                    continue;
                }
                try {
                    $client->deleteFolder($path, false);
                } catch (Throwable $e) {
                    fwrite(STDERR, 'Can not delete test folder ' . $path . ': ' . $e->getMessage() . PHP_EOL);
                }
            }
            $mailbox->disconnect();
        }
        if ($this->attachmentsDir) {
            FileHelper::removeDirectory($this->attachmentsDir);
        }
    }

    public function testMailLifecycle(): void
    {
        $token = $this->appendTestMail();

        $uids = $this->mailbox->searchMailbox('HEADER Message-ID "' . $token . '"');
        self::assertCount(1, $uids);
        $uid = $uids[0];
        self::assertSame([$uid], $this->mailbox->searchMailbox());
        self::assertSame([$uid], $this->mailbox->searchMailboxUnseen());
        self::assertSame(1, $this->mailbox->countMails());

        $mail = $this->mailbox->getMail($uid, false);
        self::assertSame($uid, $mail->id);
        self::assertSame('<' . $token . '@d3imap.test>', $mail->messageId);
        self::assertSame('Test ' . $token . ' Rēķins', $mail->subject);
        self::assertSame('Jānis Tests', $mail->fromName);
        self::assertSame('sender@d3imap.test', $mail->fromAddress);
        self::assertSame(['receiver@d3imap.test' => null], $mail->to);
        self::assertSame(['copy@d3imap.test' => 'Copy, Person'], $mail->cc);
        self::assertSame('Labdien! Šis ir d3imap testa e-pasts.', trim($mail->textPlain));
        $attachments = array_values($mail->getAttachments());
        self::assertCount(1, $attachments);
        self::assertSame('pārskats.txt', $attachments[0]->name);
        self::assertSame("attachment content\n", file_get_contents($attachments[0]->filePath));
        self::assertSame([$uid], $this->mailbox->searchMailboxUnseen(), 'getMail($uid, false) must not set \Seen');

        self::assertTrue($this->mailbox->markMailAsRead($uid));
        self::assertSame([], $this->mailbox->searchMailboxUnseen());
        self::assertTrue($this->mailbox->markMailAsUnread($uid));
        self::assertSame([$uid], $this->mailbox->searchMailboxUnseen());

        $this->mailbox->getMail($uid);
        self::assertSame([], $this->mailbox->searchMailboxUnseen(), 'getMail($uid, true) must set \Seen');

        self::assertTrue($this->mailbox->markMailAsImportant($uid));
        self::assertSame([$uid], $this->mailbox->searchMailbox('FLAGGED'));

        self::assertTrue($this->mailbox->deleteMail($uid));
        self::assertSame([$uid], $this->mailbox->searchMailbox('DELETED'));
        self::assertTrue($this->mailbox->expungeDeletedMails());
        self::assertSame([], $this->mailbox->searchMailbox());
    }

    public function testMoveMail(): void
    {
        $token = $this->appendTestMail();
        $uids = $this->mailbox->searchMailbox();
        self::assertCount(1, $uids);
        $targetPath = $this->createFolder($this->folderPath . '-target');

        self::assertTrue($this->mailbox->moveMail($uids[0], $targetPath));

        self::assertSame([], $this->mailbox->searchMailbox());
        $target = new Mailbox(MailboxIntegrationTest::createConnection(null, $targetPath));
        $target->readMailParts = false;
        $movedUids = $target->searchMailbox();
        self::assertCount(1, $movedUids);
        self::assertSame('<' . $token . '@d3imap.test>', $target->getMail($movedUids[0], false)->messageId);
        $target->disconnect();
    }

    private function createFolder(string $path): string
    {
        $mailbox = new Mailbox(MailboxIntegrationTest::createConnection());
        try {
            $mailbox->getImapClient()->createFolder($path, false);
        } catch (Throwable $e) {
            self::markTestSkipped('Can not create test folder ' . $path . ': ' . $e->getMessage());
        } finally {
            $mailbox->disconnect();
        }
        $this->createdFolders[] = $path;

        return $path;
    }

    private function appendTestMail(): string
    {
        $token = self::FOLDER_PREFIX . bin2hex(random_bytes(6));
        $attachmentName = '=?UTF-8?B?' . base64_encode('pārskats.txt') . '?=';
        $mail = implode("\r\n", [
            'Message-ID: <' . $token . '@d3imap.test>',
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode('Jānis Tests') . '?= <Sender@d3imap.test>',
            'To: receiver@d3imap.test',
            'Cc: "Copy, Person" <copy@d3imap.test>',
            'Subject: =?UTF-8?B?' . base64_encode('Test ' . $token . ' Rēķins') . '?=',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="d3imap-boundary"',
            '',
            '--d3imap-boundary',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode('Labdien! Šis ir d3imap testa e-pasts.')),
            '--d3imap-boundary',
            'Content-Type: text/plain; charset=UTF-8; name="' . $attachmentName . '"',
            'Content-Disposition: attachment; filename="' . $attachmentName . '"',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode("attachment content\n")),
            '--d3imap-boundary--',
            '',
        ]);
        $this->mailbox->getFolder()->appendMessage($mail);

        return $token;
    }
}
