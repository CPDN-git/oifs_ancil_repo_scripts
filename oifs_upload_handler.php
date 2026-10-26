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
require_once(PROJECT_INC . 'allowed_uploaders.inc');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Integrated, authenticated chunk receiver and metadata processor restored from
// 483abb4. Keep plupload_handler.php retired. Requires PHP 7.4+ and 64-bit integers.
const OIFS_CHUNK_BYTES = 100 * 1024 * 1024;
const OIFS_MAX_BYTES = 5 * 1024 * 1024 * 1024;

function upload_page_start(string $title): void {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="robots" content="noindex, nofollow"><title>' . oifs_ancil_h($title) . '</title>';
    echo '<link rel="stylesheet" href="/cpdn.css"></head><body>';
    echo '<img src="/img/OIFS_Home_logo.png" alt="OpenIFS@home" width="200">';
    echo '<h1>' . oifs_ancil_h($title) . '</h1>';
}

function upload_error(string $message, int $status = 400): void {
    throw new RuntimeException($message, $status);
}

function post_text(string $key, int $limit = 255): string {
    if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
        upload_error('Missing or invalid field: ' . $key);
    }
    $value = trim($_POST[$key]);
    if (!oifs_ancil_valid_text($value, $limit, true, $key === 'file_desc')) {
        upload_error('Invalid or overlong field: ' . $key);
    }
    return $value;
}

function post_number(string $key, int $minimum, int $maximum): int {
    $value = post_text($key, 20);
    if (!preg_match('/\A[0-9]+\z/', $value)) {
        upload_error('Invalid number: ' . $key);
    }
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false || $number < $minimum || $number > $maximum) {
        upload_error('Number out of range: ' . $key);
    }
    return $number;
}

function upload_filename(string $name): string {
    // Keep the original name for the Python backend, but never accept a path.
    if (strlen($name) > 100
        || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._ -]*\.(tgz|zip|nml)\z/i', $name)) {
        upload_error('Use a .tgz, .zip or .nml filename containing letters, numbers, spaces, dots, underscores or hyphens.');
    }
    return $name;
}

function private_directory(string $path): void {
    if (is_link($path)) {
        upload_error('Upload storage is unavailable.', 503);
    }
    if (!is_dir($path) && !mkdir($path, 0700) && !is_dir($path)) {
        upload_error('Cannot create upload storage.', 503);
    }
    if (!is_writable($path)) {
        upload_error('Upload storage is not writable.', 503);
    }
    $mode = fileperms($path);
    if ($mode === false || ($mode & 0077) !== 0) {
        upload_error('Private upload storage must be accessible only to its owner.', 503);
    }
}

function write_record(string $directory, array $record): void {
    $json = json_encode($record, JSON_THROW_ON_ERROR);
    $temporary = $directory . '/record.new';
    if (file_put_contents($temporary, $json) !== strlen($json)
        || !rename($temporary, $directory . '/record.json')) {
        upload_error('Cannot save upload state.', 500);
    }
}

