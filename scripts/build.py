#!/usr/bin/env python3
"""Build an installable WordPress plugin without dev/test files or secrets."""
import pathlib
import re
import zipfile

root = pathlib.Path(__file__).resolve().parents[1]
version = re.search(r"Version: ([0-9.]+)", (root / "virtual-tryon-wp.php").read_text()).group(1)
output = root / "dist" / f"virtual-tryon-wp-{version}.zip"
output.parent.mkdir(exist_ok=True)
files = [root / name for name in ("virtual-tryon-wp.php", "uninstall.php", "readme.txt", "README.md", "LICENSE")]
files += sorted((root / "includes").glob("*.php"))
files += sorted(path for path in (root / "assets").rglob("*") if path.is_file())
with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as bundle:
    for path in files:
        bundle.write(path, pathlib.Path("virtual-tryon-wp") / path.relative_to(root))
print(output)
