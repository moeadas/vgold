<?php
/**
 * Victory Genomics CRM - Database Configuration
 * 
 * SiteGround Compatible: Uses .env file for credentials.
 * Create config/.env with your SiteGround database credentials.
 * NEVER commit .env to version control.
 */

// Load environment from config/.env (SiteGround compatible)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Database Configuration — reads from .env, falls back to defaults
define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_NAME',    getenv('DB_NAME')    ?: 'your_database_name');
define('DB_USER',    getenv('DB_USER')    ?: 'your_database_user');
define('DB_PASS',    getenv('DB_PASS')    ?: 'your_database_password');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// Application Configuration
define('APP_NAME',    getenv('APP_NAME')    ?: 'Victory Genomics CRM');
define('APP_URL',     getenv('APP_URL')     ?: 'https://crm.victorygenomics.com');
define('APP_VERSION', '2.0.0');

// Security Configuration
define('SESSION_NAME', 'VG_CRM_SESSION');
define('SESSION_LIFETIME', 3600 * 8); // 8 hours
define('PASSWORD_MIN_LENGTH', 8);

// File Upload Configuration
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 10485760); // 10MB
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png']);

// Microsoft OAuth2 Configuration (Azure AD App Registration)
define('MS_CLIENT_ID',     getenv('MS_CLIENT_ID')     ?: '');
define('MS_CLIENT_SECRET', getenv('MS_CLIENT_SECRET') ?: '');
define('MS_TENANT_ID',     getenv('MS_TENANT_ID')     ?: '');
define('MS_REDIRECT_URI',  APP_URL . '/api/microsoft-callback.php');

// Pagination
define('RECORDS_PER_PAGE', 25);

// Timezone
date_default_timezone_set('UTC');

// Error Reporting — safe for SiteGround shared hosting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

/**
 * Send security headers — call early on every page
 */
function sendSecurityHeaders() {
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Permissions-Policy: camera=(), microphone=(self), geolocation=()");
        // CSP: allow self + CDN fonts + Chart.js + Twilio (microphone needed for VoIP)
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://media.twiliocdn.com https://*.twilio.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob:; connect-src 'self' https://*.twilio.com wss://*.twilio.com https://eventgw.twilio.com https://media.twiliocdn.com https://chunderw-vpc-gll.twilio.com https://chunderw-vpc-gll-au1.twilio.com https://chunderw-vpc-gll-br1.twilio.com https://chunderw-vpc-gll-ie1.twilio.com https://chunderw-vpc-gll-jp1.twilio.com https://chunderw-vpc-gll-sg1.twilio.com https://chunderw-vpc-gll-us1.twilio.com https://chunderw-vpc-gll-us2.twilio.com; media-src 'self' blob:;");
    }
}

/**
 * Database Connection Class (Singleton, PDO, SiteGround MySQL compatible)
 */
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Failed: " . $e->getMessage());
            die("Database connection error. Please try again later.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Execute a query with optional parameters
     */
    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function prepare($sql) {
        return $this->connection->prepare($sql);
    }
    
    /**
     * Insert — supports both SQL string and table/array syntax
     */
    public function insert($sqlOrTable, $params = []) {
        if (is_array($params) && !empty($params) && strpos($sqlOrTable, ' ') === false) {
            $columns = implode(', ', array_keys($params));
            $placeholders = implode(', ', array_fill(0, count($params), '?'));
            $sql = "INSERT INTO `$sqlOrTable` ($columns) VALUES ($placeholders)";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute(array_values($params));
        } else {
            $stmt = $this->connection->prepare($sqlOrTable);
            $stmt->execute($params);
        }
        return $this->connection->lastInsertId();
    }
    
    /**
     * Update — supports both SQL string and table/array syntax
     */
    public function update($sqlOrTable, $dataOrParams = [], $conditions = []) {
        if (strpos($sqlOrTable, ' ') === false) {
            $setParts = [];
            $params = [];
            foreach ($dataOrParams as $key => $value) {
                $setParts[] = "`$key` = ?";
                $params[] = $value;
            }
            $whereParts = [];
            foreach ($conditions as $key => $value) {
                $whereParts[] = "`$key` = ?";
                $params[] = $value;
            }
            $sql = "UPDATE `$sqlOrTable` SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } else {
            $stmt = $this->connection->prepare($sqlOrTable);
            $stmt->execute($dataOrParams);
            return $stmt->rowCount();
        }
    }
    
    /**
     * Delete — table/conditions syntax
     */
    public function delete($table, $conditions) {
        $whereParts = [];
        $params = [];
        foreach ($conditions as $key => $value) {
            $whereParts[] = "`$key` = ?";
            $params[] = $value;
        }
        $sql = "DELETE FROM `$table` WHERE " . implode(' AND ', $whereParts);
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
    
    /**
     * Execute an UPDATE/DELETE and return affected rows
     */
    public function execute($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
    
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollback() {
        return $this->connection->rollBack();
    }
    
    /**
     * Find a single record by conditions
     */
    public function findOne($table, $conditions) {
        $where = [];
        $params = [];
        foreach ($conditions as $key => $value) {
            $where[] = "`$key` = ?";
            $params[] = $value;
        }
        $whereClause = implode(' AND ', $where);
        $sql = "SELECT * FROM `$table` WHERE $whereClause LIMIT 1";
        return $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Parameterized IN clause helper.
     * Returns ['placeholders' => '?,?,?', 'params' => [...]]
     */
    public static function buildInClause(array $values) {
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        return ['placeholders' => $placeholders, 'params' => array_values($values)];
    }
    
    private function __clone() {}
    
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
?>
