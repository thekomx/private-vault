<?php
// 🌌 Private Vault (Password Manager)
// Author: TheKom™
// License: MIT
// api/helper.php

// Prevent direct access to helper.php
if (basename($_SERVER['PHP_SELF']) === 'helper.php') {
    http_response_code(403);
    exit('Direct access forbidden.');
}

// Security & CORS Headers
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$serverHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$allowedOrigin = '';

if (!empty($origin)) {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $serverHostName = explode(':', $serverHost)[0];
    
    if ($originHost === $serverHostName || $originHost === 'localhost' || $originHost === '127.0.0.1') {
        $allowedOrigin = $origin;
    }
}

if (!empty($allowedOrigin)) {
    header("Access-Control-Allow-Origin: " . $allowedOrigin);
}
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// HTTP Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("X-XSS-Protection: 1; mode=block");

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}


// PHP execution blocker string prefixed to the storage file
define('PHP_BLOCKER', "<?php http_response_code(403); exit('Access denied'); ?>\n");
define('VAULT_FILE_NAME', 'vault.data.php');

/**
 * Returns the absolute path to vault.data.php
 */
function getVaultFilePath() {
    return __DIR__ . '/' . VAULT_FILE_NAME;
}

define('RECOVERY_TRIGGER_FILE', 'recovery_triggered.txt');

/**
 * Returns the absolute path to recovery_triggered.txt
 */
function getRecoveryTriggerPath() {
    return __DIR__ . '/' . RECOVERY_TRIGGER_FILE;
}

/**
 * Writes the temporary recovery marker file.
 */
function writeRecoveryTrigger() {
    @file_put_contents(getRecoveryTriggerPath(), '1');
}

/**
 * Checks if recovery was triggered, deletes the marker, and returns a boolean.
 */
function checkRecoveryTrigger() {
    $path = getRecoveryTriggerPath();
    if (file_exists($path)) {
        @unlink($path);
        return true;
    }
    return false;
}

/**
 * Checks if the vault is initialized.
 */
function isVaultInitialized() {
    $path = getVaultFilePath();
    $bakPath = $path . '.bak';
    
    // If main is missing but backup exists, consider it initialized (it will self-heal on load)
    if (!file_exists($path) || filesize($path) === 0) {
        if (file_exists($bakPath) && filesize($bakPath) > 0) {
            return true;
        }
        return false;
    }
      
    $data = loadVaultData();
    return $data !== null && !empty($data['master_password_hash']);
}

/**
 * Loads and decrypts the vault metadata structure.
 * Strips the PHP execution blocker and returns the parsed JSON array.
 * Uses shared locking (LOCK_SH) to prevent reading during writes.
 * If database is corrupted/missing, automatically restores from the backup file if available.
 */
function loadVaultData() {
    $path = getVaultFilePath();
    $bakPath = $path . '.bak';

    // Check if main file is missing or empty, and restore from backup if it exists
    if (!file_exists($path) || filesize($path) === 0) {
        if (file_exists($bakPath) && filesize($bakPath) > 0) {
            if (copy($bakPath, $path)) {
                writeRecoveryTrigger();
            }
        } else {
            // No main file and no backup: first-time setup
            return null;
        }
    }

    $fp = fopen($path, 'r');
    if (!$fp) {
        sendJsonResponse(['error' => 'Failed to open vault storage file.'], 500);
    }

    // Acquire shared lock
    flock($fp, LOCK_SH);
    
    $content = '';
    while (!feof($fp)) {
        $content .= fread($fp, 8192);
    }
    
    // Release lock and close
    flock($fp, LOCK_UN);
    fclose($fp);

    // Strip the PHP blocker header
    if (strpos($content, PHP_BLOCKER) === 0) {
        $jsonContent = substr($content, strlen(PHP_BLOCKER));
    } else {
        // Fallback check if the header has minor differences
        $pos = strpos($content, '?>');
        if ($pos !== false) {
            $jsonContent = substr($content, $pos + 2);
        } else {
            $jsonContent = $content;
        }
    }

    $decoded = json_decode(trim($jsonContent), true);
    
    // Check if JSON structure is valid and contains master password hash
    if (!is_array($decoded) || empty($decoded['master_password_hash'])) {
        // Main file is structurally corrupt. Attempt backup restoration.
        if (file_exists($bakPath) && filesize($bakPath) > 0) {
            if (copy($bakPath, $path)) {
                writeRecoveryTrigger();
            }
            
            // Re-read restored backup file contents
            $bakContent = @file_get_contents($bakPath);
            if ($bakContent !== false) {
                if (strpos($bakContent, PHP_BLOCKER) === 0) {
                    $bakJsonContent = substr($bakContent, strlen(PHP_BLOCKER));
                } else {
                    $pos = strpos($bakContent, '?>');
                    $bakJsonContent = ($pos !== false) ? substr($bakContent, $pos + 2) : $bakContent;
                }
                $decodedBak = json_decode(trim($bakJsonContent), true);
                if (is_array($decodedBak) && !empty($decodedBak['master_password_hash'])) {
                    return $decodedBak;
                }
            }
        }
        return null;
    }

    return $decoded;
}

/**
 * Writes the vault structure (master hash, vault salt, and encrypted vault) to vault.data.php.
 * Prefixes the PHP execution blocker to prevent direct browser downloads.
 * Uses exclusive locking (LOCK_EX) to prevent race conditions during writes.
 * Also creates a backup copy (.bak) of the database prior to writing.
 */
