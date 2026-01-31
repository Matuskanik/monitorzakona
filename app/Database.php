<?php

namespace App;

class Database
{
    private \PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dbPath = trim($dbPath);
        if ($dbPath === '') {
            $dbPath = 'data/sentinel.db';
        }

        // Convert relative paths to absolute paths relative to project root
        if (!str_starts_with($dbPath, '/')) {
            $projectRoot = dirname(__DIR__);
            $dbPath = $projectRoot . '/' . $dbPath;
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // On Digital Ocean / Heroku, app dir may be read-only; use /tmp if dir not writable
        if (!is_writable($dir)) {
            $dbPath = '/tmp/sentinel.db';
        }

        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->bootstrap();
    }

    private function bootstrap(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS laws (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                master_id TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                approval_date TEXT,
                source_url TEXT NOT NULL,
                content_hash TEXT NOT NULL,
                ai_summary TEXT,
                processing_status TEXT DEFAULT 'pending',
                text_extracted INTEGER DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Migrate existing tables to add new columns if they don't exist
        try {
            $this->pdo->exec("ALTER TABLE laws ADD COLUMN processing_status TEXT DEFAULT 'pending'");
        } catch (\PDOException $e) {
            // Column already exists, ignore
        }
        
        try {
            $this->pdo->exec("ALTER TABLE laws ADD COLUMN text_extracted INTEGER DEFAULT 0");
        } catch (\PDOException $e) {
            // Column already exists, ignore
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                law_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                filepath TEXT NOT NULL,
                source_url TEXT,
                file_type TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (law_id) REFERENCES laws(id) ON DELETE CASCADE
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS processing_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                master_id TEXT NOT NULL,
                status TEXT NOT NULL,
                message TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // User tables for V5
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT,
                google_id TEXT UNIQUE,
                terms_accepted INTEGER DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS user_saved_laws (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                law_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (law_id) REFERENCES laws(id) ON DELETE CASCADE,
                UNIQUE(user_id, law_id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS user_chats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                law_id INTEGER NOT NULL,
                messages_json TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (law_id) REFERENCES laws(id) ON DELETE CASCADE,
                UNIQUE(user_id, law_id)
            )
        ");

        // Stripe / subscription columns on users (v7)
        try {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN stripe_customer_id TEXT");
        } catch (\PDOException $e) { /* column exists */ }
        try {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN subscription_status TEXT DEFAULT 'free'");
        } catch (\PDOException $e) { /* column exists */ }
        try {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN subscription_plan TEXT");
        } catch (\PDOException $e) { /* column exists */ }
        try {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN subscription_current_period_end TEXT");
        } catch (\PDOException $e) { /* column exists */ }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS user_pdf_downloads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                downloaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function findLawByMasterId(string $masterId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM laws WHERE master_id = ?");
        $stmt->execute([$masterId]);
        return $stmt->fetch() ?: null;
    }

    public function saveLaw(array $data): int
    {
        $existing = $this->findLawByMasterId($data['master_id']);
        
        $processingStatus = $data['processing_status'] ?? 'completed';
        $textExtracted = isset($data['text_extracted']) ? ($data['text_extracted'] ? 1 : 0) : 1;
        
        if ($existing) {
            $stmt = $this->pdo->prepare("
                UPDATE laws 
                SET title = ?, approval_date = ?, source_url = ?, content_hash = ?, 
                    ai_summary = ?, processing_status = ?, text_extracted = ?, updated_at = CURRENT_TIMESTAMP
                WHERE master_id = ?
            ");
            $stmt->execute([
                $data['title'],
                $data['approval_date'],
                $data['source_url'],
                $data['content_hash'],
                $data['ai_summary'] ?? null,
                $processingStatus,
                $textExtracted,
                $data['master_id']
            ]);
            return $existing['id'];
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO laws (master_id, title, approval_date, source_url, content_hash, ai_summary, processing_status, text_extracted)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['master_id'],
                $data['title'],
                $data['approval_date'],
                $data['source_url'],
                $data['content_hash'],
                $data['ai_summary'] ?? null,
                $processingStatus,
                $textExtracted
            ]);
            return $this->pdo->lastInsertId();
        }
    }

    public function saveAttachment(int $lawId, array $data): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO attachments (law_id, filename, filepath, source_url, file_type)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $lawId,
            $data['filename'],
            $data['filepath'],
            $data['source_url'] ?? null,
            $data['file_type'] ?? null
        ]);
    }

    public function getAttachments(int $lawId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM attachments WHERE law_id = ? ORDER BY created_at");
        $stmt->execute([$lawId]);
        return $stmt->fetchAll();
    }

