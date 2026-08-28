"""Build both distributable zips.

There are two, and shipping the wrong one is a real failure mode: the premium
build is flagged is_premium, so installing it opens a licence wall before the
plugin will do anything. That is correct for a paying customer and catastrophic
for a free download link - which is exactly what the landing page had.

    dist/past-due-actions.zip       premium - upload to Freemius
    dist/past-due-actions-free.zip  free    - the landing page download

The only difference is the is_premium flag. Everything else is identical,
because the licence gate is evaluated at runtime through PDA_License::is_pro()
rather than by stripping code, so one source tree produces both.

    python build-zips.py
"""

import pathlib
import re
import shutil
import zipfile

ROOT = pathlib.Path(__file__).parent
DIST = ROOT / "dist"
STAGE = ROOT / ".build"

PAYLOAD = ("past-due-actions.php", "readme.txt")
TREES = ("includes", "freemius")

PREMIUM_TRUE = "'is_premium'          => true,"
PREMIUM_FALSE = "'is_premium'          => false,"


def stage(premium: bool) -> pathlib.Path:
    """Lay out one build under .build/past-due-actions."""
    if STAGE.exists():
        shutil.rmtree(STAGE)
    plugin = STAGE / "past-due-actions"
    plugin.mkdir(parents=True)

    for name in PAYLOAD:
        shutil.copy2(ROOT / name, plugin / name)
    for tree in TREES:
        shutil.copytree(ROOT / tree, plugin / tree)

    main = plugin / "past-due-actions.php"
    src = main.read_text(encoding="utf-8")
    if PREMIUM_TRUE not in src:
        raise SystemExit("is_premium flag not found - the bootstrap changed shape")
    if not premium:
        src = src.replace(PREMIUM_TRUE, PREMIUM_FALSE, 1)
        main.write_text(src, encoding="utf-8")

    return plugin


def pack(out: pathlib.Path) -> int:
    """Zip .build into out, deterministically ordered."""
    if out.exists():
        out.unlink()
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for f in sorted(STAGE.rglob("*")):
            if f.is_file():
                z.write(f, f.relative_to(STAGE).as_posix())
    return out.stat().st_size


def version() -> str:
    src = (ROOT / "past-due-actions.php").read_text(encoding="utf-8")
    m = re.search(r"^\s*\*\s*Version:\s*(\S+)", src, re.M)
    return m.group(1) if m else "?"


def main():
    DIST.mkdir(exist_ok=True)
    print("version", version())

    for premium, name in ((True, "past-due-actions.zip"),
                          (False, "past-due-actions-free.zip")):
        plugin = stage(premium)
        flag = (plugin / "past-due-actions.php").read_text(encoding="utf-8")
        assert (PREMIUM_TRUE in flag) is premium, "is_premium not set as intended"
        size = pack(DIST / name)
        print(f"  {name:<30} is_premium={str(premium).lower():<5} {size // 1024} KB")

    shutil.rmtree(STAGE)


if __name__ == "__main__":
    main()
