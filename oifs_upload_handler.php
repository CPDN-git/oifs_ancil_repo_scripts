<?php

//  OpenIFS ancillary file upload handler

// Load host configuration from html/project before defining paths.
// BOINC and the legacy site detection expect html/user as the working directory.
if (!chdir(__DIR__ . '/..')) {
    http_response_code(500);
    exit('Unable to select the BOINC web directory.');
}
$config_file = __DIR__ . '/../../project/batch_site_config.inc';
if (!is_readable($config_file)) {
    http_response_code(500);
    exit('Batch site configuration is unavailable.');
}
require_once($config_file);
if (!isset($project_path) || !is_string($project_path) || $project_path === '') {
    http_response_code(500);
    exit('Batch site configuration is incomplete for this host.');
}

// =========================================================================
// 1. SERVER-SIDE PATHS (PHP) - Always use explicit absolute paths
// =========================================================================
define('PROJECT_PATH', rtrim($project_path, '/') . '/');
define('CPDN_LOCAL',   PROJECT_PATH . 'html/user/project');
define('BOINC_INC',    PROJECT_PATH . 'html/inc/');
define('PROJECT_INC',  PROJECT_PATH . 'html/project/');

// =========================================================================
// 2. CLIENT-SIDE PATHS (HTML) - *MUST* always start with "/" to target the domain root
//    e.g. src="/img/mylogo.png"  and not  src="../img/mylogo.png"
//    Where "/img/mylogo.png"   means  main.cpdn.org/img/mylogo.png
// =========================================================================

set_include_path(
    get_include_path() .
    PATH_SEPARATOR . BOINC_INC .
    PATH_SEPARATOR . CPDN_LOCAL .
    PATH_SEPARATOR . PROJECT_INC
);

require_once(BOINC_INC . 'util.inc');
require_once(BOINC_INC . 'user.inc');
require_once(BOINC_INC . 'boinc_db.inc');
require_once(PROJECT_INC . 'allowed_uploaders.inc');

// BOINC's default login redirect drops the directory from the return URL.
$user = get_logged_in_user(false);
if (!$user) {
    header('Location: /login_form.php?next_url=' . rawurlencode('project/oifs_upload_form.php'));
    exit;
}
if (!isset($allowed_uploaders) || !is_array($allowed_uploaders)) {
    http_response_code(500);
    exit('Uploader access configuration is unavailable.');
}
if (!in_array($user->email_addr, $allowed_uploaders, true)) {
    http_response_code(403);
    exit('You are not allowed to visit this page.');
}

// Plupload has already staged the file; this request supplies its metadata.
function upload_response($status, $message, $output = '')
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $message_html = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $output_html = htmlspecialchars($output, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo <<<EOH
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>OpenIFS@home Ancillary upload result</title>
<link rel="stylesheet" href="/cpdn.css">
</head>
<body>
<div class="wrap" style="width:100%">
    <img src="/img/OIFS_Home_logo.png" alt="OpenIFS@home" style="width:200px">
    <img src="/img/CPDN_abbrv_logo.png" alt="CPDN" style="width:250px; float:right;">
    <div style="clear:both;"></div>
</div>
<hr>
<h1>Ancillary upload result</h1>
<p>$message_html</p>
EOH;
    if ($output !== '') {
        echo '<pre>' . $output_html . '</pre>';
    }
    echo '<p><a href="/project/oifs_upload_form.php">Return to the upload form</a></p>';
    echo '</body></html>';
    exit;
}

function upload_field($name)
{
    if (!isset($_POST[$name]) || !is_string($_POST[$name]) || strpos($_POST[$name], "\0") !== false) {
        upload_response(400, 'Missing or invalid form field: ' . $name . '. Please submit the upload form again.');
    }
    return $_POST[$name];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    upload_response(405, 'Please submit an upload using the upload form.');
}

$created_by = upload_field('created_by');
$file_desc = upload_field('file_desc');
$model_ver = upload_field('model_version');
$ancil_type = upload_field('ancil_type');
$sub_type = upload_field('sub_type');
$exptid = upload_field('exptid');
$starting_analysis = upload_field('starting_analysis');
$file_name = upload_field('fileName');