    public function logProcessing(string $masterId, string $status, ?string $message = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO processing_log (master_id, status, message)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$masterId, $status, $message]);
    }

    public function getLatestLaws(int $limit = 50): array
    {
        // Order by approval_date (date of publication) descending, then by created_at
        // Since approval_date is in Slovak format (DD. MM. YYYY), we need to parse it
        // We'll use a subquery to convert the date format for proper sorting
        $stmt = $this->pdo->prepare("
            SELECT * FROM laws 
            ORDER BY 
                CASE 
                    WHEN approval_date IS NOT NULL AND approval_date != '' THEN
                        -- Convert Slovak date format (DD. MM. YYYY) to sortable format
                        -- Extract year, month, day and create YYYY-MM-DD format
                        substr('0000' || substr(approval_date, -4), -4) || '-' ||
                        substr('00' || substr(approval_date, length(approval_date) - 7, 2), -2) || '-' ||
                        substr('00' || substr(approval_date, 1, 2), -2)
                    ELSE '0000-00-00'
                END DESC,
                created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $laws = $stmt->fetchAll();
        
        // Additional PHP sorting as fallback for edge cases
        usort($laws, function($a, $b) {
            $dateA = $this->parseSlovakDate($a['approval_date'] ?? '');
            $dateB = $this->parseSlovakDate($b['approval_date'] ?? '');
            
            if ($dateA == $dateB) {
                // If dates are equal, sort by created_at
                $createdA = strtotime($a['created_at'] ?? '1970-01-01');
                $createdB = strtotime($b['created_at'] ?? '1970-01-01');
                return $createdB <=> $createdA; // DESC
            }
            
            return $dateB <=> $dateA; // DESC
        });
        
        return $laws;
    }
    
    private function parseSlovakDate(?string $date): string
    {
        if (empty($date)) {
            return '0000-00-00';
        }
        
        // Parse Slovak date format: "DD. MM. YYYY" or "D. M. YYYY"
        // Remove extra spaces and normalize
        $date = trim($date);
        $date = preg_replace('/\s+/', ' ', $date);
        
        // Try to parse the date
        if (preg_match('/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/', $date, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            return "{$year}-{$month}-{$day}";
        }
        
        return '0000-00-00';
    }

    // User methods
    public function createUser(string $email, ?string $passwordHash = null, ?string $googleId = null, bool $termsAccepted = false): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO users (email, password_hash, google_id, terms_accepted)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $email,
            $passwordHash,
            $googleId,
            $termsAccepted ? 1 : 0
        ]);
        return $this->pdo->lastInsertId();
    }

    public function findUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function findUserByGoogleId(string $googleId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE google_id = ?");
        $stmt->execute([$googleId]);
        return $stmt->fetch() ?: null;
    }

    public function findUserById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function saveLawForUser(int $userId, int $lawId): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_saved_laws (user_id, law_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$userId, $lawId]);
            return true;
        } catch (\PDOException $e) {
            // Already saved, ignore
            return false;
        }
    }

    public function removeSavedLawForUser(int $userId, int $lawId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM user_saved_laws WHERE user_id = ? AND law_id = ?");
        $stmt->execute([$userId, $lawId]);
    }

    public function getUserSavedLaws(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT l.*, usl.created_at as saved_at
            FROM user_saved_laws usl
            JOIN laws l ON usl.law_id = l.id
            WHERE usl.user_id = ?
            ORDER BY usl.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function isLawSavedByUser(int $userId, int $lawId): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM user_saved_laws WHERE user_id = ? AND law_id = ?");
        $stmt->execute([$userId, $lawId]);
        return $stmt->fetch() !== false;
    }

    public function saveUserChat(int $userId, int $lawId, array $messages): void
    {
        $messagesJson = json_encode($messages, JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare("
            INSERT INTO user_chats (user_id, law_id, messages_json, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(user_id, law_id) DO UPDATE SET
                messages_json = ?,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$userId, $lawId, $messagesJson, $messagesJson]);
    }

    public function getUserChat(int $userId, int $lawId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM user_chats WHERE user_id = ? AND law_id = ?");
        $stmt->execute([$userId, $lawId]);
        $chat = $stmt->fetch();
        if ($chat) {
            $chat['messages'] = json_decode($chat['messages_json'], true) ?: [];
            return $chat;
        }
        return null;
    }

    public function getUserChats(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT uc.*, l.title as law_title, l.master_id
            FROM user_chats uc
            JOIN laws l ON uc.law_id = l.id
            WHERE uc.user_id = ?
            ORDER BY uc.updated_at DESC
        ");
        $stmt->execute([$userId]);
        $chats = $stmt->fetchAll();
        foreach ($chats as &$chat) {
            $chat['messages'] = json_decode($chat['messages_json'], true) ?: [];
        }
        return $chats;
    }

    // Stripe / subscription (v7)
    public function findUserByStripeCustomerId(string $stripeCustomerId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE stripe_customer_id = ?");
        $stmt->execute([$stripeCustomerId]);
        return $stmt->fetch() ?: null;
    }

    public function setStripeCustomerId(int $userId, string $stripeCustomerId): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET stripe_customer_id = ? WHERE id = ?");
        $stmt->execute([$stripeCustomerId, $userId]);
    }

    public function updateUserSubscription(int $userId, string $status, ?string $plan = null, ?string $currentPeriodEnd = null): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE users SET subscription_status = ?, subscription_plan = ?, subscription_current_period_end = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $plan, $currentPeriodEnd, $userId]);
    }

    public function getPdfDownloadCount(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM user_pdf_downloads WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function recordPdfDownload(int $userId): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO user_pdf_downloads (user_id) VALUES (?)");
        $stmt->execute([$userId]);
    }
}

