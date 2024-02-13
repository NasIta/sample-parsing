<?php

$params = require __DIR__ . '/main-local.php';

if (empty($params)){
    $params = [];
}

return array_merge(
    [
        'baseUrl' => 'https://example.com',
        'login' => '',
        'password' => '',
    ],
    $params
);

?>