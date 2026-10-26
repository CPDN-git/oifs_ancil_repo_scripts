# OpenIFS ancillary repository scripts

This repository owns ancillary search/details pages, the upload form and integrated upload
handler, and their Python backends and diagnostic tools. These files stay here;
they are not being transferred to cpdn_webpages in this change.

## Website layout and shared dependencies

Deploy the four PHP pages to html/user/project, served at `/project/<filename>.php`.
Each loads html/project/cpdn_web_bootstrap.inc at page scope with an explanatory
comment. That bootstrap selects the BOINC html/user working directory, loads
batch_site_config.inc and defines the shared absolute include paths.

cpdn_webpages owns/deploys the bootstrap, host configuration, allowed_uploaders.inc,
html_table.class.php, shared stylesheets and logos. Deploy those dependencies first.
This repo owns oifs_ancil_web_common.inc, deployed to html/project before its pages;
it supplies escaping, bounded text validation, safe errors and ancillary database
configuration/connection helpers. Including it does not authenticate users or open
a database. All four entry pages explicitly require BOINC login and membership in
the current administrator-reviewed allowed_uploaders list. Search/details are no
longer public. Do not create a separate allowed_users list or restore old uploader lists.

Site configuration supplies project_path; the upload handler additionally uses
python_env and oifs_repo_root to locate
`<oifs_repo_root>/oifs_ancil_repo_scripts/oifs_file_upload_handler.py`. XML database
configuration stays at PROJECT_PATH/ancil_batch_user_config.xml. Host values and
repository/data locations remain separate; do not replace them with production
fallbacks. PHP error display/reporting remains controlled by the server.

## Deployment

Run do_deploy.sh only on the intended server as an authorized operator. Its arrays
contain the shared ancillary include and four PHP pages; it does not deploy Python,
install dependencies, modify tasks or copy the shared files owned by cpdn_webpages.
Sources resolve relative to the script; includes deploy before callers.

- Copy missing destinations and differing older copies.
- Skip identical, differing newer and differing equal-timestamp copies, reporting why.
- Stop before comparison/copying on every destination symlink, valid or broken;
  report its path/target and leave both untouched for manual resolution.
- Preserve comparison error diagnostics and stop at the first error. Earlier files
  may already have deployed; later files are not processed.

Deploy oifs_ancil_web_common.inc and the form/handler pair together. Browser URLs
stay /project/; CSS, logos and JavaScript retain root URLs. Keep
html/user/js/plupload.full.min.js under the project installation,
served as /js/plupload.full.min.js. The old plupload_handler.php remains deleted:
it is not an external dependency and must not be redeployed from a snapshot.
No live deployment or scheduler update is performed by source edits.

## Form and query safeguards

All ancillary pages require login plus uploader authorization and return no-store
responses. Search/details retain bound values, escaped output, restricted schemas
and safe logged failures. The search's hidden Batches used column and shading are
preserved; LIKE wildcards and main-site details links retain their existing meaning.
Search pagination is a future improvement; no arbitrary result cap is introduced.

The upload form supplies BOINC-compatible CSRF fields and a random upload ID with
a signed, user-bound, expiring ticket. The integrated handler checks login, uploader
authorization and both tokens on every chunk and processing POST, using constant-time
hash checks and a 24-hour validity window. No separate public receiver is used.

Scalar UTF-8 fields are bounded against the captured oifs_ancil_files schema:
filename 100 characters (simple ASCII basename), creator/uploader 80, model version
5 and experiment ID 4. Description has an application limit of 8000 characters,
below TEXT's UTF-8 byte capacity. Required subtypes/grids/initial-analysis choices
and extensions are checked server-side. The form/handler share the existing 40r1
and 43r3 model/grid choices through oifs_ancil_model_grids(); Tco159 is retained.

## Integrated upload restoration and file contract