function read_record(string $directory): ?array {
    $path = $directory . '/record.json';
    if (!file_exists($path)) {
        return null;
    }
    $json = file_get_contents($path);
    if ($json === false) {
        upload_error('Cannot read upload state.', 500);
    }
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

function chunk_length(int $index, int $size): int {
    return min(OIFS_CHUNK_BYTES, $size - $index * OIFS_CHUNK_BYTES);
}

// Store each chunk separately: out-of-order requests and identical retries are safe.
// The caller holds this upload's lock throughout all state and file operations.
function receive_chunk(string $directory, int $user_id): array {
    $name = upload_filename(post_text('name', 100));
    $size = post_number('upload_size', 1, OIFS_MAX_BYTES);
    $count = (int) ceil($size / OIFS_CHUNK_BYTES);
    $index = isset($_POST['chunk']) ? post_number('chunk', 0, $count - 1) : 0;
    $posted_count = isset($_POST['chunks']) ? post_number('chunks', 1, 52) : 1;
    if ($posted_count !== $count) {
        upload_error('Incorrect chunk count.');
    }
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK
        || !isset($file['tmp_name']) || !is_string($file['tmp_name'])
        || !is_uploaded_file($file['tmp_name'])) {
        upload_error('The chunk was not uploaded successfully. Check server upload limits.');
    }
    if (filesize($file['tmp_name']) !== chunk_length($index, $size)) {
        upload_error('Incorrect chunk size.');
    }
    $record = read_record($directory);
    if ($record === null) {
        $record = ['user_id' => $user_id, 'name' => $name, 'size' => $size,
            'chunks' => $count, 'state' => 'uploading', 'created' => time()];
        write_record($directory, $record);
    }
    if ($record['user_id'] !== $user_id || $record['name'] !== $name
        || $record['size'] !== $size || $record['state'] !== 'uploading') {
        upload_error('This upload cannot accept these chunks.', 409);
    }
    $target = $directory . '/chunk-' . $index;
    if (is_link($target)) {
        upload_error('Invalid upload storage.', 500);
    }
    if (file_exists($target)) {
        $existing = hash_file('sha256', $target);
        $incoming = hash_file('sha256', $file['tmp_name']);
        if ($existing === false || $incoming === false || $existing !== $incoming) {
            upload_error('A retry contains different data.', 409);
        }
    } elseif (!move_uploaded_file($file['tmp_name'], $target)) {
        upload_error('Cannot store the uploaded chunk.', 500);
    }
    $complete = true;
    for ($i = 0; $i < $count; $i++) {
        $part = $directory . '/chunk-' . $i;
        if (!is_file($part) || is_link($part) || filesize($part) !== chunk_length($i, $size)) {
            $complete = false;
            break;
        }
    }
    return ['complete' => $complete];
}

function validated_metadata(): array {
    $creator = post_text('created_by', 80);
    $description = post_text('file_desc', 8000);
    $version = post_text('model_version', 5);
    $type = post_text('ancil_type', 25);
    if ($creator === '' || $description === '' || !isset(oifs_ancil_model_grids()[$version])
        || !in_array($type, ['ic_ancil', 'ifsdata', 'climate_data', 'fullpos_namelist'], true)) {
        upload_error('Complete all required fields with valid values.');
    }
    $experiment = '';
    $analysis = '0';
    $subtype = '0';
    $grid = '0';
    if ($type === 'ic_ancil') {
        $experiment = post_text('exptid', 4);
        $analysis = post_text('starting_analysis', 20);
        if (!preg_match('/\A[A-Za-z0-9]{4}\z/', $experiment)
            || !in_array($analysis, ['Operational', 'ERA5', 'ERA-Interim', 'Other'], true)) {
            upload_error('Enter a four-character experiment ID and select a starting analysis.');
        }
    }
    if ($type === 'ifsdata') {
        $subtype = post_text('sub_type', 21);
        if (!in_array($subtype, ['GHG_files', 'other_radiation_files', 'SO4_files'], true)) {
            upload_error('Select an ifsdata subtype.');
        }
    }
    if ($type === 'ifsdata' || $type === 'climate_data') {
        $grid = post_text('grid_hres_' . $version, 8);
        $grids = oifs_ancil_model_grids()[$version];
        if (!in_array($grid, $grids, true)) {
            upload_error('Select a grid supported by the model version.');
        }
    }
    return [$creator, $version, $experiment, $analysis, $type, $subtype, $grid, $description];
}

