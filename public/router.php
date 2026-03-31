<?php

declare(strict_types=1);

/**
 * Built-in PHP server router for Symphony Web UI.
 *
 * Routes API requests to the ApiHandler, serves static files otherwise.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// API routes
if (str_starts_with($uri, '/api/')) {
    require __DIR__.'/../app/Http/ApiHandler.php';
    exit;
}

// Static files — let the built-in server handle them
if ($uri !== '/' && file_exists(__DIR__.$uri)) {
    return false;
}

// SPA fallback — serve index.html for all other routes
$indexPath = __DIR__.'/index.html';
if (file_exists($indexPath)) {
    readfile($indexPath);
    exit;
}

http_response_code(404);
echo 'Not Found';
