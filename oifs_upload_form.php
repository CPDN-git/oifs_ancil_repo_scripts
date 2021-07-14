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
<META NAME="ROBOTS" CONTENT="NOINDEV, NOFOLLOW">
<script type="text/javascript" src="jquery/jquery-latest.js"></script>
<script type="text/javascript" src="jquery/jquery.tablesorter.js"></script>
<script type="text/javascript" src="../js/plupload.full.min.js"></script>
<script type="text/javascript">
                        $(document).ready(function() {
                                $("#myTable").tablesorter();
                        });
                </script>
<script type="text/javascript">
    function condDisp(ancil_type,model_version)
{
    switch(ancil_type){
    case 'ic_ancil':
        document.getElementById("ic_ancil_descriptions").style.display = 'block';
        document.getElementById("gridInfo40r1").style.display = 'none';
        document.getElementById("gridInfo43r3").style.display = 'none';
        document.getElementById("subType").style.display = 'none';
        document.getElementById("sub_type").value = "0";
        document.getElementById("file_desc").value = "";
    break;
    case 'ifsdata':
        document.getElementById("subType").style.display = 'block';
        switch(model_version){
            case '43r3':
                document.getElementById("gridInfo43r3").style.display = 'block';
                document.getElementById("gridInfo40r1").style.display = 'none';
            break;
            case '40r1':
                document.getElementById("gridInfo40r1").style.display = 'block';
                document.getElementById("gridInfo43r3").style.display = 'none';
            break;
            default:
                document.getElementById("gridInfo43r3").style.display = 'block';
                document.getElementById("gridInfo40r1").style.display = 'none';
            break;
        }    
        document.getElementById("ic_ancil_descriptions").style.display = 'none';
        document.getElementById("starting_analysis").value = "0";
        document.getElementById("file_desc").value = "";
    break;
    case 'climate_data':
        document.getElementById("subType").style.display = 'none';
        switch(model_version){
            case '43r3':
                document.getElementById("gridInfo43r3").style.display = 'block';
                document.getElementById("gridInfo40r1").style.display = 'none';
            break;
            case '40r1':
                document.getElementById("gridInfo40r1").style.display = 'block';
                document.getElementById("gridInfo43r3").style.display = 'none';
            break;
            default:
                document.getElementById("gridInfo43r3").style.display = 'block';
                document.getElementById("gridInfo40r1").style.display = 'none';
            break;
        }
        document.getElementById("ic_ancil_descriptions").style.display = 'none';
        document.getElementById("sub_type").value = "0";
        document.getElementById("starting_analysis").value = "0";
        document.getElementById("file_desc").value = "";
        break;
    case 'fullpos_namelist':
        document.getElementById("subType").style.display = 'none';
        document.getElementById("ic_ancil_descriptions").style.display = 'none';
        document.getElementById("gridInfo40r1").style.display = 'none';
        document.getElementById("gridInfo43r3").style.display = 'none';
        document.getElementById("sub_type").value = "0";
        document.getElementById("starting_analysis").value = "0";
        document.getElementById("file_desc").value = "";
        break;
    default:
        document.getElementById("subType").style.display = 'none';
        document.getElementById("ic_ancil_descriptions").style.display = 'none';
        document.getElementById("gridInfo40r1").style.display = 'none';
        document.getElementById("gridInfo43r3").style.display = 'none';
        document.getElementById("sub_type").value = "0";
        document.getElementById("starting_analysis").value = "0";
        document.getElementById("file_desc").value = "";
    
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

echo '<div class="wrap" style="width:100%">';
echo '<div style="width:100%">';
echo '<img src="img/OIFS_Home_logo.png" alt="OpenIFS@home" style="width:200px">';
echo '<img src="img/CPDN_abbrv_logo.png" alt="CPDN" style="width:250px; float:right;">';
echo '</div>';
echo '<div style="clear: both;"></div>';
echo '</div>';
echo '<hr>';

$max_post_size = ini_get('post_max_size');
#echo $max_post_size;

$user = get_logged_in_user();
if (in_array($user->email_addr,$allowed_uploaders)){
    echo "<p>$user->name is logged in";
    ?>
    <p>Enter the following information to upload your experiment file(s).</p>
    <form id="upload_form" name="upload_form" action="oifs_upload_handler.php" method="post" enctype="multipart/form-data" upload_max_filesize=300000000 post_max_size=500000000>
    Created by: <input type="text" name="created_by">
    Model version: <select name="model_version" class="dropdown" onchange="condDisp(ancil_type.value,this.value);">
        <option value="0">Select</option>
        <option value='40r1'>40r1</option>
        <option value='43r3'>43r3</option>
        </select>
    File type: <select id="ancil_type" name="ancil_type" class="dropdown" onchange="condDisp(this.value, model_version.value);">
        <option value="0">Select</option>
        <option value="ic_ancil">initial files (as .tgz)</option>
        <option value="ifsdata">ifsdata (as .zip) </option>
        <option value="climate_data">climate_data (as .zip)</option>
        <option value="fullpos_namelist">FullPos namelist (as .nml)</option>
        </select><br><br>
    
    <div id="subType" name="subType" style="display: none;">
    Sub type: <select id="sub_type" name="sub_type" class="dropdown">
        <option value="0">Select</option>
                <option value="GHG_files">GHG files</option>
                <option value="other_radiation_files">Other radiation files</option>
                <option value="SO4_files">SO4 files</option>
                </select><br><br>
    </div>
    <div id="gridInfo40r1" name="gridInfo40r1" style="display: none;">
    Horizontal resolution and Grid <a href="https://confluence.ecmwf.int/display/OIFS/4.3+OpenIFS%3A+Horizontal+Resolution+and+Configurations">(info)</a>: <select id="grid_hres_40r1" name="grid_hres_40r1" class="dropdown">
        <option value="0">Select</option>
        <option value="Tl159">Tl159</option>
        <option value="Tl255">Tl255</option>
        </select><br><br>   
    </div>

    <div id="gridInfo43r3" name="gridInfo43r3" style="display: none;">
    Horizontal resolution and Grid <a href="https://confluence.ecmwf.int/display/OIFS/4.3+OpenIFS%3A+Horizontal+Resolution+and+Configurations">(info)</a>: <select id="grid_hres_43r3" name="grid_hres_43r3" class="dropdown">
        <option value="0">Select</option>
        <option value="Tl159">Tl159</option>
        <option value="Tl255">Tl255</option>
        <option value="Tl319">Tl319</option>
        <option value="Tl399">Tl399</option>
        <option value="Tco95">Tco95</option>
        <option value="Tco159">Tco159</option>
        <option value="Tco199">Tco199</option>
        </select><br><br>
    </div>
    
    <div id="ic_ancil_descriptions" name="ic_ancil_descriptions" style="display: none;">
    <p>Note initial files are uploaded as a .tgz file with the directory structure [exptid]/[start date time]/[analysis perturbation number]/[files].<br>Multiple dates and analysis numbers can be included in a single upload tarball.  The analysis perturbation number directory can be ommited if only one exists.</p>
    ECMWF experiment id: <input type="text" name="exptid" maxlength="4" size="4">
    Starting analysis: <select id="starting_analysis" name="starting_analysis" class="dropdown">
                <option value="0">Select</option>
                <option value="Operational">Operational</option>
                <option value="ERA5">ERA5</option>
                <option value="ERA-Interim">ERA-Interim</option>
                <option value="Other">Other</option>
                </select><br><br>
    </div>
    
    Case study scenario / Description: <p> <textarea name="file_desc" rows="3" cols="80"></textarea><br><br>

    <div id="filelist">Your browser doesn't have Flash, Silverlight or HTML5 support.</div>
    <br />
 
    <br />
    <pre id="console"></pre>

    <div id="container">
    Upload file here: <input id="pickfiles" type="file" href="javascript:;"><br><br>
    <input id="uploadfiles" type="submit" href="javascript:;">
    </div>

    <input type="hidden" id="fileName" name="fileName" value="">
<form>

<script type="text/javascript">
// Custom example logic
 
var uploader = new plupload.Uploader({
    runtimes : 'html5,flash,silverlight,html4',
     
    browse_button : 'pickfiles', // you can pass in id...
    container: document.getElementById('container'), // ... or DOM Element itself
  //  multi_selection: false,     
    url : "../plupload_handler.php",
    chunk_size : '100mb',

    filters : {
        max_file_size : '5gb',

        mime_types: [
            {title : "Zip files", extensions : "zip,tgz"},
        {title : "Namelist files", extensions : "nml"}
        ]
    },
 
    // Flash settings
    flash_swf_url : '../js/Moxie.swf',
 
    // Silverlight settings
    silverlight_xap_url : '../js/Moxie.xap',
     
 
    init: {
        PostInit: function() {
            document.getElementById('filelist').innerHTML = '';
 
            document.getElementById('uploadfiles').onclick = function() {
                uploader.start();
                return false;
            };
        },
 
        FilesAdded: function(up, files) {
            plupload.each(files, function(file) {
        document.getElementById('fileName').value = file.name ;
                document.getElementById('filelist').innerHTML += '<div id="' + file.id + '">' + file.name + ' (' + plupload.formatSize(file.size) + ') <b></b></div>';
            });
        },
 
        UploadProgress: function(up, file) {
            document.getElementById(file.id).getElementsByTagName('b')[0].innerHTML = '<span>' + file.percent + "%</span>";
        },

    FileUploaded: function(up, file) {
    },
    
    ChunkUploaded: function(up, file, info) {
    },

    UploadComplete: function() {
        document.getElementById('upload_form').submit();
    },
        Error: function(up, err) {
            document.getElementById('console').innerHTML += "\nError #" + err.code + ": " + err.message;
        }
    }
});

uploader.init();

</script>
<?php 
}
else {
    echo "You are not allowed to visit this page";
    }

?>
