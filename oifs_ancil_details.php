<?php
// Set the BOINC working directory, load site configuration, and define include paths.
require_once __DIR__ . '/../../project/cpdn_web_bootstrap.inc';
require_once(PROJECT_INC . 'oifs_ancil_web_common.inc');
require_once(BOINC_INC . 'util.inc');
require_once(BOINC_INC . 'user.inc');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$user = get_logged_in_user(false);
if (!$user) {
    $return_url = 'project/oifs_ancil_details.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $return_url .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: /login_form.php?next_url=' . rawurlencode($return_url));
    exit;
}
require_once(PROJECT_INC . 'allowed_uploaders.inc');
if (!isset($allowed_uploaders) || !is_array($allowed_uploaders)) {
    error_log('Ancillary authorization configuration is unavailable.');
    oifs_ancil_error(500, 'Uploader authorization configuration is unavailable.');
}
if (!in_array($user->email_addr, $allowed_uploaders, true)) {
    oifs_ancil_error(403, 'This page has restricted access.');
}
header('Content-Type: text/html; charset=UTF-8');

function oifs_details_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$file_name = $_GET['file_name'] ?? $_POST['file_name'] ?? null;
if (!oifs_ancil_valid_text($file_name, 100)) {
    oifs_ancil_error(400, 'Please specify a valid file name of at most 100 characters.');
}
ob_start();
$ancil_buffer_level = ob_get_level();
$link = null;
try {
    list($link, $dbname) = oifs_ancil_database();

    echo <<<EOH
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>OpenIFS@home Ancil File Details</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/cpdn.css">
</head>
<body>
EOH;

    echo '<div class="wrap" style="width:100%">';
    echo '<div style="width:100%">';
    echo '<img src="/img/OIFS_Home_logo.png" alt="OpenIFS@home" style="width:200px">';
    echo '<img src="/img/CPDN_abbrv_logo.png" alt="CPDN" style="width:250px; float:right;">';
    echo '</div>';
    echo '<div style="clear: both;"></div>';
    echo '</div>';
    echo '<hr>';
    echo '<strong>Ancil File Details</strong><br><br>';

    $table = $dbname . '.oifs_ancil_files';
    $fields = 'file_name, create_time, created_by, description, ancil_type, ancil_sub_type, model_version_number, exptid, starting_analysis, analysis_perturbation_number, start_date, end_date, grid_type, spectral_horizontal_resolution, gridpoint_horizontal_resolution, vertical_resolution, batches_used, md5sum, url, status';
    $query = $link->prepare("SELECT $fields FROM $table WHERE file_name=?");
    $query->bind_param('s', $file_name);
    $query->execute();
    $query->store_result();

    // Retain bind_result(), which does not require mysqli get_result().
    $query->bind_result(
        $file_name,
        $create_time,
        $created_by,
        $description,
        $ancil_type,
        $ancil_sub_type,
        $model_version_number,
        $exptid,
        $starting_analysis,
        $analysis_perturbation_number,
        $start_date,
        $end_date,
        $grid_type,
        $spectral_horizontal_resolution,
        $gridpoint_horizontal_resolution,
        $vertical_resolution,
        $batches_used,
        $md5sum,
        $url,
        $status
    );

    if ($query->num_rows === 0) {
        http_response_code(404);
        echo '<p>No ancillary file found with that name.</p>';
    }
    while ($query->fetch()) {
        $details = array(
            'Filename' => $file_name,
            'Create time' => $create_time,
            'Created by' => $created_by,
            'Description' => $description,
            'Ancil type' => $ancil_type,
            'Ancil sub-type' => $ancil_sub_type,
            'Model version number' => $model_version_number,
            'ECMWF Experiment ID' => $exptid,
            'Starting analysis' => $starting_analysis,
            'Analysis perturbation number' => $analysis_perturbation_number,
            'Start date' => $start_date,
            'End date' => $end_date,
            'Grid type' => $grid_type,
            'Spectral horizontal resolution' => $spectral_horizontal_resolution,
            'Gridpoint horizontal resolution' => $gridpoint_horizontal_resolution,
            'Vertical resolution' => $vertical_resolution
        );
        foreach ($details as $label => $value) {
            echo '<strong>' . $label . ': </strong>' . oifs_details_escape($value) . '<br>';
        }
        if (!empty($batches_used)) {
            // Assign for each result rather than accumulating into an undefined variable.
            $out = implode(', ', explode(',', (string)$batches_used));
            echo '<strong>Batches used: </strong>' . oifs_details_escape($out) . '<br>';
        }
        echo '<strong>md5sum: </strong>' . oifs_details_escape($md5sum) . '<br>';

        $url_text = (string)$url;
        $scheme = strtolower((string)parse_url($url_text, PHP_URL_SCHEME));
        echo '<strong>url: </strong>';
        if (filter_var($url_text, FILTER_VALIDATE_URL) !== false
                && in_array($scheme, array('http', 'https', 'ftp', 'ftps'), true)) {
            echo '<a href="' . oifs_details_escape($url_text) . '">'
                . oifs_details_escape($url_text) . '</a>';
        } else {
            echo oifs_details_escape($url_text);
        }
        echo '<br><strong>Status: </strong>' . oifs_details_escape($status) . '<br>';
    }
    $query->free_result();
    $query->close();

?>
<br><i>Note Status values: 0=valid, 1=replaced, 2=replaced and deprecated, 3=deleted completely, 4=archived/ not available</i>
</body>
</html>

<?php
    ob_end_flush();
} catch (Throwable $error) {
    error_log('Ancillary file lookup failed: ' . $error->getMessage());
    oifs_ancil_error(500, 'Unable to retrieve ancillary file details. Please contact the server administrator.');
} finally {
    if ($link instanceof mysqli) {
        $link->close();
    }
}
