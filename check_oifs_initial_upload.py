#!/usr/bin/env python3
"""Check an initial-condition upload without registering or publishing files.

Run with the production Python interpreter and PHP worker account. Archive
members are read and selected GRIB files are copied into an automatically
removed temporary directory. Database queries are SELECT/SHOW only. Credentials
and data rows are not printed. Nothing is written in the production input or
ancillary repository directories.

Copy the preflight script to /tmp/ on the server, then run it as the actual PHP worker account:

  sudo -u ACTUAL_PHP_WORKER \
  /home/boinc/miniconda3/envs/py3new/bin/python -B \
  /tmp/check_oifs_initial_upload.py \
  --archive /path/to/trial-files.tgz \
  --exptid AB12

Replace the account, archive path and experiment ID. The default --scripts root
is /home/boinc/cpdnboinc and selects its oifs_ancil_repo_scripts checkout; pass an
explicit --scripts path for another installation.
The script checks dependencies, archive layout, database schema/grants, 
destination collisions and the backend’s actual GRIB metadata parsing. 
It performs no database writes or repository changes; temporary GRIB copies 
are removed afterward.


"""

import argparse
import importlib.util
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import sys
import tarfile
import tempfile
from datetime import datetime

COLUMNS = {
    'file_name', 'created_by', 'uploaded_by', 'description', 'ancil_type',
    'ancil_sub_type', 'model_version_number', 'exptid', 'starting_analysis',
    'analysis_perturbation_number', 'start_date', 'end_date', 'grid_type',
    'spectral_horizontal_resolution', 'gridpoint_horizontal_resolution',
    'vertical_resolution', 'md5sum', 'url',
}


def require_access(path, mode):
    if not path.exists() or not os.access(path, mode):
        raise RuntimeError('Missing or inaccessible path: ' + str(path))


def inspect_archive(archive, experiment, maximum_bytes):
    """Validate layout and identify the four GRIB files in each analysis group."""
    required = {'ICMSH'+experiment+'INIT', 'ICMGG'+experiment+'INIT',
                'ICMGG'+experiment+'INIUA', 'ICMCL'+experiment+'INIT'}
    groups = {}
    seen = set()
    layouts = {}
    total = 0
    for member in archive:
        path = PurePosixPath(member.name)
        parts = path.parts
        if path.is_absolute() or '..' in parts or not parts or parts[0] != experiment:
            raise RuntimeError('Unexpected or unsafe archive path: ' + member.name)
        if not member.isdir() and not member.isfile():
            raise RuntimeError('Archive links/special files need review: ' + member.name)
        if member.isdir():
            continue
        if path in seen:
            raise RuntimeError('Duplicate archive member: ' + member.name)
        seen.add(path)
        total += member.size
        if total > maximum_bytes:
            raise RuntimeError('Declared archive size exceeds the preflight limit.')
        if len(parts) > 2 and parts[2] == 'ecmwf':
            continue  # The current upload backend discards this directory.
        if len(parts) not in (3, 4):
            raise RuntimeError('Unexpected initial-file layout: ' + member.name)
        date = parts[1]
        if not re.fullmatch(r'[0-9]{10}', date):
            raise RuntimeError('Invalid start-date directory: ' + date)
        datetime.strptime(date, '%Y%m%d%H')
        analysis = parts[2] if len(parts) == 4 else '00'
        if not re.fullmatch(r'[0-9]{1,3}', analysis):
            raise RuntimeError('Analysis directory needs review: ' + analysis)
        key = (date, analysis)
        layout = len(parts)
        if key in layouts and layouts[key] != layout:
            raise RuntimeError('Mixed implicit/explicit analysis directories: ' + date)
        layouts[key] = layout
        files = groups.setdefault(key, {})
        if parts[-1] in required:
            files[parts[-1]] = member
    if not groups:
        raise RuntimeError('No initial-condition analysis groups found.')
    for key, files in groups.items():
        missing = required - files.keys()
        if missing:
            raise RuntimeError('Missing GRIB files for ' + str(key) + ': ' + ', '.join(sorted(missing)))
    print('Archive layout OK:', len(groups), 'analysis groups;', total, 'declared bytes')
    return groups


