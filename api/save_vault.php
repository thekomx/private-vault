<?php
// 🌌 Private Vault (Password Manager)
// Author: TheKom™
// License: MIT
// api/save_vault.php
require_once 'helper.php';

// Get the raw JSON POST input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    sendJsonResponse(['error' => 'Invalid JSON input.'], 400);
}

// 1. Setup Mode Handling
if (!isVaultInitialized()) {
    $requiredFields = ['setup_hash', 'vault_salt', 'encrypted_vault'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            sendJsonResponse(['error' => "Setup field is missing: {$field}"], 400);
        }
    }
    
    $setupHash = $input['setup_hash'];
    $vaultSalt = trim($input['vault_salt']);
    $encryptedVault = trim($input['encrypted_vault']);
    
    // Validate payload size & structure
    if (!isValidEncryptedVault($encryptedVault)) {
        sendJsonResponse(['error' => 'Invalid encrypted vault payload format or size limit exceeded.'], 400);
    }
    
    // Hash the client-side setup hash using native Argon2id
    $argonHash = password_hash($setupHash, PASSWORD_ARGON2ID);
    
    $data = [
        'master_password_hash' => $argonHash,
        'vault_salt' => $vaultSalt,
        'vault_empty' => true,
        'encrypted_vault' => $encryptedVault
    ];
    
    if (saveVaultData($data)) {
        sendJsonResponse([
            'status' => 'success',
            'message' => 'Vault initialized successfully!'
        ]);
    } else {
        sendJsonResponse(['error' => 'Failed to initialize vault storage file. Please check folder write permissions.'], 500);
    }
}

// 2. Normal Mode: Save/Overwrite Encrypted Vault
checkRateLimit('login');

$requiredFields = ['verification_hash', 'encrypted_vault'];
foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        sendJsonResponse(['error' => "Missing required field: {$field}"], 400);
    }
}

$verificationHash = trim($input['verification_hash']);
$encryptedVault = trim($input['encrypted_vault']);
$vaultEmpty = isset($input['vault_empty']) ? (bool)$input['vault_empty'] : false;

// Validate size & format
if (!isValidEncryptedVault($encryptedVault)) {
    sendJsonResponse(['error' => 'Invalid encrypted vault payload format or size limit exceeded.'], 400);
}

$data = loadVaultData();
if (!$data) {
    sendJsonResponse(['error' => 'Failed to load vault storage file.'], 500);
}

// Verify authorization
if (!password_verify($verificationHash, $data['master_password_hash'])) {
    registerFailedAttempt('login');
    sendJsonResponse(['error' => 'Unauthorized. Invalid master password.'], 401);
}

// Clear rate limits on successful save
resetRateLimit('login');

// Overwrite the encrypted vault blob and vault_empty flag
$data['encrypted_vault'] = $encryptedVault;
$data['vault_empty'] = $vaultEmpty;

$recovered = checkRecoveryTrigger();

if (saveVaultData($data)) {
    sendJsonResponse([
        'status' => 'success',
        'message' => 'Vault updated successfully.',
        'recovered_from_backup' => $recovered
    ]);
} else {
    sendJsonResponse(['error' => 'Failed to save vault contents.'], 500);
}

// TheKom™ // was here.

