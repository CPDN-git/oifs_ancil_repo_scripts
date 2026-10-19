<?php

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
define('CPDN_LOCAL',   PROJECT_PATH . 'html/user/cpdn');
define('BOINC_INC',    PROJECT_PATH . 'html/inc/');
define('PROJECT_INC', rtrim($project_path, '/') . '/html/project/');

// =========================================================================
// 2. CLIENT-SIDE PATHS (HTML) - *MUST* always start with "/" to target the domain root
//    e.g. src="/img/mylogo.png"  and not  src="../img/mylogo.png"
//    Where "/img/mylogo.png"   means  main.cpdn.org/img/mylogo.png
// =========================================================================

// Load BOINC using its absolute server location
set_include_path(
    get_include_path() .
    PATH_SEPARATOR . BOINC_INC .
    PATH_SEPARATOR . CPDN_LOCAL .
    PATH_SEPARATOR . PROJECT_INC
);

require_once(BOINC_INC . 'util.inc');

// Load custom local classes explicitly
require_once(PROJECT_INC . 'html_table.class.php');

// Load cpdn custom config XML
$xml = simplexml_load_file(PROJECT_PATH . "ancil_batch_user_config.xml")
    or die("Error: Configuration file missing.");

$host= $xml->ancil_db_host;
$dbname=$xml->ancil_db_name;
$boinc_dbname=$xml->ancil_boinc_db_name;
$user= $xml->ancil_user;
$pass= $xml->ancil_passwd;

class Auto_Table extends HTML_table {
    public function make_table($statement) {
        $metadata = $statement->result_metadata()
            or die('Error reading search columns: ' . $statement->error);
        $fields = $metadata->fetch_fields();

        $this->addTSection('thead');
        $this->addRow();
        // Keep the final "Batches used" column hidden; it controls shading.
        foreach (array_slice($fields, 0, -1) as $field) {
            $this->addCell(
                htmlspecialchars($field->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '',
                'header'
            );
        }

        // Bind result columns directly, without requiring mysqli get_result().
        $row = array_fill(0, count($fields), null);
        $statement->bind_result(...$row)
            or die('Error binding search results: ' . $statement->error);

        $this->addTSection('tbody');
        while ($statement->fetch()) {
            $this->addRow();
            $attrs = array(
                'class' => htmlspecialchars((string)$row[4], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
            if (end($row) != 0) {
                $attrs['bgcolor'] = 'F5F5F5';
            }
            foreach (array_slice($row, 0, -1) as $index => $cell) {
                $content = htmlspecialchars((string)$cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if ($index === 0) {
                    $details_url = 'https://main.cpdn.org/cpdn/oifs_ancil_details.php?file_name='
                        . rawurlencode((string)$cell);
                    $content = '<a href="'
                        . htmlspecialchars($details_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        . '">' . $content . '</a>';
                }
                $this->addCell($content, '', 'data', $attrs);
            }
        }
        $metadata->free();
    }
}

echo <<<EOH
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>OpenIFS File Search</title>
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
echo '<hr><h1>OpenIFS file search</h1><br>';
?>
<form action="/cpdn/oifs_search.php" method="post">
    <table width="100%" border="0" style="border:none;">
        <tr class="nohover">
            <td><label>File Name:<br></label><input type="text" name="by_name" /></td>
            <td><label>Case Study/Description:<br></label><input type="text" name="by_desc" /></td>
            <td><label>Type:<br></label><select name="by_type">
                <option value="">Select...</option>
                <option value="ic_ancil">initial files</option>
                <option value="ifsdata">ifsdata</option>
                <option value="climate_data">climate_data</option>
                <option value="fullpos_namelist">FullPos namelist</option>
            </select></td>
            <td><label>Sub type:<br></label><select name="by_subtype">
                <option value="">Select...</option>
                <option value="GHG_files">GHG files</option>
                <option value="other_radiation_files">Other radiation files</option>
                <option value="SO4_files">SO4 files</option>
            </select></td>
            <td><label>Start Date:<br></label><input type="text" name="by_start" /></td>
            <td><label>ECMWF exptid:<br></label><input type="text" name="by_exptid" /></td>
            <td><input class="button" type="submit" name="submit" value="Search" /></td>
        </tr>
    </table>
</form>
<?php
if (isset($_POST['submit'])) {
    $query = "SELECT file_name as 'File name', date(create_time) as 'Creation date', created_by as 'Created by', description as 'Description', ancil_type as 'Type', IF(STRCMP(ancil_sub_type,'0') = 0,'',ancil_sub_type) as 'Sub type', model_version_number as 'Model version', exptid as 'Exptid', starting_analysis as 'Starting analysis', analysis_perturbation_number as 'Analysis number', start_date as 'Start date', end_date as 'End date', CONCAT(COALESCE(grid_type,''),COALESCE(spectral_horizontal_resolution,''),COALESCE(gridpoint_horizontal_resolution,'')) as 'Horizontal resolution', vertical_resolution as 'Vertical resolution', batches_used as 'Batches used' FROM " . $dbname . '.oifs_ancil_files';
    $conditions = array();
    $parameters = array();
    // SQL column names and operators come only from this fixed mapping.
    $filters = array(
        'by_name' => array('file_name', 'LIKE'),
        'by_desc' => array('description', 'LIKE'),
        'by_type' => array('ancil_type', '='),
        'by_subtype' => array('ancil_sub_type', '='),
        'by_start' => array('start_date', '='),
        'by_exptid' => array('exptid', '=')
    );
    foreach ($filters as $input => $filter) {
        $value = $_POST[$input] ?? '';
        if (!is_string($value)) {
            die('Invalid search value.');
        }
        if ($value !== '') {
            $conditions[] = $filter[0] . ' ' . $filter[1] . ' ?';
            $parameters[] = $filter[1] === 'LIKE' ? '%' . $value . '%' : $value;
        }
    }
    $sql = $query;
    if (count($conditions) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $table_class = 'cpdnTable';

    $link = mysqli_connect($host, $user, $pass, $dbname)
        or die('Error ' . mysqli_connect_error());
    $link->set_charset('utf8mb4')
        or die('Error setting search encoding: ' . mysqli_error($link));
    $statement = $link->prepare($sql)
        or die('Error preparing search: ' . mysqli_error($link));
    if (count($parameters) > 0) {
        $types = str_repeat('s', count($parameters));
        $statement->bind_param($types, ...$parameters)
            or die('Error binding search: ' . $statement->error);
    }
    $statement->execute()
        or die('Error executing search: ' . $statement->error);
    $statement->store_result()
        or die('Error storing search results: ' . $statement->error);

    if ($statement->num_rows > 0) {
        $tbl = new Auto_Table('SearchResultsTable', $table_class);
        $tbl->make_table($statement);
        echo $tbl->display();
    } else {
        echo '<p>No matching files found.</p>';
    }
    $statement->free_result();
    $statement->close();
    mysqli_close($link);
}
?>
</body>
</html>
