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
<title>OpenIFS Ancil upload form</title>
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

echo '<div class="wrap">';
echo '<img src="img/logo.png">';
echo '<img src="img/OIFS_Home_logo.png" width="200"></div>';
echo '<hr>';

$xml=simplexml_load_file("/storage/www/cpdnboinc_alpha/ancil_batch_user_config.xml") or die("Error: Cannot create object");
$dbhost= $xml->db_host;
$dbname=$xml->db_name;
$dbuser= $xml->ancil_user;
$dbpass= $xml->ancil_passwd; 

$user = get_logged_in_user();

$python_env='/home/boinc/miniconda2/envs/oifs_pyenv/bin/python';
$script_path='/storage/www/cpdnboinc_alpha/oifs_ancil_repo_scripts/';
$tmp_dir='/storage/www/cpdnboinc_alpha/tmp_ancil_upload/';

if (in_array($user->email_addr,$allowed_uploaders)){
        echo "$user->name is logged in<br>";
	echo 'Created by '.$_POST["created_by"].'<br>';
        echo 'Form was submitted, here are the form values: <pre>';
        print_r($_POST);
        echo "</pre>";

	echo 'Form was submitted, here are the files: <pre>';
        print_r($_FILES);
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

        if ($_POST['scenario']=="" or $_POST['created_by']=="" or $_POST['model_version']==0){
                $upload_ok=FALSE;
                die("Error, Please make sure all information is entered.<br>");
        }

	$tmpFilePath = $_FILES['upload']['tmp_name'];
	$fileName=$_FILES['upload']['name'];

	$md5_value=md5_file($tmpFilePath);
        echo '<p style=color:green;>md5 checksum: '.$md5_value.'</p>';

	if(move_uploaded_file($tmpFilePath, $tmp_dir.$fileName)){
		chmod($tmp_dir.$fileName, 0775);
		echo '<p>File '.$fileName.' successfully moved to'.$tmp_dir.'</p>';
	}
	$r = escapeshellcmd( $python_env.' '.$script_path.'oifs_file_upload_handler.py "'.$_POST['created_by'].'"  "'.$user->name.'" "'.$_POST['model_version'].'" "'.$_POST['scenario'].'" "'.$_POST['starting_analysis'].'" "'.$_POST['ancil_type'].'" "'.$_POST['sub_type'].'" "'.$_POST['file_desc'].'" "'.$fileName.'"');
	echo "$r";

	$output = shell_exec($r);
	echo "<pre>$output</pre>";
	
}
else {
        die("You are not allowed to visit this page");
        } ?>

