# Laravel filesystem Akamai Netstorage (UNOFFICIAL)

[Akamai NetStorage](https://www.akamai.com/products/netstorage) for Laravel based on [igorkgg/flysystem-akamai-netstorage](https://github.com/igorkosteski/flysystem-akamai-netstorage).

# Requirement

-   Laravel >= 12.0

# Installation

```shell
$ composer require "igorkgg/laravel-filesystem-akamai-netstorage"
```

# Configuration

```php
<?php

return [
   'disks' => [
        //...
        'akamai' => [
          'driver'   => 'akamai',
           'key'      => env('AKAMAI_KEY'),
           'keyName'  => env('AKAMAI_KEY_NAME'),
           'hostname' => env('AKAMAI_HOSTNAME'),
           'cpCode'   => env('AKAMAI_CPCODE'),
           'basePath' => env('AKAMAI_BASE_PATH', ''),
           'baseUrl'  => env('AKAMAI_BASE_URL', ''),
           'timeout'  => env('AKAMAI_TIMEOUT', 300),
        ],
        //...
    ]
];
```

# Usage

```php

Storage::disk('akamai')->put('test.txt', 'Test file');

```

# License

MIT