// Assemble only verified chunks. Do not extract archives in the web process.
function assemble_upload(string $directory, array $record, string $type): array {
    $extension = strtolower(pathinfo($record['name'], PATHINFO_EXTENSION));
    $expected = $type === 'ic_ancil' ? 'tgz' : ($type === 'fullpos_namelist' ? 'nml' : 'zip');
    if ($extension !== $expected) {
        upload_error('The file extension does not match the selected file type.');
    }
    $path = $directory . '/payload';
    $output = fopen($path, 'xb');
    if ($output === false) {
        upload_error('Cannot create the assembled file. Start a new upload.', 500);
    }
    try {
        for ($i = 0; $i < $record['chunks']; $i++) {
            $part = $directory . '/chunk-' . $i;
            if (is_link($part) || !is_file($part)
                || filesize($part) !== chunk_length($i, $record['size'])) {
                upload_error('The upload is incomplete. Start a new upload.', 409);
            }
            $input = fopen($part, 'rb');
            if ($input === false) {
                upload_error('Cannot read an uploaded chunk.', 500);
            }
            try {
                if (stream_copy_to_stream($input, $output) !== chunk_length($i, $record['size'])) {
                    upload_error('Cannot assemble the uploaded file.', 500);
                }
            } finally {
                fclose($input);
            }
        }
        if (!fflush($output)) {
            upload_error('Cannot flush the assembled file.', 500);
        }
    } finally {
        fclose($output);
    }
    clearstatcache(true, $path);
    if (filesize($path) !== $record['size']) {
        upload_error('The assembled file size is incorrect.', 500);
    }
    $prefix = file_get_contents($path, false, null, 0, 8192);
    if ($prefix === false || ($expected === 'tgz' && substr($prefix, 0, 2) !== "\x1f\x8b")
        || ($expected === 'zip' && substr($prefix, 0, 4) !== "PK\x03\x04")
        || ($expected === 'nml' && strpos($prefix, "\0") !== false)) {
        upload_error('The file does not have the expected format.');
    }
    $md5 = hash_file('md5', $path);
    $sha256 = hash_file('sha256', $path);
    if ($md5 === false || $sha256 === false) {
        upload_error('Cannot checksum the assembled file.', 500);
    }
    return [$path, $md5, $sha256];
}

