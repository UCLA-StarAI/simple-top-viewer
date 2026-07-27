#!/usr/bin/python3
# Per-user disk usage on LOCAL filesystems.
#
# This walks whole directory trees, so it is FAR too expensive to run on the
# 5-minute stats.py schedule. Run it from cron at most once or twice a day,
# ideally overnight, e.g.:
#
#   30 4 * * *  /path/to/diskusage.py > /dev/null 2>&1
#
# Safeguards so it never hurts the machine:
#   * lowers its own CPU priority, and runs du under `ionice -c3` (idle I/O)
#     and `nice -n19` so it only uses the disk when nothing else needs it;
#   * skips network filesystems (NFS/CIFS/…) so a shared /space or /home is not
#     re-scanned once per machine -- only disks local to this host are measured;
#   * caps each filesystem with a timeout so a runaway scan cannot pile up.
#
# It writes <hostname>.du (PHP) next to this script; index.php displays it.
# Results are keyed by the MOUNT POINT holding each scanned root (roots that
# share a filesystem are merged), so the page can match them to its df bars
# even where a data root like /space is a plain directory on the root fs.
# Loose files sitting directly in a scan root are attributed to their owner.
#
# Run as a regular user it cannot descend into other users' unreadable
# directories, so totals undercount. For full-visibility numbers, install the
# root-side pipeline (see install-disk-usage and the README): a root cron job
# runs a root-owned copy of this script with TOP_DU_DIR pointing at a local
# drop directory, and an unprivileged job publishes the result here.
#
# Env overrides (handy for testing / ops):
#   TOP_DU_ROOTS   colon-separated list of roots to scan
#   TOP_DU_MIN_GB  ignore users below this many GB (default 5)
#   TOP_DU_DIR     write <hostname>.du (and the lock) here instead of next to
#                  this script — used by the root-side collector, which runs a
#                  root-owned copy and drops output in /var/lib/disk-usage

import html
import os
import pwd
import shutil
import sys
import time
from subprocess import Popen, PIPE, DEVNULL, TimeoutExpired

DIR = os.path.dirname(os.path.abspath(__file__))
OUT_DIR = os.environ.get('TOP_DU_DIR', DIR)  # where <hostname>.du and the lock go
EXT = "du"
# roots whose immediate subdirectories are per-user data dirs (/scratch/<user>).
# non-existent and network-mounted roots are skipped automatically.
DU_ROOTS = ['/scratch', '/scratch2', '/scratch3', '/space', '/data', '/local']
TOP_N = 25            # keep the biggest N users per filesystem
MIN_GB = 5.0          # ignore users below this many GB
DU_TIMEOUT = 5400     # seconds, hard cap per filesystem (90 min)
LOCK_STALE = 4 * 3600 # a lock older than this is treated as a crashed run
NET_FS = set(['nfs', 'nfs4', 'cifs', 'smbfs', 'smb3', 'fuse.sshfs', 'lustre',
              'gpfs', 'ceph', 'glusterfs', 'afs', '9p', 'fuse.glusterfs', 'beegfs'])

# env overrides
if os.environ.get('TOP_DU_ROOTS'):
    DU_ROOTS = os.environ['TOP_DU_ROOTS'].split(':')
if os.environ.get('TOP_DU_MIN_GB'):
    try:
        MIN_GB = float(os.environ['TOP_DU_MIN_GB'])
    except ValueError:
        pass


def cell(s):
    return html.escape(str(s), quote=True).replace("\\", "\\\\")


def load_mounts():
    m = []
    try:
        with open('/proc/mounts') as f:
            for line in f:
                p = line.split()
                if len(p) >= 3:
                    m.append((p[1], p[2]))
    except IOError:
        pass
    return sorted(m, key=lambda x: len(x[0]), reverse=True)  # longest prefix first


MOUNTS = load_mounts()


def fstype_of(path):
    rp = os.path.realpath(path)
    for (mp, ty) in MOUNTS:
        if rp == mp or rp.startswith(mp.rstrip('/') + '/'):
            return ty
    return ''


def mount_of(path):
    """Mount point holding path (longest prefix wins); path itself if unknown."""
    rp = os.path.realpath(path)
    for (mp, ty) in MOUNTS:
        if rp == mp or rp.startswith(mp.rstrip('/') + '/'):
            return mp
    return path


