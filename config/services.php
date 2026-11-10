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
        // Plane web app URL for "Open in Plane" links (defaults to base_url)
        'app_url' => env('PLANE_APP_URL'),
        // Only this solidtime organization moves Plane work items (the API key acts on the whole Plane workspace)
        'organization_id' => env('PLANE_ORGANIZATION_ID'),
    ],
];
