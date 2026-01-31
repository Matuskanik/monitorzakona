<?php

namespace App;

class Auth
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->startSession();
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function register(string $email, string $password, bool $termsAccepted): array
    {
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Neplatná emailová adresa'];
        }

        // Check if user exists
        $existing = $this->db->findUserByEmail($email);
        if ($existing) {
            return ['success' => false, 'error' => 'Používateľ s týmto emailom už existuje'];
        }

        // Validate password
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Heslo musí mať minimálne 8 znakov'];
        }

        // Check terms acceptance
        if (!$termsAccepted) {
            return ['success' => false, 'error' => 'Musíte súhlasiť s podmienkami používania'];
        }

        // Create user
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $userId = $this->db->createUser($email, $passwordHash, null, true);

        // Auto-login
        $this->login($email, $password);

        return ['success' => true, 'user_id' => $userId];
    }

    public function login(string $email, string $password): array
    {
        $user = $this->db->findUserByEmail($email);
        if (!$user) {
            return ['success' => false, 'error' => 'Nesprávny email alebo heslo'];
        }

        // Check password
        if (!empty($user['password_hash']) && !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Nesprávny email alebo heslo'];
        }

        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];

        return ['success' => true, 'user' => $user];
    }

    public function loginWithGoogle(string $googleId, string $email, ?string $name = null): array
    {
        // Check if user exists with Google ID
        $user = $this->db->findUserByGoogleId($googleId);
        
        if (!$user) {
            // Check if user exists with email (link accounts)
            $user = $this->db->findUserByEmail($email);
            if ($user) {
                // Update existing user with Google ID
                $stmt = $this->db->getPdo()->prepare("UPDATE users SET google_id = ? WHERE id = ?");
                $stmt->execute([$googleId, $user['id']]);
            } else {
                // Create new user
                $userId = $this->db->createUser($email, null, $googleId, true);
                $user = $this->db->findUserById($userId);
            }
        }

        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];

        return ['success' => true, 'user' => $user];
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        session_destroy();
    }

    public function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public function getUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public function getUserEmail(): ?string
    {
        return $_SESSION['user_email'] ?? null;
    }

    public function getUser(): ?array
    {
        $userId = $this->getUserId();
        if (!$userId) {
            return null;
        }
        return $this->db->findUserById($userId);
    }

    public function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }
    }

    /** Whether the current user has an active paid subscription (v7). */
    public function isPaid(): bool
    {
        $user = $this->getUser();
        if (!$user) {
            return false;
        }
        $status = $user['subscription_status'] ?? 'free';
        if ($status !== 'active' && $status !== 'trialing') {
            return false;
        }
        $end = $user['subscription_current_period_end'] ?? null;
        if ($end === null || $end === '') {
            return true; // no end date = treat as active
        }
        return strtotime($end) > time();
    }
}
