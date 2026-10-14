"""Upload SQL and failure tests using fake drivers and isolated subprocesses.

No real database, native ecCodes executable or production file is accessed.
"""

import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch
import warnings

BACKEND = Path(__file__).with_name('oifs_file_upload_handler.py')
CREATOR = "O'Reilly \\ research"
DESCRIPTION = "Climate étude 🌍\n'quoted' %s; DROP TABLE oifs_ancil_files; --"
COLUMNS = (
    'file_name', 'created_by', 'uploaded_by', 'description', 'ancil_type',
    'ancil_sub_type', 'model_version_number', 'exptid', 'starting_analysis',
    'analysis_perturbation_number', 'start_date', 'end_date', 'grid_type',
    'spectral_horizontal_resolution', 'gridpoint_horizontal_resolution',
    'vertical_resolution', 'md5sum', 'url',
)


def load_backend():
    """Stub unused optional imports; importing must not read DB configuration."""
    pymysql = types.ModuleType('pymysql')
    pymysql.__path__ = []
    converters = types.ModuleType('pymysql.converters')
    converters.conversions = {}
    pymysql.converters = converters
    pymysql.cursors = types.SimpleNamespace(DictCursor=object())
    pymysql.connect = Mock(side_effect=AssertionError('Unexpected DB access'))
    replacements = {'pymysql': pymysql, 'pymysql.converters': converters,
                    'numpy': types.ModuleType('numpy')}
    spec = importlib.util.spec_from_file_location('_upload_backend_fixture', BACKEND)
    backend = importlib.util.module_from_spec(spec)
    with patch.dict(sys.modules, replacements), warnings.catch_warnings():
        warnings.simplefilter('ignore', SyntaxWarning)
        with patch('xml.etree.ElementTree.parse', side_effect=AssertionError('Unexpected config access')):
            spec.loader.exec_module(backend)
    pymysql.connect.assert_not_called()
    return backend


# This runner executes the real entry point in a child with every external
# dependency stubbed. An unhandled failure must produce a nonzero process exit.
CLI_RUNNER = r'''
import atexit
import importlib.util
import json
from pathlib import Path
import sys
import types

backend_path, scenario, kind, record_path = sys.argv[1:5]
sys.path.insert(0, str(Path(backend_path).parent))
state = {'executions': [], 'commits': 0, 'rollbacks': 0, 'moved': [],
         'removed': [], 'rmtree': [], 'archives': [], 'connections': 0,
         'cursor_closed': False, 'db_closed': False}
atexit.register(lambda: Path(record_path).write_text(json.dumps(state)))

class Cursor:
    def execute(self, query, parameters):
        state['executions'].append([query, parameters])
        if scenario in ('execute-fail', 'implicit-execute-fail') or (scenario == 'second-execute-fail' and len(state['executions']) == 2):
            raise RuntimeError('simulated insert failure')
        return 1
    def close(self):
        state['cursor_closed'] = True
        if scenario == 'cursor-close-fail':
            raise RuntimeError('simulated cursor close failure')

class Database:
    def cursor(self, kind):
        if scenario == 'cursor-fail':
            raise RuntimeError('simulated cursor creation failure')
        return Cursor()
    def commit(self):
        state['commits'] += 1
        if scenario in ('commit-fail', 'implicit-commit-fail'):
            raise RuntimeError('simulated commit failure')
    def rollback(self):
        state['rollbacks'] += 1
        if scenario == 'rollback-fail':
            raise RuntimeError('simulated rollback failure')
    def close(self):
        state['db_closed'] = True
        if scenario == 'db-close-fail':
            raise RuntimeError('simulated database close failure')

def connect(**kwargs):
    state['connections'] += 1
    if scenario == 'connect-fail':
        raise RuntimeError('simulated connection failure')
    return Database()

pymysql = types.ModuleType('pymysql')
pymysql.__path__ = []
converters = types.ModuleType('pymysql.converters')
converters.conversions = {}
pymysql.converters = converters
pymysql.cursors = types.SimpleNamespace(DictCursor=object())
pymysql.connect = connect
sys.modules.update({'pymysql': pymysql, 'pymysql.converters': converters, 'numpy': types.ModuleType('numpy')})
spec = importlib.util.spec_from_file_location('upload_cli_fixture', backend_path)
backend = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backend)
xml = '<config><ancil_db_host>fixture</ancil_db_host><ancil_user>fixture</ancil_user><ancil_passwd>fixture</ancil_passwd><ancil_db_name>fixture</ancil_db_name></config>'
tree = backend.ET.ElementTree(backend.ET.fromstring(xml))
backend.ET.parse = lambda path: tree
backend.discover_eccodes = lambda: ('/fixture/grib_ls', '/fixture/definitions')
backend.subprocess.check_output = lambda *args, **kwargs: '0123456789abcdef  fixture\n'
backend.zipfile.ZipFile = lambda *args, **kwargs: types.SimpleNamespace(testzip=lambda: None)
backend.tarfile.open = lambda *args, **kwargs: types.SimpleNamespace(extractall=lambda **kwargs: None, close=lambda: None)
backend.os.path.exists = lambda path: True
backend.os.remove = lambda path: state['removed'].append(path)
backend.shutil.rmtree = lambda path: state['rmtree'].append(path)
backend.shutil.make_archive = lambda path, *args: state['archives'].append(path)
def move(source, destination):
    if scenario in ('move-fail', 'rollback-fail'):
        raise OSError('simulated move failure')
    state['moved'].append([source, destination])
backend.shutil.move = move
root = '/storage/www/cpdnboinc/tmp_ancil_upload/AB12'
analyses = [] if scenario.startswith('implicit-') else ['00', '01']
backend.os.walk = lambda *args, **kwargs: iter([(root, ['2026010100'], []), (root+'/2026010100', analyses, [])])
backend.get_grib_info = lambda expt, analysis, directory: {
    'exptid': expt, 'analysis_number': analysis, 'start_date': '2026010100',
    'end_date': '2026010200', 'grid_type': 'Tl', 'spectral_horizontal_resolution': '159',
    'gridpoint_horizontal_resolution': 'N80', 'vertical_resolution': 'L91'}
creator = "O'Reilly \\ research"
description = "Climate étude 🌍\n'quoted' %s; DROP TABLE oifs_ancil_files; --"
name = 'fixture.tgz' if kind == 'ic_ancil' else ('fixture.nml' if kind == 'fullpos_namelist' else 'fixture.zip')
sys.argv = [backend_path, creator, 'Researcher "quoted"', '43r3', 'AB12',
            'ERA5', kind, 'GHG_files' if kind == 'ifsdata' else '0',
            '0' if kind == 'fullpos_namelist' else 'Tl159', description, name]
if scenario == 'help':
    sys.argv = [backend_path, '--help']
backend.main()
'''


