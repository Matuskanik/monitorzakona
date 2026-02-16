#!/usr/bin/env php
<?php

/**
 * Spracuje všetky Slov-Lex zákony z daného roka – vygeneruje AI zhrnutia,
 * aby boli pripravené hneď pri otvorení zákona používateľom.
 *
 * Použitie: php bin/process-year-slovlex.php [rok]
 *   rok = rok na spracovanie (default: aktuálny rok)
 *
 * Príklad: php bin/process-year-slovlex.php 2026
 *
 * Skript volá summarize-slovlex.php s limitom 0 (všetky) a filtrom roka.
 */

$year = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : (int) date('Y');
$scriptDir = __DIR__;
$summarizeScript = $scriptDir . '/summarize-slovlex.php';

if (!is_file($summarizeScript)) {
    fwrite(STDERR, "Chyba: nenájdený skript {$summarizeScript}\n");
    exit(1);
}

echo "Spracovanie Slov-Lex zákonov z roka {$year}...\n";
echo str_repeat('=', 50) . "\n";

passthru(sprintf('php %s 0 %d', escapeshellarg($summarizeScript), $year), $exitCode);

exit($exitCode);
