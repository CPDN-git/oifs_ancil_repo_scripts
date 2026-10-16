<?php

// Force PHP to show errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// =========================================================================
// 1. SERVER-SIDE PATHS (PHP) - Always use explicit absolute paths
// =========================================================================
define('PROJECT_PATH', realpath(__DIR__ . '/../../..') . '/');   // i.e. /storage/www/cpdnboinc
define('CPDN_LOCAL',   __DIR__ );
define('BOINC_INC',    PROJECT_PATH . 'html/inc/');
define('LOCAL_INC',    __DIR__ . '/include/');

// =========================================================================
// 2. CLIENT-SIDE PATHS (HTML) - *MUST* always start with "/" to target the domain root
//    e.g. src="/img/mylogo.png"  and not  src="../img/mylogo.png"
//    Where "/img/mylogo.png"   means  main.cpdn.org/img/mylogo.png
// =========================================================================


//  Now switch to boinc's html root so all the includes load correctly
chdir(CPDN_LOCAL . '/..');

// Load BOINC using its absolute server location
require_once(BOINC_INC . 'util.inc');

// Load cpdn custom config XML
$xml = simplexml_load_file(PROJECT_PATH . "ancil_batch_user_config.xml")
    or die("Error: Configuration file missing.");

//  Set the global include paths, including ours.
set_include_path(
    get_include_path() .
    PATH_SEPARATOR . BOINC_INC .
    PATH_SEPARATOR . CPDN_LOCAL
);



$host= $xml->ancil_db_host;
$dbname=$xml->ancil_db_name;
$user= $xml->ancil_user;
$pass= $xml->ancil_passwd;

function oifs_details_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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

$file_name = $_GET['file_name'] ?? $_POST['file_name'] ?? '';
if (!is_string($file_name) || $file_name === '') {
    echo '<p>Please specify a valid file name.</p>';
} else {
    $table = $dbname . '.oifs_ancil_files';
    $fields = 'file_name, create_time, created_by, description, ancil_type, ancil_sub_type, model_version_number, exptid, starting_analysis, analysis_perturbation_number, start_date, end_date, grid_type, spectral_horizontal_resolution, gridpoint_horizontal_resolution, vertical_resolution, batches_used, md5sum, url, status';
    $link = mysqli_connect($host, $user, $pass, $dbname)
        or die('Error ' . mysqli_connect_error());
    $link->set_charset('utf8mb4')
        or die('Error setting details encoding: ' . mysqli_error($link));
    $query = $link->prepare("SELECT $fields FROM $table WHERE file_name=?")
        or die('Error preparing file lookup: ' . mysqli_error($link));
    $query->bind_param('s', $file_name)
        or die('Error binding file lookup: ' . $query->error);
    $query->execute()
        or die('Error executing file lookup: ' . $query->error);
    $query->store_result()
        or die('Error storing file details: ' . $query->error);

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
    ) or die('Error binding file details: ' . $query->error);

    if ($query->num_rows === 0) {
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
    mysqli_close($link);
}
?>
<br><i>Note Status values: 0=valid, 1=replaced, 2=replaced and deprecated, 3=deleted completely, 4=archived/ not available</i>
</body>
</html>
