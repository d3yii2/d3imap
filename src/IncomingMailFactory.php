<?php

namespace d3yii2\d3imap;

use DateTimeInterface;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Message;

/**
 * Converts Webklex messages to IncomingMail, keeping the formats of the former ext-imap implementation
 */
class IncomingMailFactory
{
    private const MAX_FILE_NAME_BYTES = 200;

    private string $serverEncoding;
    private ?string $attachmentsDir;

    public function __construct(string $serverEncoding = 'utf-8', ?string $attachmentsDir = null)
    {
        $this->serverEncoding = $serverEncoding !== '' ? $serverEncoding : 'utf-8';
        $this->attachmentsDir = $attachmentsDir ? rtrim($attachmentsDir, '\\/') : null;
    }

    /**
     * Header data: id, date, from, subject, messageId, to, cc, replyTo
     */
    public function createFromMessage(Message $message): IncomingMail
    {
        $mail = new IncomingMail();
        $mail->id = (int)$message->getUid();

        $date = $this->firstValue($message->getDate());
        $hasDate = $date instanceof DateTimeInterface;
        $mail->date = date('Y-m-d H:i:s', $hasDate ? $date->getTimestamp() : time());

        $from = $this->getAddresses($message->getFrom());
        $fromAddress = reset($from);
        $mail->fromAddress = $fromAddress ? $this->getEmail($fromAddress) : '';
        $mail->fromName = $fromAddress ? $this->getName($fromAddress) : null;
        $mail->subject = $this->encode($this->toString($message->getSubject()));

        $messageId = $this->getRawMessageId($message);
        if ($messageId !== null) {
            $mail->messageId = self::cleanString($messageId);
        } elseif ($hasDate) {
            $mail->messageId = self::cleanString('<' . $mail->date . '$' . $mail->fromAddress . '>');
        } else {
            $mail->messageId = '<' . md5($mail->subject) . '$' . self::cleanString($mail->fromAddress) . '>';
        }

        $to = $this->getAddresses($message->getTo());
        if ($to) {
            $toStrings = [];
            foreach ($this->getRecipients($to) as $email => $name) {
                $toStrings[] = $name ? $name . ' <' . $email . '>' : $email;
                $mail->to[$email] = $name;
            }
            $mail->toString = implode(', ', $toStrings);
        }
        $mail->cc = $this->getRecipients($this->getAddresses($message->getCc()));
        $mail->replyTo = $this->getRecipients($this->getAddresses($message->getReplyTo()));

        return $mail;
    }

    /**
     * Bodies and attachments. Message must be fetched with body.
     * @throws Exception
     */
    public function fillParts(IncomingMail $mail, Message $message): void
    {
        $mail->textPlain = $message->hasTextBody() ? $this->encode($message->getTextBody()) : null;
        $mail->textHtml = $message->hasHTMLBody() ? $this->encode($message->getHTMLBody()) : null;

        foreach ($message->getAttachments() as $attachment) {
            $mail->addAttachment($this->createAttachment($mail, $attachment));
        }
    }

    /**
     * @throws Exception
     */
    protected function createAttachment(IncomingMail $mail, Attachment $attachment): IncomingMailAttachment
    {
        $mailAttachment = new IncomingMailAttachment();
        $mailAttachment->id = (string)$attachment->getId();
        $mailAttachment->name = $this->getAttachmentName($attachment);
        if ($this->attachmentsDir) {
            $filePath = $this->attachmentsDir . DIRECTORY_SEPARATOR . $this->getFileSystemName($mail, $mailAttachment);
            if (file_put_contents($filePath, $attachment->getContent()) === false) {
                throw new Exception('Can not save attachment "' . $mailAttachment->name . '" to ' . $filePath);
            }
            $mailAttachment->filePath = $filePath;
        }

        return $mailAttachment;
    }

    /**
     * Name with extension, as attachments without extension are not stored by d3files
     */
    protected function getAttachmentName(Attachment $attachment): string
    {
        $name = trim((string)$attachment->getName());
        if ($name === '') {
            $name = (string)$attachment->getId();
        }
        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $extension = $attachment->getExtension();
            if (!$extension) {
                $contentType = explode('/', strtolower((string)$attachment->getContentType()));
                $extension = $contentType[1] ?? 'bin';
            }
            $name .= '.' . $extension;
        }

