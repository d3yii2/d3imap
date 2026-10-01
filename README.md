
yii2 Imap
==========
This library can be used to read mails from IMAP server using PHP and Yii2.

Installation by composer
------------
```composer
{
    "require": {
       "d3yii2/d3imap": "dev-master"
    }
}
```

Or
```
$ composer require d3yii2/d3imap "dev-master"
```

### Config example

```php
'imap' => [
    'class' => '',
    'connection' => [
        'imapPath' => '',
        'imapLogin' => '',
        'imapPassword' => '',
        'serverEncoding' => 'utf-8',
        'searchEncoding' => 'US-ASCII',//MIME character set to use when searching strings. Not mandatory
        'attachmentsDir' => '/tmp',
        'decodeMimeStr' => false
    ]
]

