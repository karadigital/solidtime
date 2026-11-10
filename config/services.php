<?php

declare(strict_types=1);

return [
    'gotenberg' => [
        'url' => env('GOTENBERG_URL'),
        'basic_auth_username' => env('GOTENBERG_BASIC_AUTH_USERNAME'),
        'basic_auth_password' => env('GOTENBERG_BASIC_AUTH_PASSWORD'),
    ],

    'plane' => [
        'api_key' => env('PLANE_API_KEY'),
        'base_url' => env('PLANE_BASE_URL'),
        'workspace_slug' => env('PLANE_WORKSPACE_SLUG'),
    ],
];