        return $this->encode($name);
    }

    /**
     * "{uid}_{attachmentId}_{name}" like the former implementation, limited to MAX_FILE_NAME_BYTES
     */
    protected function getFileSystemName(IncomingMail $mail, IncomingMailAttachment $attachment): string
    {
        $replace = [
            '/\s/' => '_',
            '/[^0-9a-zа-яіїє_\.]/iu' => '',
            '/_+/' => '_',
            '/(^_)|(_$)/' => '',
        ];
        $name = preg_replace(array_keys($replace), $replace, $attachment->name);
        $id = substr((string)preg_replace('/[^0-9a-z_.@-]/i', '', $attachment->id), 0, 64);
        $fileName = preg_replace('/\.{2,}/', '.', $mail->id . '_' . $id . '_' . $name);
        if (strlen($fileName) > self::MAX_FILE_NAME_BYTES) {
            $extension = (string)pathinfo($fileName, PATHINFO_EXTENSION);
            $suffix = $extension !== '' ? '.' . substr($extension, 0, 16) : '';
            $fileName = mb_strcut($fileName, 0, self::MAX_FILE_NAME_BYTES - strlen($suffix), 'UTF-8') . $suffix;
        }

        return $fileName;
    }

    /**
     * Message-ID as written in the header (with angle brackets), Webklex strips the brackets
     */
    protected function getRawMessageId(Message $message): ?string
    {
        $header = $message->getHeader();
        if ($header && preg_match('/^Message-ID:[ \t]*(.*(?:\r?\n[ \t]+.*)*)/mi', $header->raw, $matches)) {
            $messageId = trim(preg_replace('/\r?\n[ \t]+/', ' ', $matches[1]));
            if ($messageId !== '') {
                return $messageId;
            }
        }
        $messageId = trim($this->toString($message->getMessageId()));

        return $messageId !== '' ? '<' . $messageId . '>' : null;
    }

    /**
     * @param Address[] $addresses
     * @return array email => name|null
     */
    protected function getRecipients(array $addresses): array
    {
        $recipients = [];
        foreach ($addresses as $address) {
            if ($address->mailbox === 'INVALID_ADDRESS'
                || $address->host === '.SYNTAX-ERROR.'
                || trim($address->mailbox) === ''
                || trim($address->host) === ''
            ) {
                continue;
            }
            $recipients[$this->getEmail($address)] = $this->getName($address);
        }

        return $recipients;
    }

    /**
     * Display name without RFC 5322 quotes, which Webklex keeps when ext-imap is not used
     */
    protected function getName(Address $address): ?string
    {
        $name = trim($address->personal);
        if (strlen($name) > 1 && $name[0] === '"' && substr($name, -1) === '"') {
            $name = trim(stripcslashes(substr($name, 1, -1)));
        }

        return $name !== '' ? $this->encode($name) : null;
    }

    protected function getEmail(Address $address): string
    {
        if ($address->mailbox !== '' && $address->host !== '') {
            return strtolower($address->mailbox . '@' . $address->host);
        }

        return strtolower($address->mail);
    }

    /**
     * @param Attribute|mixed $attribute
     * @return Address[]
     */
    protected function getAddresses($attribute): array
    {
        $values = $attribute instanceof Attribute ? $attribute->all() : (array)$attribute;

        return array_values(array_filter($values, static function ($value) {
            return $value instanceof Address;
        }));
    }

    /**
     * @param Attribute|mixed $attribute
     * @return mixed|null
     */
    protected function firstValue($attribute)
    {
        if ($attribute instanceof Attribute) {
            return $attribute->count() > 0 ? $attribute->first() : null;
        }

        return $attribute;
    }

    /**
     * @param Attribute|mixed $attribute
     */
    protected function toString($attribute): string
    {
        if ($attribute instanceof Attribute) {
            return $attribute->count() > 0 ? $attribute->toString() : '';
        }

        return is_scalar($attribute) ? (string)$attribute : '';
    }

    /**
     * Webklex returns UTF-8, convert to $serverEncoding
     */
    protected function encode(string $value): string
    {
        if ($value === '' || in_array(strtolower($this->serverEncoding), ['utf-8', 'utf8'], true)) {
            return $value;
        }
        $converted = @iconv('UTF-8', $this->serverEncoding . '//IGNORE', $value);
        if ($converted === false) {
            try {
                $converted = mb_convert_encoding($value, $this->serverEncoding, 'UTF-8');
            } catch (Throwable $e) {
                $converted = false;
            }
        }

        return $converted !== false ? $converted : $value;
    }

    /**
     * PHP Clean String of UTF8 Chars – Convert to similar ASCII char
     */
    public static function cleanString(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        // 1) convert á ô => a o
        $text = preg_replace("/[áàâãªä]/u", "a", $text);
        $text = preg_replace("/[ÁÀÂÃÄ]/u", "A", $text);
        $text = preg_replace("/[ÍÌÎÏ]/u", "I", $text);
        $text = preg_replace("/[íìîï]/u", "i", $text);
        $text = preg_replace("/[éèêë]/u", "e", $text);
        $text = preg_replace("/[ÉÈÊË]/u", "E", $text);
        $text = preg_replace("/[óòôõºö]/u", "o", $text);
        $text = preg_replace("/[ÓÒÔÕÖ]/u", "O", $text);
        $text = preg_replace("/[úùûü]/u", "u", $text);
        $text = preg_replace("/[ÚÙÛÜ]/u", "U", $text);
        $text = preg_replace("/[’‘‹›‚]/u", "'", $text);
        $text = preg_replace("/[“”«»„]/u", '"', $text);
        $text = str_replace(["–", " ", "ç", "Ç", "ñ", "Ñ"], ["-", " ", "c", "C", "n", "N"], $text);

        //2) Translation CP1252. &ndash; => -
        $trans = get_html_translation_table(HTML_ENTITIES);
        $trans[chr(130)] = '&sbquo;';    // Single Low-9 Quotation Mark
        $trans[chr(131)] = '&fnof;';    // Latin Small Letter F With Hook
        $trans[chr(132)] = '&bdquo;';    // Double Low-9 Quotation Mark
        $trans[chr(133)] = '&hellip;';    // Horizontal Ellipsis
        $trans[chr(134)] = '&dagger;';    // Dagger
        $trans[chr(135)] = '&Dagger;';    // Double Dagger
        $trans[chr(136)] = '&circ;';    // Modifier Letter Circumflex Accent
        $trans[chr(137)] = '&permil;';    // Per Mille Sign
        $trans[chr(138)] = '&Scaron;';    // Latin Capital Letter S With Caron
        $trans[chr(139)] = '&lsaquo;';    // Single Left-Pointing Angle Quotation Mark
        $trans[chr(140)] = '&OElig;';    // Latin Capital Ligature OE
        $trans[chr(145)] = '&lsquo;';    // Left Single Quotation Mark
        $trans[chr(146)] = '&rsquo;';    // Right Single Quotation Mark
        $trans[chr(147)] = '&ldquo;';    // Left Double Quotation Mark
        $trans[chr(148)] = '&rdquo;';    // Right Double Quotation Mark
        $trans[chr(149)] = '&bull;';    // Bullet
        $trans[chr(150)] = '&ndash;';    // En Dash
        $trans[chr(151)] = '&mdash;';    // Em Dash
        $trans[chr(152)] = '&tilde;';    // Small Tilde
        $trans[chr(153)] = '&trade;';    // Trade Mark Sign
        $trans[chr(154)] = '&scaron;';    // Latin Small Letter S With Caron
        $trans[chr(155)] = '&rsaquo;';    // Single Right-Pointing Angle Quotation Mark
        $trans[chr(156)] = '&oelig;';    // Latin Small Ligature OE
        $trans[chr(159)] = '&Yuml;';    // Latin Capital Letter Y With Diaeresis
        $trans['euro'] = '&euro;';    // euro currency symbol
        ksort($trans);

        foreach ($trans as $k => $v) {
            $text = str_replace($v, $k, $text);
        }

        // 5) remove Windows-1252 symbols like "TradeMark", "Euro"...
        return preg_replace('/[^(\x20-\x7F)]*/', '', $text);
    }
}
