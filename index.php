<?php

$publicDir = __DIR__ . '/public';
if (is_dir($publicDir)) {
    header('Location: /public/', true, 302);
    exit;
}

http_response_code(404);
echo 'Public directory not found.';
