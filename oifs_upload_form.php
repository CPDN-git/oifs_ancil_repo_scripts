<?php

//  OpenIFS ancil file upload form

// =========================================================================
// 1. SERVER-SIDE PATHS (PHP) - Always use explicit absolute paths
// =========================================================================
define('PROJECT_PATH', realpath(__DIR__ . '/../../..') . '/');   // i.e. /storage/www/cpdnboinc
define('CPDN_LOCAL',   __DIR__ );
define('BOINC_INC',    PROJECT_PATH . 'html/inc/');
define('LOCAL_INC',    __DIR__ . '/include/');
define('PROJECT_INC',  PROJECT_PATH . 'html/project/');

// =========================================================================
// 2. CLIENT-SIDE PATHS (HTML) - *MUST* always start with "/" to target the domain root
//    e.g. src="/img/mylogo.png"  and not  src="../img/mylogo.png"
//    Where "/img/mylogo.png"   means  main.cpdn.org/img/mylogo.png
// =========================================================================


//  Now switch to boinc's html root so all the includes load correctly
chdir(CPDN_LOCAL . '/..');

set_include_path(
    get_include_path() .
    PATH_SEPARATOR . BOINC_INC .
    PATH_SEPARATOR . CPDN_LOCAL
);


require_once(BOINC_INC . 'util.inc');
require_once(BOINC_INC . 'user.inc');
require_once(BOINC_INC . 'boinc_db.inc');
require_once(PROJECT_INC . 'oifs_uploaders.inc');

// BOINC's default login redirect drops the directory from the return URL.
$user = get_logged_in_user(false);
if (!$user) {
    header('Location: /login_form.php?next_url=' . rawurlencode('cpdn/oifs_upload_form.php'));
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

echo <<<EOH
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>OpenIFS@home Ancil upload form</title>
<meta name="robots" content="noindex, nofollow">
<script type="text/javascript" src="/js/plupload.full.min.js"></script>
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

echo '<p>' . htmlspecialchars((string)$user->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    . ' is logged in</p>';
    ?>
    <p>Enter the following information to upload your experiment file. Upload one archive or namelist per submission.</p>
    <form id="upload_form" name="upload_form" action="/cpdn/oifs_upload_handler.php" method="post" enctype="multipart/form-data">
    Created by: <input type="text" name="created_by" required>
    Model version: <select id="model_version" name="model_version" class="dropdown" onchange="condDisp(this.form.elements['ancil_type'].value, this.value);">
        <option value="0">Select</option>
        <option value='40r1'>40r1</option>
        <option value='43r3'>43r3</option>
        </select>
    File type: <select id="ancil_type" name="ancil_type" class="dropdown" onchange="condDisp(this.value, this.form.elements['model_version'].value);">
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

    Case study scenario / Description:<br><textarea id="file_desc" name="file_desc" rows="3" cols="80" required></textarea><br><br>

    <div id="filelist">Select a file to upload.</div>
    <br />

    <br />
    <pre id="console"></pre>

    <div id="container">
    <button id="pickfiles" type="button">Select file</button><br><br>
    <button id="uploadfiles" type="button" disabled>Upload file</button>
    </div>

    <input type="hidden" id="fileName" name="fileName" value="">
</form>

<script type="text/javascript">
var form = document.getElementById('upload_form');
var uploadButton = document.getElementById('uploadfiles');
var pickButton = document.getElementById('pickfiles');
var consoleOutput = document.getElementById('console');
var uploadFailed = false;
var uploadInProgress = false;

// Pressing Enter must use the file uploader before submitting metadata.
form.addEventListener('submit', function(event) {
    event.preventDefault();
    uploadButton.click();
});

function showUploadError(message) {
    uploadFailed = true;
    uploadInProgress = false;
    consoleOutput.textContent += '\n' + message;
    uploadButton.disabled = false;
    pickButton.disabled = false;
}

// The chunk handler reports failures as JSON even when HTTP status is 200.
function checkUploadResponse(up, info) {
    try {
        var response = JSON.parse(info.response);
        if (!response || response.error || response.jsonrpc !== '2.0') {
            showUploadError(response && response.error
                ? (response.error.message || 'The upload server reported an error.')
                : 'The upload server returned an invalid response.');
            up.stop();
            return false;
        }
    } catch (error) {
        showUploadError('The upload server returned an invalid response.');
        up.stop();
        return false;
    }
    return true;
}

if (typeof plupload === 'undefined') {
    showUploadError('Upload controls could not be loaded. Reload this page or contact support.');
    uploadButton.disabled = true;
    pickButton.disabled = true;
} else {
    var uploader = new plupload.Uploader({
        runtimes: 'html5,flash,silverlight,html4',
        browse_button: 'pickfiles',
        container: document.getElementById('container'),
        multi_selection: false,
        url: '/plupload_handler.php',
        chunk_size: '100mb',
        filters: {
            max_file_size: '5gb',
            mime_types: [
                {title: 'Zip files', extensions: 'zip,tgz'},
                {title: 'Namelist files', extensions: 'nml'}
            ]
        },
        flash_swf_url: '/js/Moxie.swf',
        silverlight_xap_url: '/js/Moxie.xap',
        init: {
            PostInit: function() {
                uploadButton.disabled = false;
                uploadButton.onclick = function() {
                    if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                        return;
                    }
                    if (form.elements['model_version'].value === '0'
                            || form.elements['ancil_type'].value === '0') {
                        consoleOutput.textContent = 'Select a model version and file type before uploading.';
                        return;
                    }
                    if (uploader.files.length !== 1) {
                        consoleOutput.textContent = 'Select one file before uploading.';
                        return;
                    }
                    uploadFailed = false;
                    uploadInProgress = true;
                    consoleOutput.textContent = '';
                    uploadButton.disabled = true;
                    pickButton.disabled = true;
                    uploader.start();
                };
            },
            FilesAdded: function(up, files) {
                if (uploadInProgress) {
                    plupload.each(files, function(file) {
                        up.removeFile(file);
                    });
                    return;
                }
                var selected = files[0];
                if (!selected) {
                    return;
                }
                // A new selection replaces the old one; the handler accepts one fileName.
                plupload.each(up.files.slice(), function(file) {
                    if (file.id !== selected.id) {
                        up.removeFile(file);
                    }
                });
                consoleOutput.textContent = files.length > 1
                    ? 'Only one file can be uploaded per submission.' : '';
                document.getElementById('fileName').value = selected.name;
                var fileList = document.getElementById('filelist');
                fileList.textContent = '';
                var entry = document.createElement('div');
                entry.id = selected.id;
                entry.appendChild(document.createTextNode(
                    selected.name + ' (' + plupload.formatSize(selected.size) + ') '
                ));
                entry.appendChild(document.createElement('b'));
                fileList.appendChild(entry);
            },
            UploadProgress: function(up, file) {
                document.getElementById(file.id).getElementsByTagName('b')[0].textContent
                    = file.percent + '%';
            },
            FileUploaded: function(up, file, info) {
                checkUploadResponse(up, info);
            },
            ChunkUploaded: function(up, file, info) {
                checkUploadResponse(up, info);
            },
            UploadComplete: function(up, files) {
                uploadInProgress = false;
                if (!uploadFailed && files.length === 1 && files[0].status === plupload.DONE) {
                    form.submit();
                }
            },
            Error: function(up, err) {
                showUploadError('Error #' + err.code + ': ' + err.message);
            }
        }
    });
    uploader.init();
}
</script>
</body>
</html>