function saveVaultData($data) {
    $path = getVaultFilePath();
    
    // Create backup copy of existing file before writing to prevent data corruption/loss
    if (file_exists($path) && filesize($path) > 0) {
        copy($path, $path . '.bak');
    }
    
    // Open for writing (creates if not exists)
    $fp = fopen($path, 'c');
    if (!$fp) {
        sendJsonResponse(['error' => 'Failed to open vault storage file for writing.'], 500);
    }

    // Acquire exclusive write lock
    flock($fp, LOCK_EX);
    
    // Truncate file
    ftruncate($fp, 0);
    rewind($fp);

    // Prepend the execution blocker and write JSON payload
    $payload = PHP_BLOCKER . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $written = fwrite($fp, $payload);
    
    fflush($fp);
    
    // Release lock and close
    flock($fp, LOCK_UN);
    fclose($fp);

    return $written !== false;
}

/**
 * Sends a standard JSON response and terminates execution.
 */
function sendJsonResponse($data, $statusCode = 200) {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
    }
    echo json_encode($data);
    exit;
}

/**
 * Validates the format and size of the encrypted vault.
 * Vault is expected to be string formatted as "iv:ciphertext" in hex.
 */
function isValidEncryptedVault($vaultStr) {
    if (!is_string($vaultStr)) {
        return false;
    }
    
    $len = strlen($vaultStr);
    // Enforce size limits (min 20 chars, max 5MB)
    if ($len < 20 || $len > 5000000) {
        return false;
    }
    
    $parts = explode(':', $vaultStr);
    if (count($parts) !== 2) {
        return false;
    }
    
    return ctype_xdigit($parts[0]) && ctype_xdigit($parts[1]);
}

/**
 * Rate limiting file-based storage functions
 */
define('RATE_LIMIT_FILE_NAME', 'rate_limit.data.php');

function getRateLimitFilePath() {
    return __DIR__ . '/' . RATE_LIMIT_FILE_NAME;
}

function loadRateLimitData() {
    $path = getRateLimitFilePath();
    if (!file_exists($path)) {
        return ['attempts' => []];
    }

    $fp = fopen($path, 'r');
    if (!$fp) {
        return ['attempts' => []];
    }

    flock($fp, LOCK_SH);
    $content = '';
    while (!feof($fp)) {
        $content .= fread($fp, 8192);
    }
    flock($fp, LOCK_UN);
    fclose($fp);

    if (strpos($content, PHP_BLOCKER) === 0) {
        $jsonContent = substr($content, strlen(PHP_BLOCKER));
    } else {
        $pos = strpos($content, '?>');
        $jsonContent = ($pos !== false) ? substr($content, $pos + 2) : $content;
    }

    $decoded = json_decode(trim($jsonContent), true);
    return is_array($decoded) ? $decoded : ['attempts' => []];
}

function saveRateLimitData($data) {
    $path = getRateLimitFilePath();
    $fp = fopen($path, 'c');
    if (!$fp) {
        return false;
    }

    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    $payload = PHP_BLOCKER . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $written = fwrite($fp, $payload);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $written !== false;
}

/**
 * Verifies that the client IP has not exceeded failed attempt limits.
 */
function checkRateLimit($action, $limit = 5, $timeframe = 600) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    if ($ip === 'unknown') {
        return;
    }

    $data = loadRateLimitData();
    $key = $action . '_' . $ip;

    if (isset($data['attempts'][$key])) {
        $attempt = $data['attempts'][$key];
        $timePassed = time() - $attempt['last_time'];

        if ($attempt['count'] >= $limit) {
            if ($timePassed < $timeframe) {
                $timeLeft = $timeframe - $timePassed;
                sendJsonResponse([
                    'error' => 'Too many failed attempts. Please try again in ' . ceil($timeLeft / 60) . ' minutes.'
                ], 429);
            } else {
                // Timeframe expired, clear this key
                unset($data['attempts'][$key]);
                saveRateLimitData($data);
            }
        }
    }
}

/**
 * Registers a failed login or save attempt for the client IP.
 */
function registerFailedAttempt($action) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    if ($ip === 'unknown') {
        return;
    }

    $data = loadRateLimitData();
    $key = $action . '_' . $ip;
    $now = time();

    // Clean up expired records to keep the rate limit file small
    $cleanedAttempts = [];
    foreach ($data['attempts'] as $k => $att) {
        if ($now - $att['last_time'] < 86400) { // Keep data for max 24 hours
            $cleanedAttempts[$k] = $att;
        }
    }
    $data['attempts'] = $cleanedAttempts;

    if (isset($data['attempts'][$key])) {
        $data['attempts'][$key]['count']++;
        $data['attempts'][$key]['last_time'] = $now;
    } else {
        $data['attempts'][$key] = [
            'count' => 1,
            'last_time' => $now
        ];
    }

    saveRateLimitData($data);
}

/**
 * Resets the rate limit counter for the client IP upon success.
 */
function resetRateLimit($action) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    if ($ip === 'unknown') {
        return;
    }

    $data = loadRateLimitData();
    $key = $action . '_' . $ip;

    if (isset($data['attempts'][$key])) {
        unset($data['attempts'][$key]);
        saveRateLimitData($data);
    }
}

// TheKom™ // was here.
