<?php

namespace d3yii2\d3imap;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Imap Component
 *
 * ~~~
 * 'components' => [
 *     'imap' => [
 *         'class' => \d3yii2\d3imap\Imap::class,
 *         'connection' => [
 *             'imapPath' => '{imap.gmail.com:993/imap/ssl}INBOX',
 *             'imapLogin' => 'username',
 *             'imapPassword' => 'password',
 *             'serverEncoding' => 'utf-8',
 *             'attachmentsDir' => '@runtime/imap',
 *         ],
 *     ],
 * ],
 * ~~~
 *
 * Usage: $uids = Yii::$app->imap->getMailbox()->searchMailboxUnseen();
 */
class Imap extends Component
{
    private array $connectionParams = [];
    private ?ImapConnection $connection = null;
    private ?Mailbox $mailbox = null;

    /**
     * @param array $connectionParams ImapConnection property values
     * @throws InvalidConfigException
     */
    public function setConnection($connectionParams): void
    {
        if (!is_array($connectionParams)) {
            throw new InvalidConfigException('You should set connection params in your config. Please read d3imap README');
        }
        $this->connectionParams = $connectionParams;
        $this->connection = null;
        $this->mailbox = null;
    }

    /**
     * @throws InvalidConfigException
     */
    public function getConnection(): ImapConnection
    {
        if ($this->connection === null) {
            $this->connection = $this->createConnection();
        }

        return $this->connection;
    }

    /**
     * @throws InvalidConfigException
     */
    public function createConnection(): ImapConnection
    {
        $imapConnection = new ImapConnection();
        foreach ($this->connectionParams as $name => $value) {
            if (!property_exists($imapConnection, $name)) {
                throw new InvalidConfigException('Unknown IMAP connection param "' . $name . '"');
            }
            $imapConnection->$name = $value;
        }
        if ($imapConnection->attachmentsDir) {
            $attachmentsDir = Yii::getAlias($imapConnection->attachmentsDir);
            if (!is_dir($attachmentsDir)) {
                throw new InvalidConfigException('Directory "' . $attachmentsDir . '" not found');
            }
            $imapConnection->attachmentsDir = rtrim(realpath($attachmentsDir), '\\/');
        }

        return $imapConnection;
    }

    /**
     * @throws InvalidConfigException
     */
    public function getMailbox(): Mailbox
    {
        if ($this->mailbox === null) {
            $this->mailbox = new Mailbox($this->getConnection());
        }

        return $this->mailbox;
    }
}
