<?php
/**
 * HappyPet Customer - Vercel Serverless Entrypoint & Router
 * Disalurkan oleh vercel.json via vercel-php runtime
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = '/' . ltrim($uri, '/');

// Mapping URL ke file PHP
if ($uri === '/' || $uri === '/index.php') {
    require __DIR__ . '/../index.php';
    exit;
}

if ($uri === '/login' || $uri === '/login.php') {
    require __DIR__ . '/../login.php';
    exit;
}

if ($uri === '/logout' || $uri === '/logout.php') {
    require __DIR__ . '/../logout.php';
    exit;
}

if ($uri === '/struk' || $uri === '/struk.php') {
    require __DIR__ . '/../struk.php';
    exit;
}

if ($uri === '/api' || $uri === '/api.php' || str_starts_with($uri, '/api.php/')) {
    require __DIR__ . '/../api.php';
    exit;
}

// Fallback jika file fisik ada di root project
$targetFile = __DIR__ . '/..' . $uri;
if (file_exists($targetFile) && !is_dir($targetFile) && str_ends_with($targetFile, '.php')) {
    require $targetFile;
    exit;
}

// Default fallback
require __DIR__ . '/../index.php';
