<?php
// This file is part of BOINC.
// http://boinc.berkeley.edu
// Copyright (C) 2008 University of California
//
// BOINC is free software; you can redistribute it and/or modify it
// under the terms of the GNU Lesser General Public License
// as published by the Free Software Foundation,
// either version 3 of the License, or (at your option) any later version.
//
// BOINC is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// See the GNU Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public License
// along with BOINC.  If not, see <http://www.gnu.org/licenses/>.

require_once("../inc/util.inc");
require_once("../inc/user.inc");
require_once("../inc/boinc_db.inc");
require_once("../inc/oifs_uploaders.inc");


//page_head("OpenIFS Ancil upload form");

echo <<<EOH
<html>
<head>
<title>OpenIFS@home Ancil upload form</title>
<script type="text/javascript" src="jquery/jquery-latest.js"></script>
<script type="text/javascript" src="jquery/jquery.tablesorter.js"></script>
<script type="text/javascript">
                        $(document).ready(function() {
                                $("#myTable").tablesorter();
                        });
                </script>
<style type="text/css">
                        #sortedtable thead th {
                                color: #00f;
                                font-weight: bold;
                                text-decoration: underline;
                        }
                </style>
<link rel="stylesheet" href="cpdn.css">
</head>
<body>
EOH;

echo '<div class="wrap" style="width:100%">';
echo '<div style="width:100%">';
echo '<img src="img/OIFS_Home_logo.png" alt="OpenIFS@home" style="width:200px">';
echo '<img src="img/CPDN_abbrv_logo.png" alt="CPDN" style="width:250px; float:right;">';
echo '</div>';
echo '<div style="clear: both;"></div>';
echo '</div>';
echo '<hr>';

$user = get_logged_in_user();

$python_env='/home/boinc/miniconda2/envs/oifs_pyenv/bin/python';
$script_path='/storage/www/cpdnboinc_dev/oifs_ancil_repo_scripts/';
$tmp_dir='/storage/www/cpdnboinc_dev/tmp_ancil_upload/';

if (in_array($user->email_addr,$allowed_uploaders)){
        echo "$user->name is logged in<br>";
    echo 'Created by '.$_POST["created_by"].'<br>';
        echo 'Form was submitted, here are the form values: <pre>';
        print_r($_POST);
        echo "</pre>";

        $phpFileUploadErrors = array(
                0 => 'There is no error, the file uploaded with success',
                1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
                2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
                3 => 'The uploaded file was only partially uploaded',
                4 => 'No file was uploaded',
                6 => 'Missing a temporary folder',
                7 => 'Failed to write file to disk.',
                8 => 'A PHP extension stopped the file upload.'
        );

        if ($_POST['file_desc']=="" or $_POST['created_by']=="" or $_POST['model_version']==0){
                $upload_ok=FALSE;
                die("Error, Please make sure all information is entered.<br>");
        }
    $fileName=$_POST['fileName'];
    
    echo '<p>Uploaded file: '.$fileName.'</p>';
    $md5_value=md5_file($tmp_dir.$fileName);
    echo '<p style=color:green;>md5 checksum: '.$md5_value.'</p>';
    
    $model_ver=$_POST['model_version'];
    $grid_hres=$_POST['grid_hres_'.$model_ver];

    $r = escapeshellcmd( $python_env.' '.$script_path.'oifs_file_upload_handler.py "'.$_POST['created_by'].'"  "'.$user->name.'" "'.$_POST['model_version'].'" "'.$_POST['exptid'].'" "'.$_POST['starting_analysis'].'" "'.$_POST['ancil_type'].'" "'.$_POST['sub_type'].'" "'.$grid_hres.'" "'.$_POST['file_desc'].'" "'.$fileName.'"');
    $output = shell_exec($r.' 2>&1');
    echo "<pre>$output</pre>";
}
else {
        die("You are not allowed to visit this page");
        } ?>

