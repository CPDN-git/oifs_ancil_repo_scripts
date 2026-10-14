"""Locate native ecCodes tools and definitions without importing Python bindings."""

import os
from pathlib import Path
import shutil
import subprocess
import sys
from typing import Tuple


def discover_eccodes() -> Tuple[str, str]:
    """Return grib_ls and the definitions path; set both definitions variables.

    Prefer the running interpreter's environment over PATH. Preserve explicit
    site definitions, including colon-separated custom/default search paths.
    Otherwise ask codes_info beside grib_ls for its installation's default.
    Raise RuntimeError for missing tools, conflicting settings or invalid paths.
    Only the current process's environment is changed; no files are written.
    """
    grib_ls = Path(sys.prefix) / "bin" / "grib_ls"
    if not grib_ls.exists():
        found = shutil.which("grib_ls")
        if found is None:
            raise RuntimeError(
                "Cannot find grib_ls in the Python environment or PATH. "
                "Install ecCodes in the environment used by the upload handler."
            )
        grib_ls = Path(os.path.abspath(found))
    if not grib_ls.is_file() or not os.access(grib_ls, os.X_OK):
        raise RuntimeError("grib_ls is not executable: " + str(grib_ls))

    current = os.environ.get("ECCODES_DEFINITION_PATH", "")
    legacy = os.environ.get("GRIB_DEFINITION_PATH", "")
    if current and legacy and current != legacy:
        raise RuntimeError(
            "ECCODES_DEFINITION_PATH and GRIB_DEFINITION_PATH disagree. "
            "Configure one path or give both the same value."
        )
    definitions = current or legacy
    if not definitions:
        # Do not pair grib_ls with codes_info from another installation on PATH.
        codes_info = grib_ls.parent / "codes_info"
        if not codes_info.is_file() or not os.access(codes_info, os.X_OK):
            raise RuntimeError("Cannot find executable codes_info beside " + str(grib_ls))
        try:
            definitions = subprocess.check_output(
                [str(codes_info), "-d"], text=True, stderr=subprocess.PIPE,
                timeout=10,
            ).strip()
        except (OSError, subprocess.SubprocessError) as error:
            raise RuntimeError("Cannot discover ecCodes definitions with " + str(codes_info)) from error

    if not definitions or any(character in definitions for character in "\r\n\0"):
        raise RuntimeError("ecCodes returned an empty or invalid definitions path.")
    for directory in definitions.split(os.pathsep):
        # ecCodes can embed definitions in its virtual MEMFS filesystem.
        if directory.startswith("/MEMFS/"):
            continue
        if not os.path.isabs(directory) or not os.path.isdir(directory):
            raise RuntimeError("ecCodes definitions directory does not exist: " + directory)
        if not os.access(directory, os.R_OK | os.X_OK):
            raise RuntimeError("ecCodes definitions directory is not accessible: " + directory)

    os.environ["ECCODES_DEFINITION_PATH"] = definitions
    os.environ["GRIB_DEFINITION_PATH"] = definitions
    return str(grib_ls), definitions
