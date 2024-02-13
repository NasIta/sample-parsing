## Мини парсер по VIN

### Требования:

1. `php version >= 8.1`
2. `composer version 2`

### Настройка:

1. `composer install`
2. добавить файл `/config/main-local.php` и заполнить актуальными кредами таким образом:

``` php
<?php

return [
    'baseUrl' => 'https://example.com',
    'login' => 'myLogin',
    'password' => 'myPass',
];
``` 


3. `composer dump-autoload`
4. выдать права на папку

### Посмотреть результат:

``` shell
php index.php 1FMYU01B72KC19127
```