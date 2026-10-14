"""Isolated discovery tests: no database, real ecCodes process or upload."""

import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from eccodes_config import discover_eccodes


class DiscoveryTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.binary = self.root / "bin"
        self.binary.mkdir()
        self.definitions = self.root / "share/eccodes/definitions"
        self.definitions.mkdir(parents=True)
        for name in ("grib_ls", "codes_info"):
            tool = self.binary / name
            tool.write_text("#!/bin/sh\nexit 0\n")
            tool.chmod(0o700)
        patches = [patch("eccodes_config.sys.prefix", str(self.root)),
                   patch.dict(os.environ, {}, clear=True),
                   patch("eccodes_config.shutil.which", return_value=None),
                   patch("eccodes_config.subprocess.check_output", return_value=str(self.definitions) + "\n")]
        self.which = patches[2].start()
        self.check_output = patches[3].start()
        patches[0].start()
        patches[1].start()
        for item in patches:
            self.addCleanup(item.stop)

    def test_interpreter_environment_and_matching_codes_info(self):
        tool, definitions = discover_eccodes()
        self.assertEqual(tool, str(self.binary / "grib_ls"))
        self.assertEqual(definitions, str(self.definitions))
        self.which.assert_not_called()
        self.check_output.assert_called_once_with(
            [str(self.binary / "codes_info"), "-d"], text=True,
            stderr=subprocess.PIPE, timeout=10,
        )
        self.assertEqual(os.environ["ECCODES_DEFINITION_PATH"], definitions)
        self.assertEqual(os.environ["GRIB_DEFINITION_PATH"], definitions)

    def test_path_fallback(self):
        (self.binary / "grib_ls").unlink()
        other = self.root / "other"
        other.mkdir()
        for name in ("grib_ls", "codes_info"):
            tool = other / name
            tool.write_text("#!/bin/sh\n")
            tool.chmod(0o700)
        self.which.return_value = str(other / "grib_ls")
        self.assertEqual(discover_eccodes()[0], str(other / "grib_ls"))
        self.assertEqual(self.check_output.call_args[0][0], [str(other / "codes_info"), "-d"])

    def test_missing_grib_ls(self):
        (self.binary / "grib_ls").unlink()
        with self.assertRaisesRegex(RuntimeError, "Cannot find grib_ls"):
            discover_eccodes()

    def test_environment_tool_not_executable(self):
        (self.binary / "grib_ls").chmod(0o600)
        with self.assertRaisesRegex(RuntimeError, "not executable"):
            discover_eccodes()
        self.which.assert_not_called()

    def test_explicit_custom_paths_are_preserved(self):
        custom = self.root / "custom definitions"
        custom.mkdir()
        value = str(custom) + os.pathsep + str(self.definitions)
        os.environ["ECCODES_DEFINITION_PATH"] = value
        self.assertEqual(discover_eccodes()[1], value)
        self.assertEqual(os.environ["GRIB_DEFINITION_PATH"], value)
        self.check_output.assert_not_called()

    def test_legacy_path_is_preserved(self):
        os.environ["GRIB_DEFINITION_PATH"] = str(self.definitions)
        self.assertEqual(discover_eccodes()[1], str(self.definitions))
        self.check_output.assert_not_called()

    def test_conflicting_paths(self):
        os.environ["GRIB_DEFINITION_PATH"] = str(self.definitions)
        os.environ["ECCODES_DEFINITION_PATH"] = "/different/path"
        with self.assertRaisesRegex(RuntimeError, "disagree"):
            discover_eccodes()

    def test_missing_codes_info(self):
        (self.binary / "codes_info").unlink()
        with self.assertRaisesRegex(RuntimeError, "codes_info beside"):
            discover_eccodes()

    def test_failed_codes_info(self):
        for error in (subprocess.CalledProcessError(1, "codes_info"),
                      subprocess.TimeoutExpired("codes_info", 10), OSError("unavailable")):
            with self.subTest(error=type(error).__name__):
                self.check_output.side_effect = error
                with self.assertRaisesRegex(RuntimeError, "Cannot discover"):
                    discover_eccodes()

    def test_invalid_definitions(self):
        for value in ("", str(self.root / "missing"), str(self.definitions) + ":", "relative", "bad\npath"):
            with self.subTest(value=value):
                self.check_output.return_value = value
                with self.assertRaises(RuntimeError):
                    discover_eccodes()
                self.assertNotIn("ECCODES_DEFINITION_PATH", os.environ)

    def test_inaccessible_definitions(self):
        real_access = os.access
        with patch("eccodes_config.os.access", side_effect=lambda path, mode: False if str(path) == str(self.definitions) else real_access(path, mode)):
            with self.assertRaisesRegex(RuntimeError, "not accessible"):
                discover_eccodes()

    def test_embedded_definitions(self):
        self.check_output.return_value = "/MEMFS/definitions"
        self.assertEqual(discover_eccodes()[1], "/MEMFS/definitions")


if __name__ == "__main__":
    unittest.main()
