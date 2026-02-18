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

        // Origin: nrsr | slovlex_zz (Slov-Lex Zbierka zákonov)
        try {
            $this->pdo->exec("ALTER TABLE laws ADD COLUMN origin TEXT DEFAULT 'nrsr'");
        } catch (\PDOException $e) {
            // Column already exists, ignore
        }
        try {
            $this->pdo->exec("ALTER TABLE laws ADD COLUMN external_id TEXT");
        } catch (\PDOException $e) {
            // Column already exists, ignore
        }
        // Migrate existing rows to explicit nrsr
        try {
            $this->pdo->exec("UPDATE laws SET origin = 'nrsr' WHERE origin IS NULL OR origin = ''");
        } catch (\PDOException $e) {
            // ignore
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


        // Parliament monitoring tables
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS parliament_mps (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nrsr_id TEXT NOT NULL UNIQUE,
                full_name TEXT NOT NULL,
                first_name TEXT,
                last_name TEXT,
                title TEXT,
                party TEXT,
                club TEXT,
                district TEXT,
                birth_date TEXT,
                email TEXT,
                website TEXT,
                photo_url TEXT,
                profile_url TEXT,
                card_text TEXT,
                stats_json TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS parliament_votings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nrsr_voting_id TEXT NOT NULL UNIQUE,
                session_number INTEGER,
                voting_number INTEGER,
                title TEXT NOT NULL,
                voting_date TEXT,
                voting_time TEXT,
                result TEXT,
                result_text TEXT,
                present_count INTEGER,
                votes_for INTEGER,
                votes_against INTEGER,
                votes_abstain INTEGER,
                votes_absent INTEGER,
                votes_did_not_vote INTEGER,
                summary_text TEXT,
                source_url TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS parliament_votes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                voting_id INTEGER NOT NULL,
                mp_id INTEGER NOT NULL,
                vote_code TEXT,
                vote_label TEXT,
                club TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(voting_id, mp_id),
                FOREIGN KEY (voting_id) REFERENCES parliament_votings(id) ON DELETE CASCADE,
                FOREIGN KEY (mp_id) REFERENCES parliament_mps(id) ON DELETE CASCADE
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS parliament_monthly_reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                report_year INTEGER NOT NULL,
                report_month INTEGER NOT NULL,
                summary_text TEXT NOT NULL,
                stats_json TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(report_year, report_month)
            )
        ");

        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_parliament_votings_date ON parliament_votings(voting_date DESC, voting_time DESC)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_parliament_votes_voting ON parliament_votes(voting_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_parliament_votes_mp ON parliament_votes(mp_id)");

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

        // Chat for laws loaded from JSON only (no laws.id) – v7
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS user_chats_master (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                master_id TEXT NOT NULL,
                messages_json TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE(user_id, master_id)
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

        // law_chunks: persistent blocks for full-text / librarian (Slov-Lex + NR SR)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS law_chunks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                law_id INTEGER NOT NULL,
                chunk_index INTEGER NOT NULL,
                content TEXT NOT NULL,
                char_start INTEGER DEFAULT 0,
                char_end INTEGER DEFAULT 0,
                section_title TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (law_id) REFERENCES laws(id) ON DELETE CASCADE
            )
        ");
        try {
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_law_chunks_law_id ON law_chunks(law_id)");
        } catch (\PDOException $e) {
            // ignore
        }

        // Global chat (librarian): one thread per user, no law_id
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS user_chats_global (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL UNIQUE,
                messages_json TEXT NOT NULL DEFAULT '[]',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        // Password reset tokens (forgot password flow)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
        $origin = $data['origin'] ?? 'nrsr';
        $externalId = $data['external_id'] ?? null;
        
        if ($existing) {
            $stmt = $this->pdo->prepare("
                UPDATE laws 
                SET title = ?, approval_date = ?, source_url = ?, content_hash = ?, 
                    ai_summary = ?, processing_status = ?, text_extracted = ?, origin = ?, external_id = ?, updated_at = CURRENT_TIMESTAMP
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
                $origin,
                $externalId,
                $data['master_id']
            ]);
            return $existing['id'];
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO laws (master_id, title, approval_date, source_url, content_hash, ai_summary, processing_status, text_extracted, origin, external_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['master_id'],
                $data['title'],
                $data['approval_date'],
                $data['source_url'],
                $data['content_hash'],
                $data['ai_summary'] ?? null,
                $processingStatus,
                $textExtracted,
                $origin,
                $externalId
            ]);
            return (int) $this->pdo->lastInsertId();
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

    public function deleteChunksByLawId(int $lawId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM law_chunks WHERE law_id = ?");
        $stmt->execute([$lawId]);
    }

    public function saveLawChunk(int $lawId, int $chunkIndex, string $content, int $charStart = 0, int $charEnd = 0, ?string $sectionTitle = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO law_chunks (law_id, chunk_index, content, char_start, char_end, section_title)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$lawId, $chunkIndex, $content, $charStart, $charEnd, $sectionTitle]);
    }

    /** @return list<array{id: int, law_id: int, chunk_index: int, content: string, char_start: int, char_end: int, section_title: ?string}> */
    public function getChunksByLawId(int $lawId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM law_chunks WHERE law_id = ? ORDER BY chunk_index");
        $stmt->execute([$lawId]);
        return $stmt->fetchAll();
    }

    /** Search chunks by content (LIKE). For librarian / full-text. */
    public function searchChunksByContent(string $query, ?string $origin = null, int $limit = 50): array
    {
        $sql = "
            SELECT c.*, l.master_id, l.title, l.origin
            FROM law_chunks c
            JOIN laws l ON l.id = c.law_id
            WHERE c.content LIKE ?
        ";
        $params = ['%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%'];
        if ($origin !== null && $origin !== '') {
            $sql .= " AND l.origin = ?";
            $params[] = $origin;
        }
        $sql .= " ORDER BY c.law_id, c.chunk_index LIMIT ?";
        $params[] = $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Librarian: get chunks matching any of the keywords, scored by number of matches.
     * @param string[] $keywords
     * @return list<array{score: int, content: string, master_id: string, title: string, section_title: ?string}>
     */
    public function getChunksMatchingKeywords(array $keywords, ?string $origin = null, int $maxChunks = 150): array
    {
        $byKey = [];
        foreach ($keywords as $kw) {
            $kw = trim($kw);
            if ($kw === '') {
                continue;
            }
            $rows = $this->searchChunksByContent($kw, $origin, 80);
            foreach ($rows as $row) {
                $id = $row['law_id'] . '_' . $row['chunk_index'];
                if (!isset($byKey[$id])) {
                    $byKey[$id] = [
                        'score' => 0,
                        'content' => $row['content'],
                        'master_id' => $row['master_id'],
                        'title' => $row['title'],
                        'section_title' => $row['section_title'] ?? null,
                        'law_id' => (int) $row['law_id'],
                        'chunk_index' => (int) $row['chunk_index'],
                    ];
                }
                $byKey[$id]['score']++;
            }
        }
        $chunks = array_values($byKey);
        usort($chunks, function ($a, $b) {
            return $b['score'] - $a['score'];
        });
        return array_slice($chunks, 0, $maxChunks);
    }

    /**
     * Get chunks from laws whose title contains any of the given terms (LIKE %term%).
     * Used to prioritize e.g. Zákonník práce for labour/vacation questions.
     *
     * @param string[] $titleTerms e.g. ['zákonník práce', 'práce']
     * @return list<array{content: string, master_id: string, title: string, section_title: ?string, law_id: int, chunk_index: int}>
     */
    public function getChunksFromLawsWithTitleMatching(array $titleTerms, ?string $origin = null, int $limit = 80): array
    {
        if (empty($titleTerms)) {
            return [];
        }
        $conditions = [];
        $params = [];
        foreach ($titleTerms as $term) {
            $conditions[] = 'l.title LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
        }
        $sql = "
            SELECT c.id, c.law_id, c.chunk_index, c.content, c.section_title, l.master_id, l.title
            FROM law_chunks c
            JOIN laws l ON l.id = c.law_id
            WHERE (" . implode(' OR ', $conditions) . ")
        ";
        if ($origin !== null && $origin !== '') {
            $sql .= " AND l.origin = ?";
            $params[] = $origin;
        }
        $sql .= " ORDER BY l.id, c.chunk_index LIMIT ?";
        $params[] = $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'content' => $row['content'],
                'master_id' => $row['master_id'],
                'title' => $row['title'],
                'section_title' => $row['section_title'] ?? null,
                'law_id' => (int) $row['law_id'],
                'chunk_index' => (int) $row['chunk_index'],
            ];
        }
        return $out;
    }

    public function getGlobalChat(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM user_chats_global WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            $row['messages'] = json_decode($row['messages_json'], true) ?: [];
            return $row;
        }
        return null;
    }

    public function saveGlobalChat(int $userId, array $messages): void
    {
        $messagesJson = json_encode($messages, JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare("
            INSERT INTO user_chats_global (user_id, messages_json, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(user_id) DO UPDATE SET
                messages_json = excluded.messages_json,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$userId, $messagesJson]);
    }

    public function logProcessing(string $masterId, string $status, ?string $message = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO processing_log (master_id, status, message)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$masterId, $status, $message]);
    }

    /**
     * @param int $limit
     * @param string|null $origin Optional filter: 'nrsr' | 'slovlex_zz' | null for all
     */
    public function getLatestLaws(int $limit = 50, ?string $origin = null): array
    {
        $sql = "
            SELECT * FROM laws
        ";
        $params = [];
        if ($origin !== null && $origin !== '') {
            $sql .= " WHERE origin = ?";
            $params[] = $origin;
        }
        $sql .= "
            ORDER BY
                CASE
                    WHEN approval_date IS NOT NULL AND approval_date != '' THEN
                        substr('0000' || substr(approval_date, -4), -4) || '-' ||
                        substr('00' || substr(approval_date, length(approval_date) - 7, 2), -2) || '-' ||
                        substr('00' || substr(approval_date, 1, 2), -2)
                    ELSE '0000-00-00'
                END DESC,
                created_at DESC
            LIMIT ?
        ";
        $params[] = $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
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
    

    public function saveOrUpdateParliamentMp(array $data): int
    {
        $existing = $this->findParliamentMpByNrsrId((string)$data['nrsr_id']);
        if ($existing) {
            $stmt = $this->pdo->prepare("
                UPDATE parliament_mps
                SET full_name = ?, first_name = ?, last_name = ?, title = ?, party = ?, club = ?,
                    district = ?, birth_date = ?, email = ?, website = ?, photo_url = ?, profile_url = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE nrsr_id = ?
            ");
            $stmt->execute([
                $data['full_name'],
                $data['first_name'] ?? null,
                $data['last_name'] ?? null,
                $data['title'] ?? null,
                $data['party'] ?? null,
                $data['club'] ?? null,
                $data['district'] ?? null,
                $data['birth_date'] ?? null,
                $data['email'] ?? null,
                $data['website'] ?? null,
                $data['photo_url'] ?? null,
                $data['profile_url'] ?? null,
                $data['nrsr_id'],
            ]);
            return (int)$existing['id'];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO parliament_mps (
                nrsr_id, full_name, first_name, last_name, title, party, club, district,
                birth_date, email, website, photo_url, profile_url
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['nrsr_id'],
            $data['full_name'],
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
            $data['title'] ?? null,
            $data['party'] ?? null,
            $data['club'] ?? null,
            $data['district'] ?? null,
            $data['birth_date'] ?? null,
            $data['email'] ?? null,
            $data['website'] ?? null,
            $data['photo_url'] ?? null,
            $data['profile_url'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findParliamentMpByNrsrId(string $nrsrId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM parliament_mps WHERE nrsr_id = ?");
        $stmt->execute([$nrsrId]);
        return $stmt->fetch() ?: null;
    }

    public function getParliamentMpById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM parliament_mps WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function saveOrUpdateParliamentVoting(array $data): int
    {
        $existing = $this->findParliamentVotingByNrsrId((string)$data['nrsr_voting_id']);
        if ($existing) {
            $stmt = $this->pdo->prepare("
                UPDATE parliament_votings
                SET session_number = ?, voting_number = ?, title = ?, voting_date = ?, voting_time = ?,
                    result = ?, result_text = ?, present_count = ?, votes_for = ?, votes_against = ?,
                    votes_abstain = ?, votes_absent = ?, votes_did_not_vote = ?, summary_text = ?, source_url = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE nrsr_voting_id = ?
            ");
            $stmt->execute([
                $data['session_number'] ?? null,
                $data['voting_number'] ?? null,
                $data['title'],
                $data['voting_date'] ?? null,
                $data['voting_time'] ?? null,
                $data['result'] ?? null,
                $data['result_text'] ?? null,
                $data['present_count'] ?? null,
                $data['votes_for'] ?? null,
                $data['votes_against'] ?? null,
                $data['votes_abstain'] ?? null,
                $data['votes_absent'] ?? null,
                $data['votes_did_not_vote'] ?? null,
                $data['summary_text'] ?? null,
                $data['source_url'] ?? null,
                $data['nrsr_voting_id'],
            ]);
            return (int)$existing['id'];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO parliament_votings (
                nrsr_voting_id, session_number, voting_number, title, voting_date, voting_time,
                result, result_text, present_count, votes_for, votes_against, votes_abstain,
                votes_absent, votes_did_not_vote, summary_text, source_url
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['nrsr_voting_id'],
            $data['session_number'] ?? null,
            $data['voting_number'] ?? null,
            $data['title'],
            $data['voting_date'] ?? null,
            $data['voting_time'] ?? null,
            $data['result'] ?? null,
            $data['result_text'] ?? null,
            $data['present_count'] ?? null,
            $data['votes_for'] ?? null,
            $data['votes_against'] ?? null,
            $data['votes_abstain'] ?? null,
            $data['votes_absent'] ?? null,
            $data['votes_did_not_vote'] ?? null,
            $data['summary_text'] ?? null,
            $data['source_url'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findParliamentVotingByNrsrId(string $nrsrVotingId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM parliament_votings WHERE nrsr_voting_id = ?");
        $stmt->execute([$nrsrVotingId]);
        return $stmt->fetch() ?: null;
    }

    public function getParliamentVotingById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM parliament_votings WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function replaceVotesForVoting(int $votingId, array $votes): void
    {
        $deleteStmt = $this->pdo->prepare("DELETE FROM parliament_votes WHERE voting_id = ?");
        $deleteStmt->execute([$votingId]);

        $insertStmt = $this->pdo->prepare("
            INSERT INTO parliament_votes (voting_id, mp_id, vote_code, vote_label, club)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($votes as $vote) {
            $mp = $this->findParliamentMpByNrsrId((string)$vote['mp_nrsr_id']);
            if (!$mp) {
                $mpId = $this->saveOrUpdateParliamentMp([
                    'nrsr_id' => (string)$vote['mp_nrsr_id'],
                    'full_name' => $vote['full_name'] ?? 'Neznamy poslanec',
                    'profile_url' => $vote['profile_url'] ?? null,
                ]);
            } else {
                $mpId = (int)$mp['id'];
            }

            $insertStmt->execute([
                $votingId,
                $mpId,
                $vote['vote_code'] ?? null,
                $vote['vote_label'] ?? null,
                $vote['club'] ?? null,
            ]);
        }
    }

    public function getLatestParliamentVotings(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM parliament_votings
            ORDER BY voting_date DESC, voting_time DESC, id DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    public function countParliamentVotings(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) AS c FROM parliament_votings");
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    }

    public function getParliamentVotingsPage(int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM parliament_votings
            ORDER BY voting_date DESC, voting_time DESC, id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }

    public function getParliamentVotesForVoting(int $votingId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT pv.*, pm.full_name, pm.nrsr_id AS mp_nrsr_id
            FROM parliament_votes pv
            JOIN parliament_mps pm ON pm.id = pv.mp_id
            WHERE pv.voting_id = ?
            ORDER BY pm.full_name ASC
        ");
        $stmt->execute([$votingId]);
        return $stmt->fetchAll();
    }

    public function getRecentVotesForMp(int $mpId, int $limit = 25): array
    {
        $stmt = $this->pdo->prepare("
            SELECT pv.vote_code, pv.vote_label, pv.club,
                   v.id AS voting_id, v.nrsr_voting_id, v.title, v.voting_date, v.voting_time, v.result, v.result_text
            FROM parliament_votes pv
            JOIN parliament_votings v ON v.id = pv.voting_id
            WHERE pv.mp_id = ?
            ORDER BY v.voting_date DESC, v.voting_time DESC, v.id DESC
            LIMIT ?
        ");
        $stmt->execute([$mpId, $limit]);
        return $stmt->fetchAll();
    }

    public function refreshParliamentMpStatsAndCard(int $mpId, string $cardText): void
    {
        $stats = $this->computeParliamentMpStats($mpId);
        $stmt = $this->pdo->prepare("
            UPDATE parliament_mps
            SET card_text = ?, stats_json = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$cardText, json_encode($stats, JSON_UNESCAPED_UNICODE), $mpId]);
    }

    public function getTopParliamentMps(int $limit = 300): array
    {
        $stmt = $this->pdo->prepare("
            SELECT pm.*,
                   COALESCE(json_extract(pm.stats_json, '$.attendance_pct'), 0) AS attendance_pct,
                   COALESCE(json_extract(pm.stats_json, '$.total_votes'), 0) AS total_votes,
                   COALESCE(json_extract(pm.stats_json, '$.votes_for'), 0) AS votes_for,
                   COALESCE(json_extract(pm.stats_json, '$.votes_against'), 0) AS votes_against,
                   COALESCE(json_extract(pm.stats_json, '$.votes_abstain'), 0) AS votes_abstain
            FROM parliament_mps pm
            ORDER BY attendance_pct DESC, total_votes DESC, pm.full_name ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    public function buildMonthlyVotingStats(int $year, int $month): array
    {
        $prefix = sprintf('%04d-%02d', $year, $month);
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) AS total_votings,
                   SUM(CASE WHEN result = 'schválené' THEN 1 ELSE 0 END) AS approved,
                   SUM(CASE WHEN result = 'neschválené' THEN 1 ELSE 0 END) AS rejected,
                   AVG(CASE WHEN present_count IS NOT NULL THEN present_count END) AS avg_present
            FROM parliament_votings
            WHERE voting_date LIKE ?
        ");
        $stmt->execute([$prefix . '%']);
        $summary = $stmt->fetch() ?: [];

        $topStmt = $this->pdo->prepare("
            SELECT * FROM parliament_votings
            WHERE voting_date LIKE ?
            ORDER BY COALESCE(votes_for, 0) + COALESCE(votes_against, 0) DESC, voting_date DESC
            LIMIT 10
        ");
        $topStmt->execute([$prefix . '%']);

        return [
            'year' => $year,
            'month' => $month,
            'total_votings' => (int)($summary['total_votings'] ?? 0),
            'approved' => (int)($summary['approved'] ?? 0),
            'rejected' => (int)($summary['rejected'] ?? 0),
            'avg_present' => round((float)($summary['avg_present'] ?? 0), 1),
            'top_votings' => $topStmt->fetchAll(),
        ];
    }

    public function saveMonthlyParliamentReport(int $year, int $month, string $summaryText, array $stats): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO parliament_monthly_reports (report_year, report_month, summary_text, stats_json, updated_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(report_year, report_month)
            DO UPDATE SET summary_text = excluded.summary_text, stats_json = excluded.stats_json, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$year, $month, $summaryText, json_encode($stats, JSON_UNESCAPED_UNICODE)]);
    }

    public function getMonthlyParliamentReport(int $year, int $month): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM parliament_monthly_reports
            WHERE report_year = ? AND report_month = ?
        ");
        $stmt->execute([$year, $month]);
        return $stmt->fetch() ?: null;
    }

    public function getAvailableParliamentMonths(int $limit = 12): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT substr(voting_date, 1, 7) AS ym
            FROM parliament_votings
            WHERE voting_date IS NOT NULL
            ORDER BY ym DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    private function computeParliamentMpStats(int $mpId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) AS total_votes,
                   SUM(CASE WHEN vote_code = 'Z' THEN 1 ELSE 0 END) AS votes_for,
                   SUM(CASE WHEN vote_code = 'P' THEN 1 ELSE 0 END) AS votes_against,
                   SUM(CASE WHEN vote_code = '?' THEN 1 ELSE 0 END) AS votes_abstain,
                   SUM(CASE WHEN vote_code = '0' THEN 1 ELSE 0 END) AS votes_absent,
                   SUM(CASE WHEN vote_code = 'N' THEN 1 ELSE 0 END) AS votes_did_not_vote
            FROM parliament_votes
            WHERE mp_id = ?
        ");
        $stmt->execute([$mpId]);
        $row = $stmt->fetch() ?: [];
        $total = (int)($row['total_votes'] ?? 0);

        $activeVotes = (int)($row['votes_for'] ?? 0) + (int)($row['votes_against'] ?? 0) + (int)($row['votes_abstain'] ?? 0);
        $attendancePct = $total > 0 ? ($activeVotes / $total) * 100 : 0.0;

        return [
            'total_votes' => $total,
            'votes_for' => (int)($row['votes_for'] ?? 0),
            'votes_against' => (int)($row['votes_against'] ?? 0),
            'votes_abstain' => (int)($row['votes_abstain'] ?? 0),
            'votes_absent' => (int)($row['votes_absent'] ?? 0),
            'votes_did_not_vote' => (int)($row['votes_did_not_vote'] ?? 0),
            'attendance_pct' => round($attendancePct, 1),
        ];
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

    /** Chat keyed by master_id (for laws loaded from JSON only). */
    public function getChatByMasterId(int $userId, string $masterId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM user_chats_master WHERE user_id = ? AND master_id = ?");
        $stmt->execute([$userId, $masterId]);
        $chat = $stmt->fetch();
        if ($chat) {
            $chat['messages'] = json_decode($chat['messages_json'], true) ?: [];
            return $chat;
        }
        return null;
    }

    public function saveChatByMasterId(int $userId, string $masterId, array $messages): void
    {
        $messagesJson = json_encode($messages, JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare("
            INSERT INTO user_chats_master (user_id, master_id, messages_json, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(user_id, master_id) DO UPDATE SET
                messages_json = ?,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$userId, $masterId, $messagesJson, $messagesJson]);
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

    // Password reset (forgot password flow)
    public function createPasswordResetToken(int $userId, string $token, string $expiresAt): void
    {
        $this->pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$userId]);
        $stmt = $this->pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $token, $expiresAt]);
    }

    public function findPasswordResetToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM password_reset_tokens WHERE token = ? AND expires_at > datetime('now')");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    public function deletePasswordResetToken(string $token): void
    {
        $this->pdo->prepare("DELETE FROM password_reset_tokens WHERE token = ?")->execute([$token]);
    }

    public function updateUserPassword(int $userId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$passwordHash, $userId]);
    }
}

