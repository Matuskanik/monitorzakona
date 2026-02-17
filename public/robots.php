<?php
/**
 * Dynamic robots.txt – blocks crawlers when MAINTENANCE_MODE is on
 */
$mode = getenv('MAINTENANCE_MODE') ?: ($_SERVER['MAINTENANCE_MODE'] ?? $_ENV['MAINTENANCE_MODE'] ?? '');
$maintenance = ($mode !== '' && $mode !== '0' && strtolower($mode) !== 'false');

header('Content-Type: text/plain; charset=utf-8');
if ($maintenance) {
    echo "User-agent: *\nDisallow: /\n";
} else {
    echo "User-agent: *\nAllow: /\n";
}
