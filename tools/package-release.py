"""Build a PHP hosting package from public source files only; never package runtime data."""
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SERVER_FILES = [
    'admin-api.php', 'admin-auth.php', 'auth-core.php',
    'auth.config.example.php', 'configure-admin.php', 'reset-admin.php', 'database.php', 'visitor-auth.php',
    'password-api.php', 'password-core.php', 'router.php', 'store-api.php', 'store-core.php',
]
ASSET_TYPES = {'.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg', '.ico', '.woff', '.woff2', '.ttf'}

def build():
    files = [ROOT / '.htaccess']
    for suffix in ('*.html', '*.css', '*.js'):
        files.extend(path for path in ROOT.glob(suffix) if path.name not in {'auth-client.js', 'auth-ui.js'})
    files.extend(ROOT / 'server' / name for name in SERVER_FILES)
    files.extend(path for path in (ROOT / 'assets').rglob('*') if path.is_file() and path.suffix.lower() in ASSET_TYPES)
    files = sorted(set(files))
    if any(not file.is_file() or file.is_symlink() or not file.resolve().is_relative_to(ROOT) for file in files):
        raise RuntimeError('Release source must be present and contained in the project')
    folder = ROOT / 'release'
    folder.mkdir(exist_ok=True)
    target = folder / 'PokeAnya-Store.zip'
    checksums = {}
    with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=7) as archive:
        for path in files:
            relative = path.relative_to(ROOT).as_posix()
            archive.write(path, relative)
            checksums[relative] = hashlib.sha256(path.read_bytes()).hexdigest()
    manifest = {'builtAt': datetime.now(timezone.utc).isoformat(), 'archive': target.name,
                'sha256': hashlib.sha256(target.read_bytes()).hexdigest(), 'files': checksums,
                'includes': 'source and public assets only; no accounts, database, sessions, backups or provider secrets'}
    (folder / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps({'archive': str(target), 'files': len(files), 'bytes': target.stat().st_size, 'sha256': manifest['sha256']}, ensure_ascii=False))
    return target, manifest

if __name__ == '__main__':
    build()