def owner_of(path):
    """Owning username of path, numeric uid if unmapped, None if unstattable."""
    try:
        uid = os.stat(path).st_uid
    except OSError:
        return None
    try:
        return pwd.getpwuid(uid).pw_name
    except KeyError:
        return str(uid)


# be gentle on the machine
try:
    os.nice(19)
except Exception:
    pass

hostname = Popen(["hostname"], stdout=PIPE, universal_newlines=True
                 ).communicate()[0].strip().lower().split('.')[0] or "unknown"

# priority prefix for du, if the tools exist
prefix = []
if shutil.which('ionice'):
    prefix += ['ionice', '-c3']
if shutil.which('nice'):
    prefix += ['nice', '-n19']

# single-instance lock: the 5-minute stats.py may fire this several times during
# the nightly window, and a scan can outlive the hour, so refuse to run twice.
LOCK = "%s/%s.du.lock" % (OUT_DIR, hostname)


def acquire_lock():
    try:
        fd = os.open(LOCK, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o644)
        os.write(fd, ("%d\n" % os.getpid()).encode())
        os.close(fd)
        return True
    except FileExistsError:
        try:
            if time.time() - os.path.getmtime(LOCK) > LOCK_STALE:
                os.unlink(LOCK)          # previous run died; steal the lock
                return acquire_lock()
        except OSError:
            pass
        return False


if not acquire_lock():
    sys.exit(0)  # another scan is already running

result = {}  # mount point -> {user: gb}
seen_dirs = set()  # (st_dev, st_ino) of already-scanned roots
try:
    for root in DU_ROOTS:
        if not os.path.isdir(root):
            continue
        if fstype_of(root) in NET_FS:
            continue  # shared filesystem: don't re-scan it from every host
        try:
            st = os.stat(os.path.realpath(root))
            key = (st.st_dev, st.st_ino)
        except OSError:
            continue
        if key in seen_dirs:
            continue  # same directory via a symlink/bind mount; don't walk twice
        seen_dirs.add(key)
        # per-user subdirectories, plus loose files sitting directly in the
        # root (attributed to their owner rather than their name)
        try:
            children = []
            for name in os.listdir(root):
                p = os.path.join(root, name)
                if os.path.islink(p):
                    continue
                if os.path.isdir(p):
                    children.append((name, p, False))
                elif os.path.isfile(p):
                    children.append((name, p, True))
        except OSError:
            continue
        if not children:
            continue

        args = prefix + ['du', '-sb', '--one-file-system'] + [p for (_, p, _f) in children]
        proc = Popen(args, stdout=PIPE, stderr=DEVNULL, universal_newlines=True)
        try:
            out = proc.communicate(timeout=DU_TIMEOUT)[0]
        except TimeoutExpired:
            proc.kill()
            proc.communicate()
            continue

        bytes_by_path = {}
        for line in out.strip().split("\n"):
            if not line.strip():
                continue
            parts = line.split("\t", 1)
            if len(parts) != 2:
                parts = line.split(None, 1)
            if len(parts) != 2:
                continue
            try:
                bytes_by_path[parts[1].strip()] = int(parts[0])
            except ValueError:
                continue

        # merge into the entry for the filesystem this root lives on; MIN_GB
        # and TOP_N are applied after merging, at output time
        agg = result.setdefault(mount_of(root), {})
        for (name, p, is_file) in children:
            b = bytes_by_path.get(p)
            if b is None:
                continue
            user = owner_of(p) if is_file else name
            if user is None:
                continue
            agg[user] = agg.get(user, 0.0) + b / 1e9

    now = int(time.time())
    dat = "<?php\n"
    dat += "$dutime['%s'] = %d;\n" % (hostname, now)
    parts = []
    for mount, usage in result.items():
        top = sorted(((u, gb) for (u, gb) in usage.items() if gb >= MIN_GB),
                     key=lambda kv: kv[1], reverse=True)[:TOP_N]
        if not top:
            continue
        inner = ", ".join("'%s' => %.1f" % (cell(u), gb) for (u, gb) in top)
        parts.append("'%s' => array(%s)" % (cell(mount), inner))
    dat += "$duusers['%s'] = array(%s);\n" % (hostname, ", ".join(parts))
    dat += "?>"

    final = "%s/%s.%s" % (OUT_DIR, hostname, EXT)
    tmp = "%s.tmp.%d" % (final, os.getpid())
    with open(tmp, "w") as f:
        f.write(dat)
    os.replace(tmp, final)
finally:
    try:
        os.unlink(LOCK)
    except OSError:
        pass
