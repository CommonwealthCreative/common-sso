<?php
function common_sso_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $message\n";
    file_put_contents(COMMON_SSO_DIR . 'common-sso.log', $line, FILE_APPEND);
}
