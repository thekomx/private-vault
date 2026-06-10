<?php
// 🌌 Private Vault (Password Manager)
// Author: TheKom™
// License: MIT
// api/status.php
require_once 'helper.php';

if (!isVaultInitialized()) {
    sendJsonResponse([
        'status' => 'setup'
    ]);
} else {
    $data = loadVaultData();
    $recovered = checkRecoveryTrigger();
    sendJsonResponse([
        'status' => 'configured',
        'vault_salt' => isset($data['vault_salt']) ? $data['vault_salt'] : '',
        'vault_empty' => isset($data['vault_empty']) ? (bool)$data['vault_empty'] : false,
        'recovered_from_backup' => $recovered
    ]);
}

// TheKom™ // was here.

