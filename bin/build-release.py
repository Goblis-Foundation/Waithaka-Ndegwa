#!/usr/bin/env python3
"""Build a reviewable recovery release and a root-level Beanstalk application ZIP.

Requires a clean git revision and composer install from the committed lock.
Writes only to the explicit output directory; never deploys or uploads.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import zipfile


ROOT = Path(__file__).resolve().parents[1]
STORAGE_MARKERS = {"storage/.gitkeep", "storage/.htaccess", "storage/release-proofs/.htaccess"}
UPLOAD_MARKERS = {"assets/uploads/.gitkeep", "assets/uploads/.htaccess"}


def git(*args: str) -> bytes:
    return subprocess.check_output(["git", *args], cwd=ROOT)


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def runtime_file(path: str) -> bool:
    top = path.split("/", 1)[0]
    if top in {"app", "assets", "views", ".ebextensions", ".platform"}:
        return True
    if path in {"index.php", ".htaccess", "composer.json", "composer.lock", "manifest.webmanifest", ".env.example"} or path in STORAGE_MARKERS:
        return True
    if path in {"bin/migrate.php", "bin/worker.php", "bin/create-writer.php"}:
        return True
    return path in {"deploy/aws/worker-launcher.php", "deploy/aws/run-worker.sh", "deploy/aws/global-bundle.pem", "deploy/aws/media-limits.ini"}


def assert_public_source(path: str) -> None:
    if path in {".env", "app/config.php"}:
        raise RuntimeError("Private configuration is tracked; remove it before packaging.")
    if path.startswith("storage/") and path not in STORAGE_MARKERS:
        raise RuntimeError("Runtime storage must not be tracked in the source release.")
    if path.startswith("assets/uploads/") and path not in UPLOAD_MARKERS:
        raise RuntimeError("Live uploads must be backed up separately from the source release.")
    if any(part in {".aws", ".git", "__pycache__"} for part in Path(path).parts):
        raise RuntimeError("Private or generated directory is tracked.")


def add_file(archive: zipfile.ZipFile, path: Path, member: str) -> dict[str, object]:
    if path.is_symlink() or not path.is_file():
        raise RuntimeError("Release files must be ordinary files: " + member)
    data = path.read_bytes()
    archive.write(path, member)
    return {"path": member, "bytes": len(data), "sha256": digest(data)}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--label", default="2026-10-21")
    args = parser.parse_args()
    if not args.label or any(c not in "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_" for c in args.label):
        parser.error("Use a filename-safe release label.")
    if git("status", "--porcelain").strip():
        raise RuntimeError("Commit the reviewed source first; packaging requires a clean worktree.")
    if not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("Install the committed Composer dependencies before building.")
    paths = sorted(p.decode() for p in git("ls-files", "-z").split(b"\0") if p)
    for path in paths:
        assert_public_source(path)
    output = args.output_dir.resolve()
    if output == ROOT or ROOT in output.parents:
        raise RuntimeError("Write release artifacts outside the source tree.")
    output.mkdir(parents=True, exist_ok=True)
    release = output / ("Waithaka-Ndegwa-AWS-Implementation-" + args.label + ".zip")
    if release.exists():
        raise RuntimeError("Choose a new label/output directory; existing releases are not overwritten.")
    commit = git("rev-parse", "HEAD").decode().strip()
    with tempfile.TemporaryDirectory(prefix="waithaka-release-build-") as temp:
        temporary = Path(temp)
        application = temporary / "application.zip"
        runtime_manifest = []
        with zipfile.ZipFile(application, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as app:
            for path in paths:
                if runtime_file(path):
                    runtime_manifest.append(add_file(app, ROOT / path, path))
            for path in sorted((ROOT / "vendor").rglob("*")):
                if path.is_file() or path.is_symlink():
                    runtime_manifest.append(add_file(app, path, path.relative_to(ROOT).as_posix()))
        with zipfile.ZipFile(application) as app:
            if app.testzip() is not None:
                raise RuntimeError("Application ZIP integrity failed.")
            required = {"index.php", ".htaccess", "vendor/autoload.php", ".platform/hooks/postdeploy/30_worker.sh",
                        ".platform/hooks/predeploy/25_media_limits.sh", "deploy/aws/global-bundle.pem",
                        "deploy/aws/media-limits.ini", "app/newsletter.php", "app/media_library.php",
                        "app/mail_feedback.php", "app/providers/youtube.php", "app/providers/tiktok.php",
                        "app/providers/tiktok_business.php", "app/readiness.php",
                        "app/analytics.php", "views/partials_google_tag.php", "app/editorial.php",
                        "app/reader.php", "app/discovery.php", "app/campaigns.php",
                        "app/migrations/100_analytics.sql", "app/migrations/110_editorial.sql",
                        "app/migrations/120_reader.sql", "app/migrations/130_discovery.sql",
                        "app/migrations/140_campaigns.sql", "views/write/campaigns.php",
                        "views/write/discovery.php", "views/editorial/start.php"}
            if not required.issubset(set(app.namelist())):
                raise RuntimeError("Application ZIP is missing a required root-level file.")
        history = temporary / "recovery-and-implementation.bundle"
        subprocess.run(["git", "bundle", "create", str(history), "--all"], cwd=ROOT, check=True, capture_output=True)
        source_manifest = []
        manifest = {
            "release": args.label, "commit": commit, "recovered_baseline": "5d69ca2",
            "deployment_performed": False, "provider_accounts_connected": False,
            "application_bundle": {"path": "deployment/application.zip", "bytes": application.stat().st_size, "sha256": digest(application.read_bytes())},
            "validation": "source/docs/VALIDATION.md", "runtime_files": runtime_manifest,
        }
        start = (
            "WAITHAKA NDEGWA — IMPLEMENTATION RELEASE\n\n"
            "Open source/README.md and source/docs/OPERATING-GUIDE.md.\n"
            "Use source/docs/AWS-MIGRATION.md to restore and stage the site.\n"
            "The guarded helper is documented in source/deploy/aws/INSTALL-CUTOVER.md.\n"
            "Upload deployment/application.zip as the Beanstalk source bundle,\n"
            "not this outer delivery archive. AWS account settings remain required.\n\n"
            "source/ contains the complete tracked project, tests, CI and runbooks.\n"
            "deployment/application.zip includes locked production dependencies.\n"
            "history/ contains the pristine recovered source and implementation commits.\n"
            "release-manifest.json records exact source/runtime file hashes.\n\n"
            "No live database, credentials, application key, uploads or private proof\n"
            "files are included. These must be recovered from the current host.\n"
            "No AWS deployment, DNS change or social publication has been performed.\n"
        )
        with zipfile.ZipFile(release, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            archive.writestr("START-HERE.txt", start)
            for path in paths:
                source_manifest.append(add_file(archive, ROOT / path, "source/" + path))
            add_file(archive, application, "deployment/application.zip")
            add_file(archive, history, "history/recovery-and-implementation.bundle")
            manifest["source_files"] = source_manifest
            archive.writestr("release-manifest.json", json.dumps(manifest, indent=2) + "\n")
        with zipfile.ZipFile(release) as archive:
            if archive.testzip() is not None:
                raise RuntimeError("Delivery archive integrity failed.")
    print(json.dumps({"file": str(release), "bytes": release.stat().st_size, "sha256": digest(release.read_bytes()), "commit": commit, "source_files": len(source_manifest), "runtime_files": len(runtime_manifest)}, indent=2))


if __name__ == "__main__":
    main()
