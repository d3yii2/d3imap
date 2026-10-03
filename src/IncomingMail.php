<?php

namespace d3yii2\d3imap;

/**
 * Copyright (c) 2012 by Barbushin Sergey <barbushin@gmail.com>.
 * All rights reserved.
 */
class IncomingMail
{
    /** @var int IMAP UID */
    public $id;

    /** @var string Y-m-d H:i:s in the PHP default timezone */
    public $date;
    public $subject;

    public $fromName;
    public $fromAddress;

    /** @var array email => name|null */
    public $to = [];
    public $toString;

    /** @var array email => name|null */
    public $cc = [];

    /** @var array email => name|null */
    public $replyTo = [];

    public $textPlain;
    public $textHtml;

    /** @var string Message-ID header with angle brackets, ASCII only */
    public $messageId;

    /** @var IncomingMailAttachment[] */
    protected $attachments = [];

    public function addAttachment(IncomingMailAttachment $attachment)
    {
        $this->attachments[$attachment->id] = $attachment;
    }

    /**
     * @return IncomingMailAttachment[]
     */
    public function getAttachments()
    {
        return $this->attachments;
    }

    /**
     * Get array of internal HTML links placeholders
     * @return array attachmentId => link placeholder
     */
    public function getInternalLinksPlaceholders()
    {
        return preg_match_all('/=["\'](ci?d:([\w\.%*@-]+))["\']/i', (string)$this->textHtml, $matches)
            ? array_combine($matches[2], $matches[1])
            : [];
    }

    public function replaceInternalLinks($baseUri)
    {
        $baseUri = rtrim($baseUri, '\\/') . '/';
        $fetchedHtml = $this->textHtml;
        foreach ($this->getInternalLinksPlaceholders() as $attachmentId => $placeholder) {
            if (isset($this->attachments[$attachmentId])) {
                $fetchedHtml = str_replace(
                    $placeholder,
                    $baseUri . basename($this->attachments[$attachmentId]->filePath),
                    $fetchedHtml
                );
            }
        }
        return $fetchedHtml;
    }
}
