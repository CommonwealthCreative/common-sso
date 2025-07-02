<?php

function common_sso_get_encryption_key() {
    // Use a 32-byte (256-bit) key derived from WordPress's AUTH_KEY
    return hash('sha256', AUTH_KEY, true);
}

function common_sso_encrypt($data) {
    $key = common_sso_get_encryption_key();
    $iv = random_bytes(16); // 16 bytes for AES-256-CBC
    $cipher = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($cipher === false) {
        return false;
    }

    return base64_encode($iv . $cipher);
}

function common_sso_decrypt($data) {
    $key = common_sso_get_encryption_key();
    $decoded = base64_decode($data, true);

    if ($decoded === false || strlen($decoded) < 17) {
        return false;
    }

    $iv = substr($decoded, 0, 16);
    $cipher = substr($decoded, 16);

    return openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}
