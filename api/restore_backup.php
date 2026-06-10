<?php
// 🌌 Private Vault (Password Manager)
// Author: TheKom™
// License: MIT
// api/restore_backup.php
require_once 'helper.php';

// Enforce rate limiting
checkRateLimit('login');

// Get the raw JSON POST input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    sendJsonResponse(['error' => 'Invalid JSON input.'], 400);
}

if (empty($input['verification_hash'])) {
    sendJsonResponse(['error' => 'Missing verification_hash.'], 400);
}

$verificationHash = trim($input['verification_hash']);

// Load data specifically from the backup file
$bakPath = getVaultFilePath() . '.bak';
if (!file_exists($bakPath)) {
    sendJsonResponse(['error' => 'No backup file found to restore from.'], 404);
}

$fp = fopen($bakPath, 'r');
if (!$fp) {
    sendJsonResponse(['error' => 'Failed to open backup file.'], 500);
}

// Read backup file with shared lock
flock($fp, LOCK_SH);
$content = '';
while (!feof($fp)) {
    $content .= fread($fp, 8192);
}
flock($fp, LOCK_UN);
fclose($fp);

// Strip PHP blocker
if (strpos($content, PHP_BLOCKER) === 0) {
    $jsonContent = substr($content, strlen(PHP_BLOCKER));
} else {
    $pos = strpos($content, '?>');
    $jsonContent = ($pos !== false) ? substr($content, $pos + 2) : $content;
}

$bakData = json_decode(trim($jsonContent), true);

if (!is_array($bakData) || empty($bakData['master_password_hash'])) {
    sendJsonResponse(['error' => 'Backup file is structurally corrupt.'], 500);
}

// Verify authorization against backup credentials
if (!password_verify($verificationHash, $bakData['master_password_hash'])) {
    registerFailedAttempt('login');
    sendJsonResponse(['error' => 'Unauthorized. Invalid master password.'], 401);
}

// Clear rate limits on successful validation
resetRateLimit('login');

// Overwrite the main vault database file with the backup file
if (copy($bakPath, getVaultFilePath())) {
    writeRecoveryTrigger();
    sendJsonResponse([
        'status' => 'success',
        'message' => 'Vault restored successfully from backup.'
    ]);
} else {
    sendJsonResponse(['error' => 'Failed to restore vault file. Check server folder write permissions.'], 500);
}

// TheKom™ // was here.
