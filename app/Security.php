<?php

namespace App;

class Security
{
    /**
     * Nastaví security headers pre HTTP odpoveď
     */
    public static function setSecurityHeaders(): void
    {
        // XSS Protection
        header('X-XSS-Protection: 1; mode=block');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');
        
        // Referrer Policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Content Security Policy
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://accounts.google.com/gsi/client https://accounts.google.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; frame-src 'self' https://accounts.google.com; connect-src 'self' https://api.openai.com https://accounts.google.com https://oauth2.googleapis.com https://www.googleapis.com;");
        
        // Permissions Policy
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }
    
    /**
     * Validuje integer ID z GET parametra
     */
    public static function validateIntegerId(?string $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        
        // Skontroluj či je číslo
        if (!is_numeric($id)) {
            return null;
        }
        
        $intId = (int)$id;
        
        // Skontroluj či je kladné číslo
        if ($intId <= 0) {
            return null;
        }
        
        return $intId;
    }
    
    /**
     * Validuje a sanitizuje search query
     */
    public static function validateSearchQuery(?string $query, int $maxLength = 200): string
    {
        if ($query === null) {
            return '';
        }
        
        // Odstráň biele znaky
        $query = trim($query);
        
        // Obmedz dĺžku
        if (mb_strlen($query) > $maxLength) {
            $query = mb_substr($query, 0, $maxLength);
        }
        
        return $query;
    }
    
    /**
     * Jednoduchý rate limiting pomocou session
     */
    public static function checkRateLimit(string $key, int $maxRequests = 60, int $timeWindow = 60): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $now = time();
        $sessionKey = 'rate_limit_' . $key;
        
        if (!isset($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = [
                'count' => 1,
                'start_time' => $now
            ];
            return true;
        }
        
        $rateLimit = $_SESSION[$sessionKey];
        
        // Ak uplynul časový okno, resetuj
        if ($now - $rateLimit['start_time'] > $timeWindow) {
            $_SESSION[$sessionKey] = [
                'count' => 1,
                'start_time' => $now
            ];
            return true;
        }
        
        // Ak prekročil limit, zamietni
        if ($rateLimit['count'] >= $maxRequests) {
            return false;
        }
        
        // Inkrementuj počítadlo
        $_SESSION[$sessionKey]['count']++;
        return true;
    }
    
    /**
     * Detekcia základných botov podľa User-Agent
     */
    public static function isBot(?string $userAgent = null): bool
    {
        if ($userAgent === null) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }
        
        $botPatterns = [
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget',
            'python', 'java', 'go-http', 'scrapy', 'headless'
        ];
        
        $userAgentLower = mb_strtolower($userAgent);
        
        foreach ($botPatterns as $pattern) {
            if (strpos($userAgentLower, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Sanitizuje výstup pre HTML
     */
    public static function escape(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}
