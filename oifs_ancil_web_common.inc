<?php
// Ancillary page helpers. Callers load the shared CPDN bootstrap and authorize users.
// Including this file performs no authentication, database access or output.
function oifs_ancil_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function oifs_ancil_error($status, $message) {
    // Discard only this page's buffered markup, so an error returns one document.
    global $ancil_buffer_level;
    if (isset($ancil_buffer_level)) {
        while (ob_get_level() >= $ancil_buffer_level) {
            ob_end_clean();
        }
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<title>OpenIFS ancillary error</title><link rel="stylesheet" href="/cpdn.css">';
    echo '</head><body><h1>OpenIFS ancillary error</h1><p>' . oifs_ancil_h($message) . '</p>';
    echo '<p><a href="/project/oifs_search.php">File search</a> | ';
    echo '<a href="/project/oifs_upload_form.php">Upload form</a></p></body></html>';
    exit;
}

function oifs_ancil_valid_text($value, $maximum, $allow_empty = false, $multiline = false) {
    if (!is_string($value) || strlen($value) > 4 * $maximum) {
        return false;
    }
    $length = preg_match_all('/./us', $value);
    if ($length === false || $length > $maximum || (!$allow_empty && trim($value) === '')) {
        return false;
    }
    $controls = $multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/';
    return !preg_match($controls, $value);
}

function oifs_ancil_database() {
    $previous = libxml_use_internal_errors(true);
    try {
        $xml = @simplexml_load_file(PROJECT_PATH . 'ancil_batch_user_config.xml');
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if ($xml === false) {
        throw new RuntimeException('Ancillary database configuration is unavailable.');
    }
    $schema = (string)$xml->ancil_db_name;
    if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $schema)) {
        throw new RuntimeException('Invalid ancillary database schema configuration.');
    }
    $host = (string)$xml->ancil_db_host;
    $account = (string)$xml->ancil_user;
    if ($host === '' || $account === '') {
        throw new RuntimeException('Incomplete ancillary database configuration.');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $account, (string)$xml->ancil_passwd, $schema);
    $db->set_charset('utf8mb4');
    return array($db, $schema);
}

// The form and processor must offer/accept exactly the same model/grid choices.
function oifs_ancil_model_grids() {
    return array(
        '40r1' => array('Tl159', 'Tl255'),
        '43r3' => array('Tl159', 'Tl255', 'Tl319', 'Tl399', 'Tco95', 'Tco159', 'Tco199')
    );
}
