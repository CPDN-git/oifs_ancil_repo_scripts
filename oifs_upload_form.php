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
<title>CPDN Ancil upload form</title>
<META NAME="ROBOTS" CONTENT="NOINDEV, NOFOLLOW">
<script type="text/javascript" src="jquery/jquery-latest.js"></script>
<script type="text/javascript" src="jquery/jquery.tablesorter.js"></script>
<script type="text/javascript">
                        $(document).ready(function() {
                                $("#myTable").tablesorter();
                        });
                </script>
<script type="text/javascript">
	function condDisp(ancil_type)
{
	switch(ancil_type){
	case 'ic_ancil':
                document.getElementById("ic_ancil_descriptions").style.display = 'block';
		document.getElementById("subType").style.display = 'none';
		document.getElementById("file_description").style.display = 'none';
		document.getElementById("sub_type").value = 0;
		document.getElementById("file_desc").value = "";
	break;
        case 'ifsdata':
                document.getElementById("subType").style.display = 'block';
                document.getElementById("file_description").style.display = 'block';
		document.getElementById("ic_ancil_descriptions").style.display = 'none';
		document.getElementById("ICMSH_desc").value = "";
		document.getElementById("ICMGG_surface_desc").value = "";
		document.getElementById("ICMGG_upper_air_desc").value = "";
		document.getElementById("ICMCL_desc").value = "";
		document.getElementById("wave_desc").value = "";
        break;
        case 'climate_data':
                document.getElementById("file_description").style.display = 'block';
		document.getElementById("subType").style.display = 'none';
                document.getElementById("ic_ancil_descriptions").style.display = 'none';
		document.getElementById("sub_type").value = 0;
		document.getElementById("file_desc").value = "";
		document.getElementById("ICMSH_desc").value = "";
                document.getElementById("ICMGG_surface_desc").value = "";
                document.getElementById("ICMGG_upper_air_desc").value = "";
                document.getElementById("ICMCL_desc").value = "";
                document.getElementById("wave_desc").value = "";
        break;
	default:
		document.getElementById("file_description").style.display = 'none';
                document.getElementById("subType").style.display = 'none';
                document.getElementById("ic_ancil_descriptions").style.display = 'none';
		document.getElementById("sub_type").value = 0;
                document.getElementById("file_desc").value = "";
		document.getElementById("ICMSH_desc").value = "";
                document.getElementById("ICMGG_surface_desc").value = "";
                document.getElementById("ICMGG_upper_air_desc").value = "";
                document.getElementById("ICMCL_desc").value = "";
                document.getElementById("wave_desc").value = "";
	}
}
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

$max_post_size = ini_get('post_max_size');
#echo $max_post_size;

$user = get_logged_in_user();
#if (in_array($user->email_addr,$allowed_uploaders)){
	echo "<p>$user->name is logged in";
	?>
	<p>Enter the following information to upload your experiment file(s)</p>
	<form action="oifs_upload_handler.php" method="post" enctype="multipart/form-data" upload_max_filesize=300000000 post_max_size=500000000>
	Created by: <input type="text" name="created_by">
	Model version: <select name="model_version" class="dropdown">
		<option value="0">Select</option>
		<option value='40r1'>40r1</option>
		<option value='43r3'>43r3</option>
		</select><br><br>
	Case study scenario description: <p> <textarea name="scenario" rows="3" cols="80"></textarea><br><br>
	File type: <select id="ancil_type" name="ancil_type" class="dropdown" onchange="condDisp(this.value);">
  		<option value="0">Select</option>
		<option value="ic_ancil">initial files</option>
  		<option value="ifsdata">ifsdata</option>
 		<option value="climate_data">climate_data</option>
		</select>
	
	<div id="subType" name="subType" style="display: none;">
	Sub type: <select id="sub_type" name="sub_type" class="dropdown">
		<option value="0">Select</option>
                <option value="CFC_files">CFC files</option>
                <option value="radiation_files">Radiation files</option>
                <option value="SO4 files">SO4 files</option>
                </select><br><br>	
	</div>
	
	<div id="file_description" name="file_description" style="display: none;">
	File description: <p> <textarea id="file_desc" name="file_desc" rows="3" cols="80"></textarea><br><br>	
	</div>
	
	<div id="ic_ancil_descriptions" name="ic_ancil_descriptions" style="display: none;">
	Starting analysis: <select name="starting_analysis" class="dropdown">
                <option value="0">Select</option>
                <option value="Operational">Operational</option>
                <option value="ERA5">ERA5</option>
                <option value="ERA-Interim">ERA-Interim</option>
                <option value="Other">Other</option>
                </select><br><br>
	</div>

	Upload file here: <input name="upload" type="file"><br><br>
	<input type="submit">
	</form>


<?php 
#}
#else {
#	echo "You are not allowed to visit this page";
#	}

?>
