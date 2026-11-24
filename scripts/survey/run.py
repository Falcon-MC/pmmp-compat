#!/usr/bin/env python3

import argparse
from collections import defaultdict
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys


def select(archive, limit):
    projects = defaultdict(list)
    for manifest in sorted(archive.glob("*/*/*/info.json")):
        releases = sorted(manifest.parent.glob("*.phar"))
        if not releases:
            continue
        release = json.loads(manifest.read_text(encoding="utf-8"))
        release["phar"] = str(releases[0])
        projects[release["repo_name"], release["project_name"]].append(release)

    rows = []
    for (repo, project), versions in projects.items():
        eligible = [
            release for release in versions
            if any(
                str(api.get(endpoint, "")).split(".")[0] == "5"
                for api in release.get("api", [])
                for endpoint in ("from", "to")
            )
        ]
        if not eligible:
            continue
        latest = max(eligible, key=lambda release: (release.get("submission_date") or 0, release["phar"]))
        rows.append({
            "project": project,
            "repo": repo,
            "version": latest["version"],
            "downloads": sum(release.get("downloads") or 0 for release in versions),
            "phar": latest["phar"],
        })
    rows.sort(key=lambda row: (-row["downloads"], row["repo"], row["project"]))
    return rows[:limit]


def main():
    parser = argparse.ArgumentParser(description="Reproduce the Poggit API 5 plugin survey.")
    parser.add_argument("archive", type=Path, help="checkout of poggit/release-archive")
    parser.add_argument("output", type=Path, help="new directory for extraction and analysis")
    parser.add_argument("--limit", type=int, default=200)
    parser.add_argument("--php", default="php", help="PHP CLI executable with Phar")
    args = parser.parse_args()
    if args.limit < 1:
        parser.error("--limit must be positive")
    archive = args.archive.resolve()
    rows = select(archive, args.limit)
    if not rows:
        parser.error("no API 5 releases found in archive")
    output = args.output.resolve()
    output.mkdir()
    sources = output / "src"
    sources.mkdir()
    locations = {}
    for index, row in enumerate(rows):
        label = re.sub(r"[^A-Za-z0-9_.-]", "_", row["project"])
        target = sources / f"{index:03d}_{label}"
        locations[row["phar"]] = str(target)
        row["source_dir"] = target.name
        row["phar"] = str(Path(row["phar"]).relative_to(archive))
    scripts = Path(__file__).resolve().parent
    subprocess.run([args.php, str(scripts / "extract.php")], input=json.dumps(locations), text=True, check=True)
    success = 0
    for row in rows:
        target = sources / row["source_dir"]
        row["extracted"] = (target / ".complete").is_file()
        if row["extracted"]:
            success += 1
        elif target.exists():
            shutil.rmtree(target)
    (output / "ranking.json").write_text(json.dumps(rows, indent=2) + "\n", encoding="utf-8")
    subprocess.run([sys.executable, str(scripts / "analyze.py"), str(output)], check=True)
    subprocess.run([sys.executable, str(scripts / "report.py"), str(output)], check=True)
    print(f"Extracted {success} of {len(rows)} projects. Report: {output / 'REPORT.md'}")


if __name__ == "__main__":
    main()
