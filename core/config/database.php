<?php

declare(strict_types=1);

return [
    'uri'      => getenv('MONGODB_URI') ?: 'mongodb://localhost:27017',
    'database' => getenv('MONGODB_DATABASE') ?: 'nms',
    'options'  => [
        'connectTimeoutMS'         => 5000,
        'serverSelectionTimeoutMS' => 5000,
        'socketTimeoutMS'          => 30000,
    ],
];
