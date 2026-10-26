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
    $return_url = 'project/oifs_search.php';
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

require_once(PROJECT_INC . 'html_table.class.php');

class Auto_Table extends HTML_table {
    public function make_table($statement) {
        $metadata = $statement->result_metadata();
        if ($metadata === false) {
            throw new RuntimeException('Search result metadata is unavailable.');
        }
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
        $statement->bind_result(...$row);

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
                    $details_url = 'https://main.cpdn.org/project/oifs_ancil_details.php?file_name='
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

ob_start();
$ancil_buffer_level = ob_get_level();
$link = null;
try {

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
<form action="/project/oifs_search.php" method="post">
    <table width="100%" border="0" style="border:none;">
        <tr class="nohover">
            <td><label>File Name:<br></label><input type="text" name="by_name" maxlength="100" /></td>
            <td><label>Case Study/Description:<br></label><input type="text" name="by_desc" maxlength="1024" /></td>
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
            <td><label>Start Date:<br></label><input type="text" name="by_start" maxlength="10" /></td>
            <td><label>ECMWF exptid:<br></label><input type="text" name="by_exptid" maxlength="4" /></td>
            <td><input class="button" type="submit" name="submit" value="Search" /></td>
        </tr>
    </table>
</form>
<?php
    if (isset($_POST['submit'])) {
        list($link, $dbname) = oifs_ancil_database();
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
        $limits = array('by_name' => 100, 'by_desc' => 1024, 'by_type' => 25,
            'by_subtype' => 21, 'by_start' => 10, 'by_exptid' => 4);
        foreach ($filters as $input => $filter) {
            $value = $_POST[$input] ?? '';
            if (!oifs_ancil_valid_text($value, $limits[$input], true)) {
                oifs_ancil_error(400, 'A search value is invalid or too long.');
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

        $statement = $link->prepare($sql);
        if (count($parameters) > 0) {
            $types = str_repeat('s', count($parameters));
            $statement->bind_param($types, ...$parameters);
        }
        $statement->execute();
        $statement->store_result();

        if ($statement->num_rows > 0) {
            $tbl = new Auto_Table('SearchResultsTable', $table_class);
            $tbl->make_table($statement);
            echo $tbl->display();
        } else {
            echo '<p>No matching files found.</p>';
        }
        $statement->free_result();
        $statement->close();

    }
?>
</body>
</html>

<?php
    ob_end_flush();
} catch (Throwable $error) {
    error_log('Ancillary search failed: ' . $error->getMessage());
    oifs_ancil_error(500, 'Unable to search ancillary files. Please contact the server administrator.');
} finally {
    if ($link instanceof mysqli) {
        $link->close();
    }
}
