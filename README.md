
yii2 Imap
==========
Reads mails from an IMAP server with Yii2. Uses [webklex/php-imap](https://www.php-imap.com/),
the native PHP `imap` extension (removed from PHP 8.4 core) is not needed.

Installation by composer
------------
```
$ composer require d3yii2/d3imap "dev-master"
```

Connection
----------
`ImapConnection::$imapPath` uses the c-client format of the former `imap_open()`:
`{host[:port][/flags]}[folder]`, e.g. `{mail.example.com:993/imap/ssl/novalidate-cert}INBOX`.

| flag | meaning |
|---|---|
| `/ssl` | implicit TLS, default port 993 (also used for port 993 without `/ssl`, `/tls`, `/notls`) |
| `/tls` | STARTTLS, default port 143 |
| `/notls` | no encryption |
| `/novalidate-cert` | do not validate the server certificate |

POP3 and NNTP are not supported. `activeFolder` overrides the folder of `imapPath` (default `INBOX`).
`host`, `port`, `encryption` (`ssl`, `tls`, `starttls` or `''`), `validateCert`, `timeout` and Webklex
`clientOptions` can be set explicitly.

Usage
-----
```php
$connection = new \d3yii2\d3imap\ImapConnection();
$connection->imapPath = '{mail.example.com:993/imap/ssl}INBOX';
$connection->imapLogin = 'user@example.com';
$connection->imapPassword = '...';
$connection->serverEncoding = 'utf-8';
$connection->attachmentsDir = Yii::getAlias('@runtime/imap');

$mailbox = new \d3yii2\d3imap\Mailbox($connection);
$mailbox->readMailParts = false;
foreach ($mailbox->searchMailboxUnseen() as $uid) {
    $mail = $mailbox->getMail($uid, false); // header only, \Seen is not changed
    $mailbox->getMailParts($mail);           // bodies and attachments (files in attachmentsDir)
    $mailbox->markMailAsRead($uid);
}
$mailbox->disconnect();
```

Mail ids are IMAP UIDs. Fetching never sets `\Seen`, except `getMail($uid, true)` which marks the mail
as read after its parts are loaded. `IncomingMail::$messageId` keeps the Message-ID header as written,
including `<>`, like the former ext-imap implementation. Errors are thrown as `d3yii2\d3imap\Exception`,
the Webklex exception is available via `getPrevious()`. `getImapClient()`, `getFolder()` and
`getMessage()` give access to the Webklex objects.

### Component

```php
'imap' => [
    'class' => \d3yii2\d3imap\Imap::class,
    'connection' => [
        'imapPath' => '{imap.gmail.com:993/imap/ssl}INBOX',
        'imapLogin' => '',
        'imapPassword' => '',
        'serverEncoding' => 'utf-8',
        'attachmentsDir' => '@runtime/imap',
    ]
]
```
`Yii::$app->imap->getMailbox()` returns the `Mailbox`.

Tests
-----
Run from the application root (the directory with `vendor`):
```
vendor/bin/phpunit -c vendor/d3yii2/d3imap/phpunit.xml.dist
```
- `unit` - no IMAP server needed.
- `integration` - reads the mailbox from `MAILER_IMAP_TRANSPORT_HOST`, `_PORT`, `_ENCRYPTION`, `_USERNAME`,
  `_PASSWORD` in `app_env/.env` (skipped if not set). Read only, checks that `\Seen` flags stay unchanged.
  Optional: `D3IMAP_TEST_FOLDER`, `D3IMAP_TEST_MAILS` (count of latest mails to read, default 5).
- `D3IMAP_TEST_WRITE=1` also runs the tests which change the mailbox: they create temporary folders
  `INBOX<delimiter>d3imap-test-*`, append a test mail, set flags, move, delete and expunge it and delete
  the folders.
