<?php
class DB {
    private static ?PDO $instance = null;

    public static function get(): PDO {
        if (self::$instance === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    // Fusul orar al conexiunii = fusul orar PHP (Europe/Bucharest), altfel NOW() din MySQL
                    // și date() din PHP diferă cu 2-3 ore ("acum 3 ore" la un comentariu abia postat)
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '" . date('P') . "'"
                ]);
            } catch (PDOException $e) {
                error_log('[veedo] DB connect: ' . $e->getMessage());
                http_response_code(503);
                if (APP_ENV === 'development') {
                    die("DB Error: " . htmlspecialchars($e->getMessage()));
                }
                die("Eroare de conexiune la baza de date. Contactați administratorul.");
            }
        }
        return self::$instance;
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array {
        return self::query($sql, $params)->fetch() ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    /** Lista albă de tabele permise - previne SQL injection pe table name */
    private static array $ALLOWED_TABLES = [
        // Core
        'users','ads','ad_photos','ad_views','ad_fields','ad_comments','comment_likes',
        'categories','category_fields',
        // Social
        'likes','favorites','follows','reviews','reports','notifications',
        // Messaging
        'conversations','messages',
        // Commerce
        'transactions','orders','boosts',
        // Content
        'stories','story_views','pages','settings',
        // Live
        'live_streams','live_products','live_sessions','live_stream_products',
        // Afiliere
        'affiliates','affiliate_clicks','affiliate_payouts','affiliate_withdrawals','referrals',
        // Misc
        'search_alerts','admin_log','rate_limits'
    ];

    private static function validateTable(string $table): void {
        if (!in_array($table, self::$ALLOWED_TABLES, true)) {
            throw new \InvalidArgumentException("Table not allowed: " . $table);
        }
    }

    private static function validateColumn(string $col): string {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $col)) {
            throw new \InvalidArgumentException("Invalid column name: " . $col);
        }
        return $col;
    }

    public static function insert(string $table, array $data): int {
        self::validateTable($table);
        $cols = implode(',', array_map([self::class,'validateColumn'], array_keys($data)));
        $placeholders = implode(',', array_fill(0, count($data), '?'));
        self::query("INSERT INTO $table ($cols) VALUES ($placeholders)", array_values($data));
        return (int)self::get()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        self::validateTable($table);
        $set = implode(',', array_map(fn($k) => self::validateColumn($k)."=?", array_keys($data)));
        $stmt = self::query("UPDATE $table SET $set WHERE $where", [...array_values($data), ...$whereParams]);
        return $stmt->rowCount();
    }

    public static function lastId(): int {
        return (int)self::get()->lastInsertId();
    }
}
