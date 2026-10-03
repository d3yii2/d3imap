<?php

namespace d3yii2\d3imap\tests\unit;

use d3yii2\d3imap\IncomingMailFactory;
use d3yii2\d3imap\Mailbox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;
use yii\helpers\FileHelper;

class IncomingMailFactoryTest extends TestCase
{
    private string $attachmentsDir;
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->attachmentsDir = sys_get_temp_dir() . '/d3imap-test-' . uniqid('', true);
        FileHelper::createDirectory($this->attachmentsDir);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        FileHelper::removeDirectory($this->attachmentsDir);
    }

    public static function headerParserProvider(): array
    {
        $parsers = ['webklex parser' => [false]];
        if (extension_loaded('imap')) {
            $parsers['ext-imap parser'] = [true];
        }

        return $parsers;
    }

    #[DataProvider('headerParserProvider')]
    public function testMultipartMail(bool $rfc822): void
    {
        $message = $this->loadMessage('multipart.eml', $rfc822);
        $factory = new IncomingMailFactory('utf-8', $this->attachmentsDir);

        $mail = $factory->createFromMessage($message);

        self::assertSame(0, $mail->id);
        self::assertSame('<abc.123@example.com>', $mail->messageId);
        self::assertSame('2024-10-01 07:15:30', $mail->date);
        self::assertSame('Rēķins Nr. 123 – test', $mail->subject);
        self::assertSame('janis.berzins@example.com', $mail->fromAddress);
        self::assertSame('Jānis Bērziņš', $mail->fromName);
        self::assertSame(['anna@example.com' => 'Anna Liepa', 'peter@example.org' => null], $mail->to);
        self::assertSame('Anna Liepa <anna@example.com>, peter@example.org', $mail->toString);
        self::assertSame(['cc@example.com' => 'Cc Person'], $mail->cc);
        self::assertSame(['reply@example.com' => 'Reply Desk'], $mail->replyTo);
        self::assertNull($mail->textPlain);
        self::assertSame([], $mail->getAttachments());

        $factory->fillParts($mail, $message);

        self::assertSame('Labdien! Rēķins pielikumā.', trim($mail->textPlain));
        self::assertStringContainsString('<b>Labdien!</b> Rēķins pielikumā.', $mail->textHtml);
        self::assertSame(['logo@example.com' => 'cid:logo@example.com'], $mail->getInternalLinksPlaceholders());

        $attachments = array_values($mail->getAttachments());
        self::assertCount(2, $attachments);
        [$pdf, $logo] = $attachments;

        self::assertSame('rēķins.pdf', $pdf->name);
        self::assertSame($this->attachmentsDir, dirname($pdf->filePath));
        self::assertMatchesRegularExpression('/^0_[0-9a-z]+_rins\.pdf$/', basename($pdf->filePath));
        self::assertStringStartsWith('%PDF-1.4', file_get_contents($pdf->filePath));

        self::assertSame('logo@example.com', $logo->id);
        self::assertStringEndsWith('.png', $logo->name);
        self::assertStringStartsWith('0_logo@example.com_', basename($logo->filePath));
        self::assertStringStartsWith("\x89PNG", file_get_contents($logo->filePath));
    }

    #[DataProvider('headerParserProvider')]
    public function testQuotedDisplayNames(bool $rfc822): void
    {
        $raw = "Message-ID: <quoted@example.com>\r\n"
            . "From: \"Doe, John\" <John@Example.com>\r\n"
            . "To: \"Copy, Person\" <copy@example.com>, Second <second@example.com>, plain@example.com\r\n"
            . "Cc: =?UTF-8?Q?=22B=C4=93rzi=C5=86=C5=A1=2C_J=C4=81nis=22?= <janis@example.com>\r\n"
            . "Subject: Quoted names\r\n\r\nBody\r\n";
        $options = Mailbox::getDefaultClientOptions();
        $options['rfc822'] = $rfc822;

        $mail = (new IncomingMailFactory())->createFromMessage(
            Message::fromString($raw, Config::make(['options' => $options]))
        );

        self::assertSame('john@example.com', $mail->fromAddress);
        self::assertSame('Doe, John', $mail->fromName);
        self::assertSame(
            ['copy@example.com' => 'Copy, Person', 'second@example.com' => 'Second', 'plain@example.com' => null],
            $mail->to
        );
        self::assertSame(['janis@example.com' => 'Bērziņš, Jānis'], $mail->cc);
    }

    public function testMessageIdFallbackToDateAndFrom(): void
    {
        $mail = (new IncomingMailFactory())->createFromMessage($this->loadMessage('no-message-id.eml'));

        self::assertSame('2024-10-02 08:00:00', $mail->date);
        self::assertSame('sender@example.com', $mail->fromAddress);
        self::assertSame('Sender', $mail->fromName);
        self::assertSame('<2024-10-02 08:00:00$sender@example.com>', $mail->messageId);
    }

    public function testMessageIdFallbackToSubjectWithoutDate(): void
    {
        $mail = (new IncomingMailFactory())->createFromMessage($this->loadMessage('no-date-no-id.eml'));

        self::assertEqualsWithDelta(time(), strtotime($mail->date), 5);
        self::assertNull($mail->fromName);
        self::assertSame('<' . md5('Without date and message id') . '$sender@example.com>', $mail->messageId);
    }

    public function testRawMessageIdInvalidRecipientsAndCharset(): void
    {
        $message = $this->loadMessage('raw-message-id.eml');
        $factory = new IncomingMailFactory('utf-8', $this->attachmentsDir);

        $mail = $factory->createFromMessage($message);
        $factory->fillParts($mail, $message);

        self::assertSame('abc-no-brackets@example.com', $mail->messageId);
        self::assertSame([], $mail->to);
        self::assertSame(['valid@example.com' => 'Valid Person'], $mail->cc);
        self::assertNull($mail->textPlain);
        self::assertSame('<p>Grüße</p>', trim($mail->textHtml));

        $attachments = array_values($mail->getAttachments());
        self::assertCount(1, $attachments);
        self::assertSame('report.pdf', $attachments[0]->name);
        self::assertFileExists($attachments[0]->filePath);
    }

    public function testServerEncoding(): void
    {
        $mail = (new IncomingMailFactory('ISO-8859-13'))->createFromMessage($this->loadMessage('multipart.eml'));

        self::assertNotSame('Jānis Bērziņš', $mail->fromName);
        self::assertSame('Jānis Bērziņš', mb_convert_encoding($mail->fromName, 'UTF-8', 'ISO-8859-13'));
    }

    public function testAttachmentsWithoutDirectoryAreNotSaved(): void
    {
        $message = $this->loadMessage('multipart.eml');
        $factory = new IncomingMailFactory();
        $mail = $factory->createFromMessage($message);

        $factory->fillParts($mail, $message);

        self::assertCount(2, $mail->getAttachments());
        foreach ($mail->getAttachments() as $attachment) {
            self::assertNull($attachment->filePath);
            self::assertNotSame('', $attachment->name);
        }
    }

    public function testLongAttachmentFileNameIsShortened(): void
    {
        $name = str_repeat('ļoti_garš_nosaukums_', 20) . '.pdf';
        $raw = "Message-ID: <long@example.com>\r\n"
            . "From: sender@example.com\r\n"
            . "Subject: Long name\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"b\"\r\n\r\n"
            . "--b\r\nContent-Type: text/plain\r\n\r\nBody\r\n"
            . "--b\r\nContent-Type: application/pdf\r\n"
            . "Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name) . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . base64_encode('%PDF-1.4') . "\r\n--b--\r\n";
        $message = Message::fromString($raw, Config::make(['options' => Mailbox::getDefaultClientOptions()]));
        $factory = new IncomingMailFactory('utf-8', $this->attachmentsDir);
        $mail = $factory->createFromMessage($message);

        $factory->fillParts($mail, $message);

        $attachment = array_values($mail->getAttachments())[0];
        self::assertSame($name, $attachment->name);
        self::assertLessThanOrEqual(200, strlen(basename($attachment->filePath)));
        self::assertStringEndsWith('.pdf', $attachment->filePath);
        self::assertFileExists($attachment->filePath);
    }

    private function loadMessage(string $fixture, bool $rfc822 = false): Message
    {
        $options = Mailbox::getDefaultClientOptions();
        $options['rfc822'] = $rfc822;

        return Message::fromString(
            file_get_contents(dirname(__DIR__) . '/fixtures/' . $fixture),
            Config::make(['options' => $options])
        );
    }
}
