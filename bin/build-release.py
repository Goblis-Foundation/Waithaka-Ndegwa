#!/usr/bin/env python3
"""Build an immutable, validated root-level Beanstalk application ZIP."""
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
    if path in {"index.php", ".htaccess", "composer.json", "composer.lock", "manifest.webmanifest", "writer.webmanifest", "sw.js", "offline.html", ".env.example"} or path in STORAGE_MARKERS:
        return True
    if path in {"bin/message-trash-migrate.php", "bin/migrate.php", "bin/worker.php", "bin/create-writer.php", "bin/fresh-bootstrap.php", "bin/google-workspace-migrate.php", "bin/founder-story-migrate.php", "bin/culture-topic.php", "bin/google-mcp.php", "bin/meta-mcp.php"}:
        return True
    return path in {"deploy/aws/worker-launcher.php", "deploy/aws/run-worker.sh", "deploy/aws/global-bundle.pem", "deploy/aws/media-limits.ini"}

def assert_public_source(path: str) -> None:
    if path in {".env", "app/config.php"}:
        raise RuntimeError("Private configuration must not be tracked.")
    if path.startswith("storage/") and path not in STORAGE_MARKERS:
        raise RuntimeError("Runtime storage must not be tracked.")
    if path.startswith("assets/uploads/") and path not in UPLOAD_MARKERS:
        raise RuntimeError("Live uploads must be backed up separately.")
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
    parser.add_argument("--label", default="2026-10-27")
    args = parser.parse_args()
    if not args.label or any(c not in "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_" for c in args.label):
        parser.error("Use a filename-safe release label.")
    if git("status", "--porcelain").strip():
        raise RuntimeError("Packaging requires a clean reviewed worktree.")
    if not (ROOT / "vendor/autoload.php").is_file():
        raise RuntimeError("Install committed Composer dependencies first.")
    paths = sorted(p.decode() for p in git("ls-files", "-z").split(b"\0") if p)
    for path in paths:
        assert_public_source(path)
    output = args.output_dir.resolve()
    if output == ROOT or ROOT in output.parents:
        raise RuntimeError("Write release artifacts outside the source tree.")
    output.mkdir(parents=True, exist_ok=True)
    release = output / ("Waithaka-Ndegwa-AWS-Implementation-" + args.label + ".zip")
    if release.exists():
        raise RuntimeError("Choose a new output label; releases are immutable.")
    commit = git("rev-parse", "HEAD").decode().strip()
    with tempfile.TemporaryDirectory(prefix="waithaka-release-build-") as temp:
        temporary = Path(temp)
        brand = temporary / "brand"
        result = subprocess.run(["php", str(ROOT / "app/brand_assets.php"), "--output-dir", str(brand)], cwd=ROOT, check=True, text=True, capture_output=True)
        generated_names = json.loads(result.stdout)["files"]
        if any(Path(name).name != name for name in generated_names):
            raise RuntimeError("Brand compiler returned an unsafe path.")
        generated = {"assets/images/" + name: brand / name for name in generated_names}
        application = temporary / "application.zip"
        runtime_manifest = []
        with zipfile.ZipFile(application, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as app:
            for path in paths:
                if runtime_file(path):
                    runtime_manifest.append(add_file(app, generated.get(path, ROOT / path), path))
            for path in sorted(set(generated) - set(paths)):
                runtime_manifest.append(add_file(app, generated[path], path))
            for path in sorted((ROOT / "vendor").rglob("*")):
                if path.is_file() or path.is_symlink():
                    runtime_manifest.append(add_file(app, path, path.relative_to(ROOT).as_posix()))
        with zipfile.ZipFile(application) as app:
            if app.testzip() is not None:
                raise RuntimeError("Application ZIP integrity failed.")
            required = {"index.php", ".htaccess", "vendor/autoload.php", "app/publishing_desk.php", "app/publishing_desk_policy.php", "app/publishing_desk_routes.php", "app/publishing_desk_billing.php", "app/publishing_desk_media.php", "views/desk/index.php", "views/desk/client.php", "views/desk/article.php", "assets/images/brand-lockup.webp", "assets/images/brand-lockup.png", "assets/images/icon-192.png", "assets/images/icon-512.png", "assets/images/favicon.ico", "assets/js/visitor-statistics.js", "views/partials_visitor_statistics.php", "bin/message-trash-migrate.php", "app/message_trash_migration.php", "app/migrations/190_message_trash.sql", ".platform/hooks/postdeploy/29_message_trash.sh", "bin/culture-topic.php", "app/migrations/180_culture_topic.sql", ".platform/hooks/postdeploy/29_culture_topic.sh", "app/gemini.php", "app/planner.php", "app/trusted_devices.php", "views/write/program.php", "views/write/program_home.php", "assets/css/program.css", "bin/founder-story-migrate.php", "app/founder_story_migration.php", "app/migrations/170_founder_story_approval.sql", ".platform/hooks/postdeploy/28_founder_story.sh", "assets/js/document-qr.js", "assets/js/vendor/qrcode.js", "assets/js/vendor/QRCODE-LICENSE.txt", "views/document_record.php", "assets/css/document.css", ".platform/hooks/postdeploy/30_worker.sh", ".platform/hooks/predeploy/25_media_limits.sh", "deploy/aws/global-bundle.pem", "deploy/aws/media-limits.ini", "app/newsletter.php", "app/media_library.php", "app/mail_feedback.php", "app/providers/youtube.php", "app/providers/tiktok.php", "app/providers/tiktok_business.php", "app/readiness.php", "app/analytics.php", "views/partials_google_tag.php", "app/editorial.php", "app/reader.php", "app/discovery.php", "app/campaigns.php", "app/google_workspace.php", "app/migrations/160_google_workspace.sql", "bin/google-workspace-migrate.php", ".platform/hooks/postdeploy/27_google_workspace.sh", "bin/fresh-bootstrap.php", ".platform/hooks/postdeploy/25_fresh_bootstrap.sh", "app/migrations/100_analytics.sql", "app/migrations/110_editorial.sql", "app/migrations/120_reader.sql", "app/migrations/130_discovery.sql", "app/migrations/140_campaigns.sql", "views/write/campaigns.php", "views/write/discovery.php", "views/editorial/start.php"}
            if not required.issubset(set(app.namelist())):
                raise RuntimeError("Application ZIP is missing a required runtime file.")
        history = temporary / "recovery-and-implementation.bundle"
        subprocess.run(["git", "bundle", "create", str(history), "--all"], cwd=ROOT, check=True, capture_output=True)
        source_manifest = []
        manifest = {"release": args.label, "commit": commit, "recovered_baseline": "5d69ca2", "deployment_performed": False, "provider_accounts_connected": False, "application_bundle": {"path": "deployment/application.zip", "bytes": application.stat().st_size, "sha256": digest(application.read_bytes())}, "validation": "source/docs/VALIDATION.md", "runtime_files": runtime_manifest}
        start = "WAITHAKA NDEGWA — IMPLEMENTATION RELEASE\n\nThe immutable application bundle is deployment/application.zip.\nSource, tests, approved-brand compilation and hosting runbooks are included.\nThe manifest records exact runtime hashes, including compiled icons.\nThis archive itself does not activate billing or deploy infrastructure.\nNo provider secrets or private uploads are included.\n"
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