$grids = array(
    '40r1' => array('0', 'Tl159', 'Tl255'),
    '43r3' => array('0', 'Tl159', 'Tl255', 'Tl319', 'Tl399', 'Tco95', 'Tco159', 'Tco199')
);
if (trim($created_by) === '' || trim($file_desc) === '' || !isset($grids[$model_ver])) {
    upload_response(400, 'Please provide a creator, description and supported model version.');
}
if (!in_array($ancil_type, array('ic_ancil', 'ifsdata', 'climate_data', 'fullpos_namelist'), true)) {
    upload_response(400, 'Please select a supported ancillary file type.');
}
if (!in_array($sub_type, array('0', 'GHG_files', 'other_radiation_files', 'SO4_files'), true) ||
    !in_array($starting_analysis, array('0', 'Operational', 'ERA5', 'ERA-Interim', 'Other'), true)) {
    upload_response(400, 'Invalid ancillary subtype or starting analysis.');
}
$grid_hres = upload_field('grid_hres_' . $model_ver);
if (!in_array($grid_hres, $grids[$model_ver], true)) {
    upload_response(400, 'Invalid grid selection for this model version.');
}
if (in_array($ancil_type, array('ifsdata', 'climate_data'), true) && $grid_hres === '0') {
    upload_response(400, 'Please select the horizontal resolution and grid.');
}
// The backend uses exptid as a directory name when unpacking initial files.
if ($ancil_type === 'ic_ancil' && !preg_match('/\A[A-Za-z0-9]{4}\z/', $exptid)) {
    upload_response(400, 'Initial files require a four-character alphanumeric experiment ID.');
}
if ($file_name === '' || $file_name === '.' || $file_name === '..' ||
    strpos($file_name, '/') !== false || strpos($file_name, '\\') !== false ||
    substr($file_name, -5) === '.part') {
    upload_response(400, 'Invalid uploaded filename.');
}

// Use the host configuration for the Python environment and backend location.
if (!isset($python_env, $oifs_repo_root) || !is_string($python_env) || $python_env === '' ||
    !is_string($oifs_repo_root) || $oifs_repo_root === '') {
    upload_response(500, 'The upload processor configuration is incomplete.');
}
$script_file = rtrim($oifs_repo_root, '/') . '/oifs_ancil_repo_scripts/oifs_file_upload_handler.py';
$tmp_dir = realpath(PROJECT_PATH . 'tmp_ancil_upload');
if ($tmp_dir === false || !is_dir($tmp_dir) || !is_readable($tmp_dir)) {
    upload_response(500, 'The upload staging directory is unavailable.');
}
$staged_file = $tmp_dir . DIRECTORY_SEPARATOR . $file_name;
$resolved_file = realpath($staged_file);
if ($resolved_file === false || dirname($resolved_file) !== $tmp_dir ||
    is_link($staged_file) || !is_file($resolved_file) || !is_readable($resolved_file)) {
    upload_response(400, 'The completed uploaded file is unavailable. Please upload the file again.');
}
$md5_value = md5_file($resolved_file);
if ($md5_value === false) {
    upload_response(500, 'Unable to calculate the uploaded file checksum.');
}
if (!is_executable($python_env) || !is_file($script_file) || !is_readable($script_file) || !is_callable('exec')) {
    upload_response(500, 'The upload processor is unavailable. Please contact the server administrator.');
}

// Quote each argument separately, preserving the backend's positional interface.
$arguments = array(
    $python_env, $script_file, $created_by, $user->name, $model_ver,
    $exptid, $starting_analysis, $ancil_type, $sub_type, $grid_hres,
    $file_desc, $file_name
);
$command = implode(' ', array_map('escapeshellarg', $arguments));
$output = array();
$exit_status = 1;
try {
    exec($command . ' 2>&1', $output, $exit_status);
} catch (Throwable $error) {
    error_log('Ancillary upload process could not be started: ' . $error->getMessage());
    upload_response(500, 'Unable to start the upload processor. Please contact the server administrator.');
}
if ($exit_status !== 0) {
    upload_response(500, 'Upload processing failed. Please contact the server administrator before retrying.', implode("\n", $output));
}
upload_response(200, 'Upload processing completed for ' . $file_name . '. MD5 checksum: ' . $md5_value, implode("\n", $output));