The integrated pair introduced in 0f530e7 and present in 483abb4 is restored with
the current bootstrap, paths, authorization and validation. The old
plupload_handler.php was deliberately deleted in 629695d on 14 October 2026;
6d6077d on 16 October later replaced the pair with a flow that again depended on
that deleted endpoint. This restoration reconciles that regression rather than
bringing the insecure endpoint back. Original BOINC copyright/licence notices
are retained. The Plupload JavaScript library remains necessary for large files.

Both phases POST to /project/oifs_upload_handler.php:

| Phase | Browser sends | Handler action |
|---|---|---|
| chunk | phase=chunk; upload_size; signed upload_id/upload_expires/upload_token; tnow/ttok; Plupload's name, chunk/chunks and multipart file field. | Validate expected size/index/count and actual bytes; store separate chunks under the authenticated upload ticket; return JSON with matching upload_id and complete status. |
| process | phase=process; the same ticket and CSRF fields; fileName and enabled metadata controls. | Require the matching user/name/unprocessed record, validate metadata, assemble verified chunks and launch Python once. |

The browser uses 100 MiB chunks and a 5 GiB total limit; PHP enforces the same
limits (up to 52 chunks) on a 64-bit PHP 7.4+ build. Identical chunk retries are
accepted; conflicting retries, another user's ticket, missing chunks, wrong sizes,
unsafe filenames and duplicate processing are rejected. Only server-confirmed
completion triggers the metadata POST. Metadata is frozen during transfer and
re-enabled before submission, so required controls are actually included.

Private storage is `PROJECT_PATH/tmp_ancil_upload/.oifs_uploads/<upload_id>/`,
with owner-only directory permissions, record.json and per-upload locking. Assembly
streams chunks to a payload and checks size, basic file signature, MD5 and SHA-256;
the full multi-gigabyte file is not loaded into PHP memory. A shared processing
lock serializes backend calls, and a pre-existing incoming filename is not replaced.
The assembled file is placed at `PROJECT_PATH/tmp_ancil_upload/<original filename>`.
The final positional argument to Python is that basename, not the private chunk
path or an HTTP temporary filename. Python retains its existing move/delete lifecycle.
Checksums displayed on success describe the original uploaded file.

The configured python_env runs
`<oifs_repo_root>/oifs_ancil_repo_scripts/oifs_file_upload_handler.py` with these ten
positional fields, in the backend's existing argparse order:

| Position | Value / source |
|---|---|
| 1 | created_by from the form |
| 2 | uploaded_by from the authenticated BOINC account name |
| 3 | model_version from the form |
| 4 | exptid for initial files; empty for other types |
| 5 | starting_analysis for initial files; 0 for other types |
| 6 | ancil_type from the form |
| 7 | sub_type for ifsdata; 0 otherwise |
| 8 | selected `grid_hres_<model_version>` for ifsdata/climate_data; 0 otherwise |
| 9 | file_desc from the form |
| 10 | the original uploaded basename recorded during chunk receipt |

proc_open receives an argument array, without shell interpolation. A -- separator
prevents metadata beginning with a dash from being parsed as options. Disabled
irrelevant controls are intentionally absent from POST; validated_metadata() supplies
the defaults above rather than requiring missing fields. This preserves the Python
interface without modifying the locked backend.

The current Python backend hard-codes the production incoming directory and XML
configuration. PHP therefore compares the configured canonical staging/config paths
with /storage/www/cpdnboinc/tmp_ancil_upload and
/storage/www/cpdnboinc/ancil_batch_user_config.xml and rejects a mismatch. This is
a compatibility guard, not a fallback to production for an unsupported site.
Backend path configuration still needs its separate detailed review.

## Server checks and recovery

- Deploy the shared ancillary include before both updated pages. Keep the old
  /plupload_handler.php route absent/denied, including aliases or old root copies.
- Check /js/plupload.full.min.js, the page pair and shared CPDN includes are readable
  from their current URLs/paths. Both upload phases now use the same project handler.
- Incoming storage must be writable by the PHP worker, whose Python child needs
  access to the repository/database. .oifs_uploads directories must exclude group
  and other access. Configure the server to deny HTTP access to staging storage.
