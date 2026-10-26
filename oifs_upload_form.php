<?php
// This file is part of BOINC.
// https://boinc.berkeley.edu
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
// along with BOINC.  If not, see <https://www.gnu.org/licenses/>.

// Set the BOINC working directory, load site configuration, and define include paths.
require_once __DIR__ . '/../../project/cpdn_web_bootstrap.inc';
require_once(PROJECT_INC . 'oifs_ancil_web_common.inc');
require_once(BOINC_INC . 'util.inc');
require_once(BOINC_INC . 'user.inc');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$user = get_logged_in_user(false);
if (!$user) {
    $return_url = 'project/oifs_upload_form.php';
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

try {
    $upload_id = bin2hex(random_bytes(16));
} catch (Throwable $error) {
    error_log('OpenIFS upload ticket creation failed.');
    oifs_ancil_error(500, 'Unable to prepare the upload form. Please contact the administrator.');
}
$upload_expires = time() + 86400;
$upload_token = hash_hmac('sha256', $user->id . ':' . $upload_id . ':' . $upload_expires,
    $user->authenticator);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>OpenIFS@home ancillary file upload</title>
    <link rel="stylesheet" href="/cpdn.css">
    <script src="/js/plupload.full.min.js" defer></script>
</head>
<body>
    <header>
        <img src="/img/OIFS_Home_logo.png" alt="OpenIFS@home" width="200">
        <img src="/img/CPDN_abbrv_logo.png" alt="CPDN" width="250">
    </header>
    <h1>Upload an OpenIFS ancillary file</h1>
    <p><?= htmlspecialchars($user->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> is logged in.</p>
    <p>Upload one file at a time, up to 5 GiB. Keep this page open until processing finishes.</p>
    <noscript>This form requires JavaScript and a browser supporting HTML5 file uploads.</noscript>
    <form id="upload_form" action="/project/oifs_upload_handler.php" method="post">
        <?= form_tokens($user->authenticator) ?>
        <input type="hidden" name="phase" value="process">
        <input type="hidden" name="upload_id" value="<?= $upload_id ?>">
        <input type="hidden" name="upload_expires" value="<?= $upload_expires ?>">
        <input type="hidden" name="upload_token" value="<?= $upload_token ?>">
        <input type="hidden" id="fileName" name="fileName" value="">
        <p><label for="created_by">Created by</label>
            <input id="created_by" name="created_by" required maxlength="80"></p>
        <p><label for="model_version">Model version</label>
            <select id="model_version" name="model_version" required>
                <option value="">Select</option>
                <option value="40r1">40r1</option>
                <option value="43r3">43r3</option>
            </select></p>
        <p><label for="ancil_type">File type</label>
            <select id="ancil_type" name="ancil_type" required>
                <option value="">Select</option>
                <option value="ic_ancil">Initial files (.tgz)</option>
                <option value="ifsdata">ifsdata (.zip)</option>
                <option value="climate_data">climate_data (.zip)</option>
                <option value="fullpos_namelist">FullPos namelist (.nml)</option>
            </select></p>
        <div id="subType" hidden>
            <label for="sub_type">Subtype</label>
            <select id="sub_type" name="sub_type" disabled>
                <option value="">Select</option>
                <option value="GHG_files">GHG files</option>
                <option value="other_radiation_files">Other radiation files</option>
                <option value="SO4_files">SO4 files</option>
            </select>
        </div>
        <?php foreach (oifs_ancil_model_grids() as $version => $grids): ?>
        <div id="gridInfo<?= $version ?>" hidden>
            <label for="grid_hres_<?= $version ?>">Horizontal resolution and grid (<?= $version ?>)</label>
            <select id="grid_hres_<?= $version ?>" name="grid_hres_<?= $version ?>" disabled>
                <option value="">Select</option>
                <?php foreach ($grids as $grid): ?>
                <option value="<?= $grid ?>"><?= $grid ?></option>
                <?php endforeach; ?>
            </select>
            <a href="https://confluence.ecmwf.int/display/OIFS/4.3+OpenIFS%3A+Horizontal+Resolution+and+Configurations">Grid information</a>
        </div>
        <?php endforeach; ?>
        <div id="ic_ancil_descriptions" hidden>
            <p>Upload initial files as a .tgz with the directory structure
                [exptid]/[start date time]/[analysis perturbation number]/[files].
                Multiple dates and analysis numbers can be included. The analysis
                perturbation directory can be omitted when only one exists.</p>
            <p><label for="exptid">ECMWF experiment ID</label>
                <input id="exptid" name="exptid" maxlength="4" size="4"
                    pattern="[A-Za-z0-9]{4}" disabled></p>
            <p><label for="starting_analysis">Starting analysis</label>
                <select id="starting_analysis" name="starting_analysis" disabled>
                    <option value="">Select</option>
                    <option value="Operational">Operational</option>
                    <option value="ERA5">ERA5</option>
                    <option value="ERA-Interim">ERA-Interim</option>
                    <option value="Other">Other</option>
                </select></p>
        </div>
        <p><label for="file_desc">Case study scenario / description</label><br>
            <textarea id="file_desc" name="file_desc" rows="3" cols="80"
                maxlength="8000" required></textarea></p>
        <div id="container">
            <button id="pickfiles" type="button" disabled>Choose file</button>
            <button id="uploadfiles" type="submit" disabled>Upload and process</button>
        </div>
        <p id="filelist">No file selected.</p>
        <p id="console" role="status" aria-live="polite"></p>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        'use strict';
        const form = document.getElementById('upload_form');
        const status = document.getElementById('console');
        const list = document.getElementById('filelist');
        const button = document.getElementById('uploadfiles');
        const picker = document.getElementById('pickfiles');
        const type = document.getElementById('ancil_type');
        const version = document.getElementById('model_version');
        let busy = false;
        let failed = false;
        let received = false;
        let completed = false;

        function showFields(groupId, enabled) {
            const group = document.getElementById(groupId);
            group.hidden = !enabled;
            group.querySelectorAll('input, select').forEach(function (field) {
                field.disabled = !enabled;
                field.required = enabled;
                if (!enabled) field.value = '';
            });
        }
        function condDisp() {
            const grid = type.value === 'ifsdata' || type.value === 'climate_data';
            showFields('subType', type.value === 'ifsdata');
            showFields('ic_ancil_descriptions', type.value === 'ic_ancil');
            document.querySelectorAll('[id^=gridInfo]').forEach(function (group) {
                showFields(group.id, grid && group.id === 'gridInfo' + version.value);
            });
        }
        type.addEventListener('change', condDisp);
        version.addEventListener('change', condDisp);
        condDisp();
        if (typeof plupload === 'undefined') {
            status.textContent = 'The upload library could not be loaded. Contact the administrator.';
            return;
        }
        const uploader = new plupload.Uploader({
            runtimes: 'html5',
            browse_button: 'pickfiles',
            container: document.getElementById('container'),
            url: '/project/oifs_upload_handler.php',
            multipart: true,
            file_data_name: 'file',
            unique_names: false,
            chunk_size: '100mb',
            multi_selection: false,
            max_retries: 2,
            filters: {
                max_file_size: '5gb',
                mime_types: [{title: 'Ancillary files', extensions: 'zip,tgz,nml'}]
            },
            init: {
                PostInit: function () {
                    picker.disabled = false;
                },
                FilesAdded: function (up, files) {
                    if (busy) {
                        files.forEach(function (file) { up.removeFile(file); });
                        return;
                    }
                    const selected = files[files.length - 1];
                    up.files.slice().forEach(function (file) {
                        if (file.id !== selected.id) up.removeFile(file);
                    });
                    document.getElementById('fileName').value = selected.name;
                    list.textContent = selected.name + ' (' + plupload.formatSize(selected.size) + ')';
                    status.textContent = '';
                    button.disabled = false;
                },
                BeforeUpload: function (up, file) {
                    const params = {phase: 'chunk', upload_size: String(file.size)};
                    ['upload_id', 'upload_expires', 'upload_token', 'tnow', 'ttok'].forEach(function (name) {
                        params[name] = form.elements.namedItem(name).value;
                    });
                    up.setOption('multipart_params', params);
                },
                UploadProgress: function (up, file) {
                    status.textContent = 'Uploading: ' + file.percent + '%';
                },
                FileUploaded: function (up, file, info) {
                    try {
                        const response = JSON.parse(info.response);
                        received = response.jsonrpc === '2.0' && !response.error && response.result
                            && response.result.complete === true
                            && response.result.upload_id === form.elements.namedItem('upload_id').value;
                    } catch (error) {
                        received = false;
                    }
                    if (!received) {
                        failed = true;
                        status.textContent = 'The server did not confirm a complete upload. Reload the form to try again.';
                    }
                },
                UploadComplete: function (up) {
                    if (failed || !received || up.files.length !== 1
                        || up.files[0].status !== plupload.DONE) return;
                    completed = true;
                    status.textContent = 'Upload complete. Processing the ancillary file. Please wait...';
                    form.querySelectorAll('[data-upload-enabled]').forEach(function (field) {
                        field.disabled = false;
                    });
                    // Native submission bypasses this form's upload-start listener.
                    HTMLFormElement.prototype.submit.call(form);
                },
                Error: function (up, error) {
                    status.textContent = 'Upload error: ' + error.message
                        + (busy ? ' Reload the form before trying again.' : '');
                    if (busy) {
                        failed = true;
                        up.stop();
                    }
                }
            }
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (busy || !form.reportValidity() || uploader.files.length !== 1) return;
            const file = uploader.files[0];
            const extension = type.value === 'ic_ancil' ? 'tgz'
                : (type.value === 'fullpos_namelist' ? 'nml' : 'zip');
            if (!/^[A-Za-z0-9][A-Za-z0-9._ -]*\.(tgz|zip|nml)$/i.test(file.name)
                || file.name.length > 100 || file.size < 1
                || file.name.split('.').pop().toLowerCase() !== extension) {
                status.textContent = 'Choose a nonempty .' + extension
                    + ' file with a simple filename (letters, numbers, spaces, dots, underscores or hyphens).';
                return;
            }
            busy = true;
            failed = false;
            received = false;
            picker.disabled = true;
            button.disabled = true;
            uploader.disableBrowse(true);
            // Freeze metadata during upload, then restore it for the final POST.
            form.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(function (field) {
                if (!field.disabled) {
                    field.setAttribute('data-upload-enabled', 'true');
                    field.disabled = true;
                }
            });
            uploader.start();
        });
        window.addEventListener('beforeunload', function (event) {
            if (busy && !completed) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
        uploader.init();
    });
    </script>
</body>
</html>
