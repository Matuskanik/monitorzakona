<?php
// Entry point - serve static HTML version
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$publicDir = __DIR__ . '/public';

// Parse the request
$path = parse_url($requestUri, PHP_URL_PATH);

// If requesting root, serve index.html
if ($path === '/' || $path === '') {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($publicDir . '/index.html');
    exit;
}

// If requesting a static file that exists, serve it
$filePath = $publicDir . $path;
if (file_exists($filePath) && is_file($filePath)) {
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $mimeTypes = [
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
    ];
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $contentType);
    readfile($filePath);
    exit;
}

// Fallback to index.html for SPA-like behavior
header('Content-Type: text/html; charset=UTF-8');
readfile($publicDir . '/index.html');