class UploadDatabaseTests(unittest.TestCase):
    def setUp(self):
        self.backend = load_backend()
        self.db = Mock()
        self.cursor = Mock()
        self.backend.db = self.db
        self.backend.cursor = self.cursor

    def arguments(self, kind='ic_ancil'):
        return types.SimpleNamespace(
            created_by=CREATOR, uploaded_by='Researcher "quoted"',
            file_desc=DESCRIPTION, ancil_type=kind, sub_type='0',
            model_version='43r3', starting_analysis='ERA5', exptid='AB12',
            ulfile='fixture.nml', grid_hres='0',
        )

    def test_initial_parameters_round_trip_without_sql_interpolation(self):
        grib = dict(exptid='AB12', analysis_number='00', start_date='2026010100',
                    end_date='2026010200', grid_type='Tl', spectral_horizontal_resolution='159',
                    gridpoint_horizontal_resolution='N80', vertical_resolution='L91')
        query, values = self.backend.get_query(self.arguments(), grib, 'fixture.zip', 'abcdef')
        self.assertEqual(query.count('%s'), 18)
        self.assertEqual(len(values), 18)
        self.assertTrue(values[-1].startswith('https://main.cpdn.org/oifs_ancil_files/'))
        self.assertNotIn(CREATOR, query)
        self.assertNotIn(DESCRIPTION, query)
        with contextlib.closing(sqlite3.connect(':memory:')) as db:
            db.execute('CREATE TABLE oifs_ancil_files (' + ', '.join(name+' TEXT' for name in COLUMNS) + ')')
            # SQLite's DB-API uses ?, while PyMySQL uses %s for value binding.
            db.execute(query.replace('%s', '?'), values)
            row = db.execute('SELECT * FROM oifs_ancil_files').fetchone()
            self.assertEqual(row, values)
            self.assertEqual(row[1], CREATOR)
            self.assertEqual(row[3], DESCRIPTION)

    def test_ordinary_parameters_round_trip_without_sql_interpolation(self):
        with patch.object(self.backend.subprocess, 'check_output', return_value='abcdef fixture.nml'), \
             patch.object(self.backend.shutil, 'move'), contextlib.redirect_stdout(io.StringIO()):
            self.backend.upload_file(self.arguments('fullpos_namelist'))
        query, values = self.cursor.execute.call_args.args
        self.assertEqual(query.count('%s'), 11)
        self.assertEqual(len(values), 11)
        self.assertNotIn(CREATOR, query)
        self.assertNotIn(DESCRIPTION, query)
        with contextlib.closing(sqlite3.connect(':memory:')) as db:
            db.execute('CREATE TABLE oifs_ancil_files (' + ', '.join(name+' TEXT' for name in COLUMNS) + ')')
            db.execute(query.replace('%s', '?'), values)
            row = db.execute('SELECT created_by, description FROM oifs_ancil_files').fetchone()
            self.assertEqual(row, (CREATOR, DESCRIPTION))
        self.db.commit.assert_called_once_with()
        self.db.rollback.assert_not_called()

    def run_cli(self, scenario, kind='fullpos_namelist'):
        with tempfile.TemporaryDirectory(prefix='oifs-db-test-') as directory:
            record = Path(directory)/'result.json'
            result = subprocess.run(
                [sys.executable, '-B', '-W', 'ignore::SyntaxWarning', '-c', CLI_RUNNER,
                 str(BACKEND), scenario, kind, str(record)],
                capture_output=True, text=True, timeout=10,
            )
            self.assertTrue(record.is_file(), result.stderr)
            return result, json.loads(record.read_text())

    def assert_failure(self, result):
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertNotIn('Finished!', result.stdout)

    def test_all_upload_types_succeed_with_bound_values(self):
        for kind in ('ic_ancil', 'ifsdata', 'climate_data', 'fullpos_namelist'):
            with self.subTest(kind=kind):
                result, state = self.run_cli('success', kind)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn('Finished!', result.stdout)
                self.assertTrue(state['cursor_closed'])
                self.assertTrue(state['db_closed'])
                for query, values in state['executions']:
                    self.assertEqual(query.count('%s'), len(values))
                    self.assertEqual(values[1], CREATOR)
                    self.assertEqual(values[3], DESCRIPTION)
                    self.assertTrue(values[-1].startswith('https://main.cpdn.org/oifs_ancil_files/'))
                    self.assertNotIn(DESCRIPTION, query)

    def test_ordinary_insert_failure_is_nonzero_and_does_not_move_file(self):
        result, state = self.run_cli('execute-fail')
        self.assert_failure(result)
        self.assertEqual(state['rollbacks'], 1)
        self.assertEqual(state['moved'], [])
        self.assertEqual(state['commits'], 0)
        self.assertTrue(state['cursor_closed'] and state['db_closed'])

    def test_initial_insert_failure_stops_and_preserves_input(self):
        for scenario in ('execute-fail', 'implicit-execute-fail'):
            with self.subTest(scenario=scenario):
                result, state = self.run_cli(scenario, 'ic_ancil')
                self.assert_failure(result)
                self.assertEqual(len(state['executions']), 1)
                self.assertEqual(len(state['archives']), 1)
                self.assertEqual(state['rollbacks'], 1)
                self.assertEqual(state['removed'], [])
                self.assertEqual(state['rmtree'], [])

    def test_initial_second_insert_failure_stops_after_prior_commit(self):
        result, state = self.run_cli('second-execute-fail', 'ic_ancil')
        self.assert_failure(result)
        self.assertEqual(state['commits'], 1)
        self.assertEqual(state['rollbacks'], 1)
        self.assertEqual(state['removed'], [])
        self.assertEqual(state['rmtree'], [])

    def test_both_commit_failure_paths_are_nonzero(self):
        for scenario, kind in [('commit-fail', 'ic_ancil'), ('implicit-commit-fail', 'ic_ancil'), ('commit-fail', 'fullpos_namelist')]:
            with self.subTest(scenario=scenario, kind=kind):
                result, state = self.run_cli(scenario, kind)
                self.assert_failure(result)
                self.assertEqual(state['rollbacks'], 1)
                self.assertEqual(state['removed'], [])
                self.assertEqual(state['rmtree'], [])

    def test_file_move_failure_is_nonzero_and_rolls_back(self):
        result, state = self.run_cli('move-fail')
        self.assert_failure(result)
        self.assertEqual(state['rollbacks'], 1)
        self.assertEqual(state['commits'], 0)

    def test_rollback_failure_is_still_nonzero(self):
        result, state = self.run_cli('rollback-fail')
        self.assert_failure(result)
        self.assertEqual(state['rollbacks'], 1)
        self.assertIn('simulated rollback failure', result.stderr)
        self.assertIn('simulated move failure', result.stderr)

    def test_connection_failure_is_nonzero(self):
        result, state = self.run_cli('connect-fail')
        self.assert_failure(result)
        self.assertEqual(state['connections'], 1)
        self.assertEqual(state['executions'], [])

    def test_cursor_failure_closes_database_and_is_nonzero(self):
        result, state = self.run_cli('cursor-fail')
        self.assert_failure(result)
        self.assertTrue(state['db_closed'])

    def test_resource_close_failures_are_nonzero(self):
        for scenario in ('cursor-close-fail', 'db-close-fail'):
            with self.subTest(scenario=scenario):
                result, state = self.run_cli(scenario)
                self.assert_failure(result)
                self.assertTrue(state['db_closed'])

    def test_help_does_not_connect_to_database(self):
        result, state = self.run_cli('help')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state['connections'], 0)

    def test_initial_implicit_analysis_success(self):
        result, state = self.run_cli('implicit-analysis', 'ic_ancil')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(state['executions']), 1)
        self.assertEqual(state['executions'][0][1][9], '00')
        self.assertEqual(len(state['removed']), 1)
        self.assertEqual(len(state['rmtree']), 1)


if __name__ == '__main__':
    unittest.main()
