<?php
// 🌌 Private Vault (Password Manager)
// Author: TheKom™
// License: MIT
// api/get_vault.php
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

if (empty($input['verification_hash'])) {
    sendJsonResponse(['error' => 'Missing verification_hash.'], 400);
}

$verificationHash = trim($input['verification_hash']);
$data = loadVaultData();

if (!$data) {
    sendJsonResponse(['error' => 'Failed to load vault storage file.'], 500);
}

// Verify authorization
if (!password_verify($verificationHash, $data['master_password_hash'])) {
    registerFailedAttempt('login');
    sendJsonResponse(['error' => 'Unauthorized. Invalid master password.'], 401);
}

// Clear rate limits on successful validation
resetRateLimit('login');

$recovered = checkRecoveryTrigger();

// Return the single-blob encrypted vault.
sendJsonResponse([
    'status' => 'success',
    'encrypted_vault' => isset($data['encrypted_vault']) ? $data['encrypted_vault'] : '',
    'recovered_from_backup' => $recovered
]);

// TheKom™ // was here.
