<?php

return [
    'database' => [
        'host' => '127.0.0.1',
        'name' => 'gallery_cms',
        'user' => 'gallery_user',
        'password' => 'change-me',
        'charset' => 'utf8mb4',
    ],
    'base_url' => '',
    'galleries_root' => __DIR__ . '/galleries',
    'zip_cache_path' => __DIR__ . '/cache/zips',
    'admin_session_name' => 'gallery_admin_session',
    'visitor_vote_secret' => 'replace-with-a-long-random-secret',
    'setup_key' => 'replace-with-a-temporary-setup-key',
];

