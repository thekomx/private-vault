<?php
// 🌌 Private Vault (Password Manager)
// Author: TheKom™
// License: MIT
// api/change_password.php
require_once 'helper.php';

// Check if application is in Setup Mode
if (!isVaultInitialized()) {
    sendJsonResponse(['error' => 'Application is in Setup Mode. Configure master password first.'], 400);
}

// Enforce login rate limit
checkRateLimit('login');

// Get the raw JSON POST input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    sendJsonResponse(['error' => 'Invalid JSON input.'], 400);
}

$requiredFields = ['old_verification_hash', 'new_verification_hash', 'encrypted_vault'];
foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        sendJsonResponse(['error' => "Missing required field: {$field}"], 400);
    }
}

$oldVerificationHash = trim($input['old_verification_hash']);
$newVerificationHash = trim($input['new_verification_hash']);
$encryptedVault = trim($input['encrypted_vault']);

// Validate size & format
if (!isValidEncryptedVault($encryptedVault)) {
    sendJsonResponse(['error' => 'Invalid encrypted vault payload format or size limit exceeded.'], 400);
}

$data = loadVaultData();
if (!$data) {
    sendJsonResponse(['error' => 'Failed to load vault storage file.'], 500);
}

// Verify the old master password first
if (!password_verify($oldVerificationHash, $data['master_password_hash'])) {
    registerFailedAttempt('login');
    sendJsonResponse(['error' => 'Unauthorized. Invalid old master password.'], 401);
}

// Reset rate limits on successful validation
resetRateLimit('login');

// Hash the new verification hash using native Argon2id
$newArgonHash = password_hash($newVerificationHash, PASSWORD_ARGON2ID);

// Update both the password hash and the vault blob atomically
$data['master_password_hash'] = $newArgonHash;
$data['encrypted_vault'] = $encryptedVault;
$data['vault_empty'] = false; // Obviously not empty after re-encryption

$recovered = checkRecoveryTrigger();

if (saveVaultData($data)) {
    sendJsonResponse([
        'status' => 'success',
        'message' => 'Master password and vault re-encryption completed successfully!',
        'recovered_from_backup' => $recovered
    ]);
} else {
    sendJsonResponse(['error' => 'Failed to save updated vault configurations.'], 500);
}

// TheKom™ // was here.
