#!/usr/bin/env python3
"""Exercise backup/restore with generated fixtures only, without booting Laravel."""

import hashlib
import json
from pathlib import Path
import shutil
import sqlite3
import tempfile
import time


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def readonly(path):
    return sqlite3.connect(path.resolve().as_uri() + '?mode=ro', uri=True)


def check_database(path):
    with readonly(path) as database:
        if database.execute('PRAGMA integrity_check').fetchall() != [('ok',)]:
            raise ValueError('SQLite integrity check failed')
        if database.execute('PRAGMA foreign_key_check').fetchall():
            raise ValueError('SQLite foreign key check failed')
        return database.execute('SELECT id, value FROM records ORDER BY id').fetchall()


def verify_bundle(directory, manifest):
    for filename, checksum in manifest.items():
        if digest(directory / filename) != checksum:
            raise ValueError('Backup checksum mismatch')


def drill(root, mode):
    source = root / mode / 'source'
    backup = root / mode / 'backup'
    restored = root / mode / 'restored'
    source.mkdir(parents=True)
    backup.mkdir()
    connections = []
    names = ('primary.sqlite', 'analytics.sqlite', 'queue.sqlite')
    expected = {}
    try:
        for name in names:
            connection = sqlite3.connect(source / name)
            connections.append(connection)
            connection.execute(f'PRAGMA journal_mode={mode}')
            connection.execute('PRAGMA wal_autocheckpoint=0')
            connection.execute('CREATE TABLE records (id INTEGER PRIMARY KEY, value TEXT NOT NULL)')
            connection.execute('INSERT INTO records VALUES (1, ?)', ('synthetic-' + name,))
            connection.commit()
            expected[name] = [(1, 'synthetic-' + name)]
        (source / 'private-document.txt').write_text('Synthetic private document\n')
        (source / 'public-media.txt').write_text('Synthetic public media\n')

        # Writers are quiesced across all three databases and both file stores.
        # Open connections retain WAL files so the backup must include WAL commits.
        if mode == 'wal':
            assert all((source / (name + '-wal')).stat().st_size > 0 for name in names)
        for name, connection in zip(names, connections):
            with sqlite3.connect(backup / name) as destination:
                connection.backup(destination)
            assert check_database(backup / name) == expected[name]
        for name in ('private-document.txt', 'public-media.txt'):
            shutil.copy2(source / name, backup / name)
        manifest = {path.name: digest(path) for path in sorted(backup.iterdir())}
        (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
        verify_bundle(backup, manifest)

        # A post-snapshot commit illustrates the data-loss boundary of this backup.
        connections[0].execute('INSERT INTO records VALUES (2, ?)', ('after-backup',))
        connections[0].commit()
    finally:
        for connection in connections:
            connection.close()

    source.rename(source.with_name('unavailable-source'))
    try:
        readonly(source / 'primary.sqlite')
    except sqlite3.OperationalError:
        missing_source_rejected = True
    else:
        raise AssertionError('Missing source was silently created')

    started = time.monotonic()
    verify_bundle(backup, manifest)
    shutil.copytree(backup, restored)
    verify_bundle(restored, manifest)
    for name in names:
        assert check_database(restored / name) == expected[name]
    restore_seconds = round(time.monotonic() - started, 6)

    corrupt = backup.with_name('corrupt-copy')
    shutil.copytree(backup, corrupt)
    (corrupt / 'primary.sqlite').write_bytes(b'corrupt fixture')
    try:
        verify_bundle(corrupt, manifest)
    except ValueError:
        corrupt_backup_rejected = True
    else:
        raise AssertionError('Corrupt backup was accepted')
    verify_bundle(backup, manifest)
    verify_bundle(restored, manifest)
    return {
        'journal_mode': mode,
        'databases_restored': len(names),
        'documents_restored': 2,
        'missing_source_rejected': missing_source_rejected,
        'corrupt_backup_rejected': corrupt_backup_rejected,
        'post_snapshot_row_absent': True,
        'integrity_and_rows_verified': True,
        'restore_seconds': restore_seconds,
    }


if __name__ == '__main__':
    with tempfile.TemporaryDirectory(prefix='tris-synthetic-recovery-') as temporary:
        results = [drill(Path(temporary), mode) for mode in ('delete', 'wal')]
    print(json.dumps({'scope': 'synthetic local fixtures only', 'results': results}, indent=2))
