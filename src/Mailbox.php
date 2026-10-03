<?php

namespace d3yii2\d3imap;

use stdClass;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\EncodingAliases;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;
use Yii;

/**
 * IMAP mailbox based on webklex/php-imap, does not need the native imap extension.
 * Mail ids are UIDs in the folder ImapConnection::getFolderPath().
 */
class Mailbox
{
    public const FOLDER_INBOX = 'INBOX';

    /** @var bool getMail() loads bodies and attachments too */
    public $readMailParts = true;

    protected ImapConnection $imapConnection;
    protected ?ClientManager $clientManager;
    protected IncomingMailFactory $mailFactory;
    protected ?Client $client = null;
    protected ?Folder $folder = null;

    /** @var bool $markAsSeen of the last getMail() call, applied when the mail parts are loaded */
    private bool $markAsSeen = false;

    public function __construct(ImapConnection $imapConnection, ?ClientManager $clientManager = null)
    {
        $this->imapConnection = $imapConnection;
        $this->clientManager = $clientManager;
        $this->mailFactory = new IncomingMailFactory(
            (string)$imapConnection->serverEncoding,
            $imapConnection->attachmentsDir ?: null
        );
    }

    /**
     * Connected client
     * @throws Exception
     */
    public function getImapClient(): Client
    {
        if ($this->client === null) {
            $client = $this->createClient();
            set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) {
                if (!(error_reporting() & $severity)) {
                    return false;
                }
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }, E_WARNING);
            try {
                $client->connect();
            } catch (\Exception $e) {
                throw $this->createException('Can not connect to ' . $this->getConnectionName(), $e);
            } finally {
                restore_error_handler();
            }
            $this->client = $client;
        }

        return $this->client;
    }

    /**
     * @throws Exception
     */
    public function getFolderPath(): string
    {
        return $this->imapConnection->getFolderPath();
    }

    /**
     * @throws Exception
     */
    public function getFolder(): Folder
    {
        if ($this->folder === null) {
            $path = $this->getFolderPath();
            $folder = $this->execute('Can not read folder "' . $path . '"', function () use ($path) {
                return $this->getImapClient()->getFolderByPath($path);
            });
            if ($folder === null) {
                throw new Exception('Folder "' . $path . '" not found in ' . $this->getConnectionName());
            }
            $this->folder = $folder;
        }

        return $this->folder;
    }

    /**
     * Gets status information about the active folder: messages, recent, unseen, uidnext, uidvalidity
     * @throws Exception
     */
    public function statusMailbox(): stdClass
    {
        return $this->execute('Can not get folder status', function () {
            return (object)$this->getFolder()->status();
        });
    }

    /**
     * Full names of all folders
     * @return string[]
     * @throws Exception
     */
    public function getListingFolders(): array
    {
        return $this->execute('Can not list folders', function () {
            $folders = [];
            foreach ($this->getImapClient()->getFolders(false) as $folder) {
                $folders[] = $folder->full_name;
            }
            return $folders;
        });
    }

    /**
     * Mails count in the active folder
     * @throws Exception
     */
    public function countMails(): int
    {
        return $this->execute('Can not count mails', function () {
            $status = $this->getImapClient()->openFolder($this->getFolder()->path, true);
            return (int)($status['exists'] ?? 0);
        });
    }

    /**
     * Searches the active folder
     *
     * @param string $criteria IMAP SEARCH criteria (RFC 3501, 6.4.4) as for imap_search(),
     *    e.g. 'ALL', 'UNSEEN', 'UNSEEN FROM "joey smith"', 'SINCE "01-Jan-2024"'
     * @return int[] mails UIDs
     * @throws Exception
     */
    public function searchMailbox(string $criteria = 'ALL'): array
    {
        $criteria = trim($criteria) !== '' ? trim($criteria) : 'ALL';

        return $this->execute('Search "' . $criteria . '" failed', function () use ($criteria) {
            $uids = $this->getFolder()->query()->where('CUSTOM ' . $criteria)->search()->all();
            return array_map('intval', array_values($uids));
        });
    }

    /**
     * @return int[]
     * @throws Exception
     */
    public function searchMailboxUnseen(): array
    {
        return $this->searchMailbox('UNSEEN');
    }

    /**
     * Get mail data. Bodies and attachments are loaded only if $readMailParts is true,
     * otherwise call getMailParts().
     *
     * @param int|string $mailId UID
     * @param bool $markAsSeen add \Seen flag after the mail parts are loaded
     * @throws Exception
     */
    public function getMail($mailId, bool $markAsSeen = true): IncomingMail
    {
        $this->markAsSeen = $markAsSeen;
        $message = $this->getMessage($mailId, $this->readMailParts);
        $mail = $this->execute('Can not read mail UID ' . $mailId . ' header', function () use ($message) {
            return $this->mailFactory->createFromMessage($message);
        });
        if ($this->readMailParts) {
            $this->loadMailParts($mail, $message);
        }

        return $mail;
    }

    /**
     * Load bodies and attachments, attachment files are saved to ImapConnection::$attachmentsDir
     * @throws Exception
     */
    public function getMailParts(IncomingMail $mail): IncomingMail
    {
        $this->loadMailParts($mail, $this->getMessage($mail->id));

        return $mail;
    }

    /**
     * Webklex message. Fetching never changes the \Seen flag.
     *
     * @param int|string $mailId UID
     * @throws Exception
     */
    public function getMessage($mailId, bool $withBody = true): Message
    {
        $uid = (int)$mailId;

        return $this->execute('Can not fetch mail UID ' . $uid, function () use ($uid, $withBody) {
            return $this->getFolder()
                ->query()
                ->leaveUnread()
                ->setFetchBody($withBody)
                ->getMessageByUid($uid);
        });
    }

    /**
     * Save mail as RFC 822 (eml) file
     *
     * @param int|string $mailId UID
     * @throws Exception
     */
    public function saveMail($mailId, string $filename = 'email.eml'): bool
    {
        $message = $this->getMessage($mailId);
        $header = $message->getHeader();
        $raw = ($header ? rtrim($header->raw, "\r\n") : '') . "\r\n\r\n" . $message->getRawBody();

        return file_put_contents($filename, $raw) !== false;
    }

    /**
     * Marks mail for deletion, it is removed by expungeDeletedMails()
     *
     * @param int|string $mailId UID
     * @throws Exception
     */
    public function deleteMail($mailId): bool
    {
        return $this->setFlag([$mailId], '\\Deleted');
    }

    /**
     * @param int|string $mailId UID
     * @param string $mailBox target folder path (UTF-8)
     * @throws Exception
     */
    public function moveMail($mailId, string $mailBox): bool
    {
        $uid = (int)$mailId;

        return $this->execute('Can not move mail UID ' . $uid . ' to "' . $mailBox . '"', function () use ($uid, $mailBox) {
            $folder = EncodingAliases::convert($mailBox, 'utf-8', 'utf7-imap');
            $this->getConnection()->moveMessage($folder, $uid, null, IMAP::ST_UID)->validatedData();
            return true;
        });
    }

    /**
     * Deletes all the mails marked for deletion
     * @throws Exception
     */
    public function expungeDeletedMails(): bool
    {
        return $this->execute('Expunge failed', function () {
            $this->getConnection()->expunge()->validatedData();
            return true;
        });
    }

    /**
     * @param int|string $mailId UID
     * @throws Exception
     */
    public function markMailAsRead($mailId): bool
    {
        return $this->setFlag([$mailId], '\\Seen');
    }

    /**
     * @param int|string $mailId UID
     * @throws Exception
     */
    public function markMailAsUnread($mailId): bool
    {
        return $this->clearFlag([$mailId], '\\Seen');
    }

    /**
     * @param int|string $mailId UID
     * @throws Exception
     */
    public function markMailAsImportant($mailId): bool
    {
        return $this->setFlag([$mailId], '\\Flagged');
    }

    /**
     * @throws Exception
     */
    public function markMailsAsRead(array $mailId): bool
    {
        return $this->setFlag($mailId, '\\Seen');
    }

    /**
     * @throws Exception
     */
    public function markMailsAsUnread(array $mailId): bool
    {
        return $this->clearFlag($mailId, '\\Seen');
    }

    /**
     * @throws Exception
     */
    public function markMailsAsImportant(array $mailId): bool
    {
        return $this->setFlag($mailId, '\\Flagged');
    }

    /**
     * @param array $mailsIds UIDs
     * @param string $flag \Seen, \Answered, \Flagged, \Deleted or \Draft
     * @throws Exception
     */
    public function setFlag(array $mailsIds, string $flag): bool
    {
        return $this->storeFlag($mailsIds, $flag, '+');
    }

    /**
     * @param array $mailsIds UIDs
     * @param string $flag \Seen, \Answered, \Flagged, \Deleted or \Draft
     * @throws Exception
     */
    public function clearFlag(array $mailsIds, string $flag): bool
    {
        return $this->storeFlag($mailsIds, $flag, '-');
    }

    public function disconnect(): void
    {
        if ($this->client !== null) {
            try {
                $this->client->disconnect();
            } catch (Throwable $e) {
                Yii::warning('IMAP disconnect from ' . $this->getConnectionName() . ' failed: ' . $e->getMessage(), __METHOD__);
            }
        }
        $this->client = null;
        $this->folder = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Webklex "options" used by default
     */
    public static function getDefaultClientOptions(): array
    {
        return [
            'fetch' => IMAP::FT_PEEK,
            'sequence' => IMAP::ST_UID,
            'fetch_body' => true,
            'fetch_flags' => true,
            'soft_fail' => false,
            // same header parser with and without ext-imap
            'rfc822' => false,
            'fallback_date' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Default options overridden by ImapConnection::$clientOptions
     */
    protected function getClientOptions(): array
    {
        return array_merge(self::getDefaultClientOptions(), $this->imapConnection->clientOptions);
    }

    /**
     * @throws Exception
     */
    protected function createClient(): Client
    {
        $clientManager = $this->clientManager ?? new ClientManager(['options' => $this->getClientOptions()]);

        return $this->execute('Can not create IMAP client', function () use ($clientManager) {
            return $clientManager->make($this->imapConnection->getAccountConfig());
        });
    }

    /**
     * @throws Exception
     */
    protected function loadMailParts(IncomingMail $mail, Message $message): void
    {
        $this->execute('Can not read mail UID ' . $mail->id . ' parts', function () use ($mail, $message) {
            $this->mailFactory->fillParts($mail, $message);
        });
        if ($this->markAsSeen) {
            $this->markMailAsRead($mail->id);
        }
    }

    /**
     * Connection with the active folder selected
     * @throws \Exception
     */
    protected function getConnection(): ProtocolInterface
    {
        $client = $this->getImapClient();
        $client->openFolder($this->getFolder()->path);

        return $client->getConnection();
    }

    /**
     * @throws Exception
     */
    protected function storeFlag(array $mailsIds, string $flag, string $mode): bool
    {
        $action = ($mode === '+' ? 'set' : 'clear') . ' flag ' . $flag;

        return $this->execute('Can not ' . $action, function () use ($mailsIds, $flag, $mode) {
            $connection = $this->getConnection();
            foreach ($mailsIds as $mailId) {
                $uid = (int)$mailId;
                $connection->store([$flag], $uid, $uid, $mode, true, IMAP::ST_UID)->validatedData();
            }
            return true;
        });
    }

    /**
     * Runs $callback, converts exceptions to Exception
     * @return mixed
     * @throws Exception
     */
    protected function execute(string $errorMessage, callable $callback)
    {
        try {
            return $callback();
        } catch (Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            throw $this->createException($errorMessage, $e);
        }
    }

    protected function createException(string $message, \Exception $previous): Exception
    {
        $details = [];
        for ($e = $previous; $e !== null; $e = $e->getPrevious()) {
            $text = trim(str_replace(["\r", "\n", "\t"], ' ', $e->getMessage()));
            if ($text !== '' && !in_array($text, $details, true)) {
                $details[] = $text;
            }
        }
        if ($details) {
            $message .= ': ' . implode(' / ', $details);
        }

        return new Exception($message, 0, $previous);
    }

    protected function getConnectionName(): string
    {
        try {
            $config = $this->imapConnection->getAccountConfig();
            return $config['username'] . ' at ' . $config['host'] . ':' . $config['port'];
        } catch (Throwable $e) {
            return (string)$this->imapConnection->imapPath;
        }
    }
}
