<?php
declare(strict_types=1);

namespace d3yii2\d3imap;

/**
 * Connection settings. Server, port, encryption and folder are taken from $imapPath,
 * the optional $host, $port, $encryption and $validateCert override them.
 */
class ImapConnection
{
    /** @var string|null c-client mailbox, e.g. {mail.example.com:993/imap/ssl/novalidate-cert}INBOX */
    public ?string $imapPath = '';

    /** @var string|null folder to read, overrides the folder of $imapPath */
    public ?string $activeFolder = '';
    public ?string $imapLogin = '';
    public ?string $imapPassword = '';

    /** @var string|null charset of returned texts, utf-8 if empty */
    public ?string $serverEncoding = '';

    /** @var string|null directory for attachment files, attachments are not saved if empty */
    public ?string $attachmentsDir = '';

    /** @deprecated not used, MIME headers are always decoded */
    public $decodeMimeStr;

    public ?string $host = null;
    public ?int $port = null;

    /** @var string|null ssl, tls, starttls or empty string for an unencrypted connection */
    public ?string $encryption = null;
    public ?bool $validateCert = null;

    /** @var int connection timeout in seconds */
    public int $timeout = 30;

    /** @var array Webklex "options", merged over Mailbox defaults */
    public array $clientOptions = [];

    /**
     * Webklex account configuration
     * @throws Exception
     */
    public function getAccountConfig(): array
    {
        $path = $this->getParsedImapPath();
        $host = $this->host ?: ($path ? $path->host : '');
        if ($host === '') {
            throw new Exception('IMAP host is not set, set ImapConnection::$imapPath or $host');
        }
        if ($this->encryption !== null) {
            $encryption = $this->encryption !== '' ? strtolower($this->encryption) : null;
        } else {
            $encryption = $path ? $path->encryption : ImapPath::ENCRYPTION_SSL;
        }
        if ($this->port) {
            $port = $this->port;
        } elseif ($path) {
            $port = $path->port;
        } else {
            $port = ImapPath::defaultPort($encryption);
        }
        if ($this->validateCert !== null) {
            $validateCert = $this->validateCert;
        } else {
            $validateCert = !$path || $path->validateCert;
        }

        return [
            'host' => $host,
            'port' => $port,
            'protocol' => 'imap',
            'encryption' => $encryption ?? false,
            'validate_cert' => $validateCert,
            'username' => (string)$this->imapLogin,
            'password' => (string)$this->imapPassword,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * @throws Exception
     */
    public function getFolderPath(): string
    {
        if ($this->activeFolder) {
            return $this->activeFolder;
        }
        $path = $this->getParsedImapPath();

        return $path && $path->folder ? $path->folder : Mailbox::FOLDER_INBOX;
    }

    /**
     * @throws Exception
     */
    public function getParsedImapPath(): ?ImapPath
    {
        return $this->imapPath ? ImapPath::parse($this->imapPath) : null;
    }
}
