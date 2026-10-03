<?php

namespace d3yii2\d3imap;

class IncomingMailAttachment
{
    /** @var string Content-ID or content hash */
    public $id;

    /** @var string original file name, always with extension */
    public $name;

    /** @var string|null saved file, null if ImapConnection::$attachmentsDir is not set */
    public $filePath;
}