def check_database(backend, groups, experiment, repository):
    """Check schema, privileges and output-name collisions using read-only SQL."""
    db = backend.connect_database()
    try:
        cursor = db.cursor(backend.pymysql.cursors.DictCursor)
        try:
            cursor.execute('SELECT 1')
            cursor.fetchone()
            cursor.execute('SHOW COLUMNS FROM oifs_ancil_files')
            columns = cursor.fetchall()
            missing = COLUMNS - {column['Field'] for column in columns}
            if missing:
                raise RuntimeError('Missing database columns: ' + ', '.join(sorted(missing)))
            extra_required = [column['Field'] for column in columns
                              if column['Field'] not in COLUMNS and column['Null'] == 'NO'
                              and column['Default'] is None
                              and 'auto_increment' not in column['Extra']]
            if extra_required:
                raise RuntimeError('Insert omits required columns: ' + ', '.join(extra_required))
            cursor.execute('SHOW TABLE STATUS WHERE Name = %s', ('oifs_ancil_files',))
            table = cursor.fetchone()
            if table is None:
                raise RuntimeError('Ancillary database table was not found.')
            engine = table['Engine']
            print('Database schema OK; table engine:', engine)
            if engine.upper() != 'INNODB':
                raise RuntimeError('Review rollback support for this table engine before the trial.')
            cursor.execute('SHOW GRANTS')
            print('Review INSERT permission in these grants (account/authentication details omitted):')
            for row in cursor.fetchall():
                for grant in row.values():
                    parts = re.split(r'\s+TO\s+', grant, maxsplit=1, flags=re.IGNORECASE)
                    print(' ', parts[0] if len(parts) == 2 else 'Grant format needs private administrator review.')
            for date, analysis in groups:
                filename = 'ic_'+experiment+'_'+date+'_'+analysis+'.zip'
                target = repository / 'ic_ancil' / experiment / date / analysis / filename
                if target.exists() or target.is_symlink():
                    raise RuntimeError('Repository output already exists: ' + str(target))
                ancestor = target.parent
                while not ancestor.exists():
                    ancestor = ancestor.parent
                require_access(ancestor, os.R_OK | os.W_OK | os.X_OK)
                cursor.execute('SELECT COUNT(*) AS matches FROM oifs_ancil_files WHERE file_name = %s', (filename,))
                if cursor.fetchone()['matches']:
                    raise RuntimeError('Database file_name already exists: ' + filename)
            print('No output-name collisions found; existing repository ancestors are accessible.')
        finally:
            cursor.close()
    finally:
        db.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--scripts', type=Path, default=Path('/home/boinc/cpdnboinc'),
                        help='Parent of the oifs_ancil_repo_scripts checkout (or the checkout itself).')
    parser.add_argument('--incoming', type=Path, default=Path('/storage/www/cpdnboinc/tmp_ancil_upload'))
    parser.add_argument('--repository', type=Path, default=Path('/storage/cpdn_ancil_files/oifs_ancil_files'))
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--exptid', required=True)
    parser.add_argument('--max-unpacked-gib', type=int, default=10,
                        help='Preflight inspection limit; default 10 GiB. Does not set a backend limit.')
    args = parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9]{4}', args.exptid) or args.max_unpacked_gib < 1:
        parser.error('Use a four-character experiment ID and a positive size limit.')
    # Keep --scripts compatible with an explicit backend checkout while defaulting
    # to the authoritative parent of the CPDN repositories.
    if not (args.scripts/'oifs_file_upload_handler.py').is_file():
        args.scripts = args.scripts/'oifs_ancil_repo_scripts'
    sys.dont_write_bytecode = True
    for path in (args.scripts/'oifs_file_upload_handler.py', args.scripts/'eccodes_config.py', args.archive):
        require_access(path, os.R_OK)
    for path in (args.incoming, args.repository/'ic_ancil'):
        require_access(path, os.R_OK | os.W_OK | os.X_OK)
    if not args.incoming.is_dir() or not (args.repository/'ic_ancil').is_dir():
        raise RuntimeError('Incoming and ic_ancil paths must be existing directories.')
    private = args.incoming/'.oifs_uploads'
    if private.is_symlink():
        raise RuntimeError('Private upload storage must not be a symlink.')
    if private.exists():
        require_access(private, os.R_OK | os.W_OK | os.X_OK)
        if not private.is_dir() or private.stat().st_mode & 0o077:
            raise RuntimeError('Existing private upload storage must exclude group/other access.')
    import pymysql
    print('Python:', sys.version.split()[0], '; interpreter:', sys.executable)
    print('Packages: PyMySQL', getattr(pymysql, '__version__', 'installed'))
    sys.path.insert(0, str(args.scripts.resolve()))
    backend_path = args.scripts/'oifs_file_upload_handler.py'
    # Refuse the old module, which opened a database connection during import.
    if 'def connect_database(' not in backend_path.read_text():
        raise RuntimeError('Deploy the current backend before running this preflight.')
    spec = importlib.util.spec_from_file_location('oifs_preflight_backend', backend_path)
    backend = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(backend)
    backend.grib_ls_path, definitions = backend.discover_eccodes()
    print('ecCodes:', backend.grib_ls_path, '; definitions:', definitions)
    with tarfile.open(args.archive, 'r:gz') as archive:
        groups = inspect_archive(archive, args.exptid, args.max_unpacked_gib*1024**3)
        check_database(backend, groups, args.exptid, args.repository)
        selected_bytes = sum(member.size for files in groups.values() for member in files.values())
        if shutil.disk_usage(tempfile.gettempdir()).free < selected_bytes + 64*1024**2:
            raise RuntimeError('Insufficient temporary space to inspect the selected GRIB files.')
        with tempfile.TemporaryDirectory(prefix='oifs-grib-preflight-') as temporary:
            for number, ((date, analysis), files) in enumerate(groups.items()):
                directory = Path(temporary)/str(number)
                directory.mkdir()
                for name, member in files.items():
                    stream = archive.extractfile(member)
                    if stream is None:
                        raise RuntimeError('Cannot read archive member: ' + member.name)
                    with stream, (directory/name).open('xb') as destination:
                        shutil.copyfileobj(stream, destination, 1024*1024)
                    if (directory/name).stat().st_size != member.size:
                        raise RuntimeError('Incomplete archive member: ' + member.name)
                info = backend.get_grib_info(args.exptid, analysis, str(directory))
                if info['start_date'] != date:
                    raise RuntimeError('Directory date differs from GRIB metadata: ' + date)
                print('GRIB metadata OK:', date, analysis, info['grid_type'],
                      info['spectral_horizontal_resolution'], info['vertical_resolution'])
    print('Preflight passed. Confirm INSERT privileges, deployed PHP/web settings and public HTTPS routing before uploading.')
    print('No database records or production files were written; temporary GRIB copies were removed.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('PRECHECK FAILED:', error, file=sys.stderr)
        sys.exit(1)