- Allow each 100 MiB chunk plus multipart overhead in PHP post_max_size,
  upload_max_filesize and proxy/server request limits. Keep PHP/FPM/proxy timeouts
  sufficient for upload requests and final assembly/processing; no 5 GiB single
  multipart request is required. Incoming disk needs at least chunks plus assembled
  file, with additional capacity for extracted files and generated ZIPs.
- Backend output capture is capped at 64 KiB, with an encoded 8 KiB log excerpt.
  Processing has a 300-second default backend timeout; protected configuration may
  define OIFS_UPLOAD_TIMEOUT as an integer from 1 to 3600 seconds. PHP requests an
  additional 600 seconds for assembly/checksums. Check whether real archives need
  a longer configured limit. This timeout terminates the immediate Python process,
  not a guarantee that all descendants or external side effects stop atomically.
- Successful uploads retain a processed record and remove completed chunks. Failed
  processing is marked failed where possible and is never automatically rerun with
  the same ticket. Inspect logs and repository/database state before retrying.
  A worker crash may leave processing state, which also blocks automatic retries.
- Establish operator-controlled retention for abandoned/failed chunks, records and
  leftover payloads. No automatic bulk deletion or live cleanup is introduced.

## Python checks and regression tests

| File | Role | External effects |
|---|---|---|
| test_eccodes_config.py | Isolated unittest regression coverage for ecCodes tool discovery, environment overrides, missing tools and failures. | Uses mocks/private temporary fixtures; no real database or native ecCodes invocation. |
| test_oifs_file_upload_handler.py | Regression coverage for SQL value binding, all upload types, failure exit codes, commit/rollback and resource cleanup. | Uses mocked dependencies, isolated subprocesses and in-memory SQLite/private temporary fixtures; no production uploads/database. |
| check_oifs_initial_upload.py | Operational preflight for a real initial-condition archive, permissions, schema/grants, output collisions and actual GRIB metadata parsing. | SELECT/SHOW queries, native ecCodes calls and temporary GRIB copies; no production database/repository writes. Requires an explicitly authorized operator run. |
| db_connection_test.py / oifs_connection_test.py | Standalone database connection diagnostics. | Access configured databases; not automatic checks or replacements for the regression tests. |

The test files and preflight are complementary. The preflight covers initial files
and real deployment conditions; it does not replace regression tests for ordinary
uploads or simulated failure paths. These programs are not run by do_deploy.sh or
by PHP as an automatic pre-upload gate. Do not run tests or the preflight merely
to validate PHP syntax; an operational preflight still accesses the real database.

The preflight --scripts default is /home/boinc/cpdnboinc. It selects that directory's
oifs_ancil_repo_scripts checkout; an explicit --scripts may instead name the
backend checkout itself. Pass --incoming/--repository for non-default storage and
use the configured Python environment and actual PHP worker account. These flags
affect preflight checks only: they do not reconfigure the production upload backend.

## Deferred backend review

Edits to oifs_file_upload_handler.py remain locked pending detailed review. PHP
now enforces chunk ownership itself; there is no outstanding external chunk-handler
dependency. The backend still needs archive member validation, link/traversal checks,
private extraction, unpacked-size limits, output collision handling, host/data-path
configuration and recovery from partial filesystem/database writes. The preflight
is not automatically enforced by the web flow and does not fix production extraction.

Python processing/metadata semantics are unchanged. Restoring authenticated transport
and serializing backend requests does not make filesystem/database changes atomic.
Use trusted archives pending that backend review; inspect failed operations before
any new upload attempt. No backend or shared server snapshot is edited here.

## Validation and ownership

Use php -l for changed pages/includes and bash -n for deployment syntax. Python
syntax can be checked without importing/executing it or creating bytecode. Syntax
checks and static field/path tracing do not establish live database, browser-upload
or ecCodes correctness. The inline JavaScript is syntax checked without execution.
Shared includes remain owned by cpdn_webpages; the ancillary helper, four pages,
backend and check/test programs remain owned here. Live functionality is reviewed
on the server by the operator. No production upload, backend, test suite or database
query is run as part of these source changes.