// No shell interpolation. Drain both pipes to avoid deadlocks and bound output.
function run_backend(array $arguments, int $timeout): void {
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'],
        2 => ['pipe', 'w']], $pipes, __DIR__);
    if (!is_resource($process)) {
        upload_error('Cannot start the ancillary backend.', 503);
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $started = microtime(true);
    $output = '';
    $exit = -1;
    $timed_out = false;
    try {
        while (true) {
            foreach ([1, 2] as $index) {
                $data = stream_get_contents($pipes[$index], 65536);
                if ($data === false) {
                    upload_error('Cannot read ancillary backend output.', 500);
                }
                $output .= substr($data, 0, max(0, 65536 - strlen($output)));
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if (microtime(true) - $started >= $timeout) {
                $timed_out = true;
                proc_terminate($process);
                usleep(200000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(10000);
        }
    } finally {
        // Also stop the immediate child if reading its output raises an exception.
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process);
            usleep(200000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
        }
        foreach ([1, 2] as $index) {
            $tail = stream_get_contents($pipes[$index], 65536);
            if (is_string($tail)) {
                $output .= substr($tail, 0, max(0, 65536 - strlen($output)));
            }
            fclose($pipes[$index]);
        }
        $closed_exit = proc_close($process);
    }
    if ($output !== '') {
        // Backend diagnostics can contain internal paths: keep them in server logs.
        error_log('OpenIFS backend output (bounded): ' . json_encode(substr($output, 0, 8192), JSON_INVALID_UTF8_SUBSTITUTE));
    }
    if ($timed_out || ($exit < 0 ? $closed_exit : $exit) !== 0) {
        upload_error('Ancillary processing failed or timed out. Ask the administrator to check the logs before retrying.', 502);
    }
}

$chunk_request = isset($_POST['phase']) && $_POST['phase'] === 'chunk';
$upload_lock = null;
$backend_lock = null;
$directory = null;
$record = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        upload_error('POST is required.', 405);
    }
    if (!isset($allowed_uploaders) || !is_array($allowed_uploaders)) {
        upload_error('Uploader authorization configuration is unavailable.', 503);
    }
    $user = get_logged_in_user(false);
    if (!$user || !in_array($user->email_addr, $allowed_uploaders, true)) {
        upload_error('You are not authorized to upload ancillary files.', 403);
    }
    if (PHP_VERSION_ID < 70400 || PHP_INT_SIZE < 8) {
        upload_error('This upload handler requires PHP 7.4+ on a 64-bit build.', 503);
    }
    $phase = post_text('phase', 16);
    if (!in_array($phase, ['chunk', 'process'], true)) {
        upload_error('Invalid upload phase.');
    }
    if (!oifs_ancil_valid_text($user->name, 80)) {
        upload_error('Your account name exceeds the uploader field limit (80 characters).');
    }
    // BOINC-compatible CSRF, POST only, constant-time check and 24-hour lifetime.
    if (isset($_GET['tnow']) || isset($_GET['ttok'])) {
        upload_error('Submit form tokens in POST only.', 403);
    }
    $form_time = post_number('tnow', 1, time());
    $form_token = post_text('ttok', 32);
    if (time() - $form_time > 86400 || !preg_match('/\A[0-9a-f]{32}\z/', $form_token)
        || !hash_equals(md5($_POST['tnow'] . $user->authenticator), $form_token)) {
        upload_error('The form has expired. Reload it and start a new upload.', 403);
    }
    $id = post_text('upload_id', 32);
    $expires = post_number('upload_expires', time(), time() + 86400);
    $token = post_text('upload_token', 64);
    if (!preg_match('/\A[0-9a-f]{32}\z/', $id)
        || !preg_match('/\A[0-9a-f]{64}\z/', $token)
        || !hash_equals(hash_hmac('sha256', $user->id . ':' . $id . ':' . $expires,
            $user->authenticator), $token)) {
        upload_error('Invalid upload ticket.', 403);
    }
    // The locked Python backend reads the legacy incoming directory below.
    // Reject a mismatched site configuration rather than stage files elsewhere.
    $incoming = realpath(PROJECT_PATH . 'tmp_ancil_upload');
    $backend_incoming = realpath('/storage/www/cpdnboinc/tmp_ancil_upload');
    $backend_config = realpath('/storage/www/cpdnboinc/ancil_batch_user_config.xml');
    if ($incoming === false || !is_dir($incoming) || !is_writable($incoming)) {
        upload_error('The incoming upload directory is unavailable.', 503);
    }
    if ($incoming !== $backend_incoming || $backend_config === false
        || realpath(PROJECT_PATH . 'ancil_batch_user_config.xml') !== $backend_config) {
        upload_error('Site paths do not match the installed upload backend. Ask the administrator to review its configuration.', 503);
    }
    $storage = $incoming . '/.oifs_uploads';
    private_directory($storage);
    $directory = $storage . '/' . $id;
    private_directory($directory);
    $lock_path = $directory . '/lock';
    if (is_link($lock_path)) {
        upload_error('Invalid upload lock.', 500);
    }
    $upload_lock = fopen($lock_path, 'c');
    if ($upload_lock === false || !flock($upload_lock, LOCK_EX | LOCK_NB)) {
        upload_error('This upload is busy. Retry the request shortly.', 409);
    }
    if ($chunk_request) {
        $result = receive_chunk($directory, (int) $user->id);
        $result['upload_id'] = $id;
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode(['jsonrpc' => '2.0', 'result' => $result, 'id' => $id], JSON_THROW_ON_ERROR);
    } else {
        $metadata = validated_metadata();
        $record = read_record($directory);
        if ($record === null || $record['user_id'] !== (int) $user->id
            || $record['state'] !== 'uploading'
            || $record['name'] !== upload_filename(post_text('fileName', 100))) {
            upload_error('No matching unprocessed upload was found.', 409);
        }
        if (!isset($python_env, $oifs_repo_root) || !is_string($python_env) || $python_env === ''
            || !is_string($oifs_repo_root) || $oifs_repo_root === '') {
            upload_error('The ancillary backend configuration is incomplete.', 503);
        }
        $python = $python_env;
        $script = rtrim($oifs_repo_root, '/') . '/oifs_ancil_repo_scripts/oifs_file_upload_handler.py';
        // An optional timeout belongs in protected project configuration, never POST.
        $timeout = defined('OIFS_UPLOAD_TIMEOUT') ? OIFS_UPLOAD_TIMEOUT : 300;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 3600
            || !is_file($python) || !is_executable($python) || !is_file($script)
            || !is_readable($script)) {
            upload_error('The ancillary backend configuration is unavailable.', 503);
        }
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close', 'set_time_limit'] as $function) {
            if (!is_callable($function)) {
                upload_error('A required upload processing function is unavailable.', 503);
            }
        }
        // Account for assembly/checksums as well as Python's own timeout.
        if (!set_time_limit($timeout + 600)) {
            upload_error('Cannot set the processing time limit.', 503);
        }
        $backend_lock_path = $incoming . '/.oifs-processing.lock';
        if (is_link($backend_lock_path)) {
            upload_error('Invalid backend lock.', 500);
        }
        $backend_lock = fopen($backend_lock_path, 'c');
        if ($backend_lock === false || !flock($backend_lock, LOCK_EX | LOCK_NB)) {
            upload_error('Another ancillary file is being processed. Submit this form again shortly.', 409);
        }
        $destination = $incoming . '/' . $record['name'];
        if (file_exists($destination) || is_link($destination)) {
            upload_error('An incoming file with this name already exists. Ask the administrator to review it.', 409);
        }
        [$payload, $md5, $sha256] = assemble_upload($directory, $record, $metadata[4]);
        // A failed/interrupted backend can have side effects. Never rerun it automatically.
        $record['state'] = 'processing';
        write_record($directory, $record);
        if (!rename($payload, $destination)) {
            upload_error('Cannot publish the incoming file.', 500);
        }
        $arguments = [$python, $script, '--', $metadata[0], $user->name, $metadata[1],
            $metadata[2], $metadata[3], $metadata[4], $metadata[5], $metadata[6],
            $metadata[7], $record['name']];
        run_backend($arguments, $timeout);
        $record['state'] = 'processed';
        $record['md5'] = $md5;
        $record['sha256'] = $sha256;
        write_record($directory, $record);
        // Leave the legacy incoming file to the backend's existing lifecycle.
        for ($i = 0; $i < $record['chunks']; $i++) {
            if (!unlink($directory . '/chunk-' . $i)) {
                error_log('OpenIFS: could not remove a completed chunk for upload ' . $id);
            }
        }
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        $name = htmlspecialchars($record['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        upload_page_start('Ancillary file processed');
        echo '<p>Uploaded file: ' . $name . '</p>';
        echo '<p>MD5: ' . $md5 . '</p><p>SHA-256: ' . $sha256 . '</p>';
        echo '<p><a href="/project/oifs_upload_form.php">Upload another file</a></p></body></html>';
    }
} catch (Throwable $error) {
    $status = $error instanceof RuntimeException ? $error->getCode() : 500;
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    error_log('OpenIFS upload ' . ($id ?? 'unknown') . ': ' . json_encode(substr($error->getMessage(), 0, 8192), JSON_INVALID_UTF8_SUBSTITUTE));
    $message = $status === 500 ? 'An upload operation failed. Ask the administrator to check the logs.' : $error->getMessage();
    if (is_array($record) && ($record['state'] ?? '') === 'processing' && is_string($directory)) {
        try {
            $record['state'] = 'failed';
            write_record($directory, $record);
        } catch (Throwable $state_error) {
            error_log('OpenIFS: could not persist failed upload state.');
        }
    }
    http_response_code($status);
    header('Cache-Control: no-store');
    if ($chunk_request) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => $status,
            'message' => $message], 'id' => null]);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        upload_page_start('Upload error');
        echo '<p>';
        echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '</p><p><a href="/project/oifs_upload_form.php">Return to the upload form</a></p></body></html>';
    }
} finally {
    foreach ([$backend_lock, $upload_lock] as $lock) {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
