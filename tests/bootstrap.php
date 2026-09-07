<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/helpers.php';

new Kirby\Cms\App([
    'roots' => [
        'index'   => dirname(__DIR__),
        'content' => __DIR__ . '/fixtures/content',
    ],
]);
