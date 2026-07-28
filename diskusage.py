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
# Everything else on a monitored filesystem is attributed too: a remainder
# pass walks the parts not covered by a scan root and sums disk blocks by
# file owner, so the OS install shows up as 'root' instead of an anonymous
# grey remainder. Users below MIN_GB (and past TOP_N) are pooled into one
# '(small users)' entry. What's left unattributed after that is essentially
# deleted-but-open files and filesystem metadata.
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
#   TOP_DU_WALK_MOUNTS  colon-separated mounts for the by-owner remainder walk
#                  (default: / /tmp /var plus mounts holding scan roots);
#                  set empty to disable the remainder pass entirely

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
DU_TIMEOUT = 4 * 3600  # seconds, hard cap per filesystem — a multi-TB mount
                       # on a busy machine can legitimately need hours at
                       # idle I/O priority; timing out drops the whole mount
LOCK_STALE = 16 * 3600 # a lock older than this is treated as a crashed run;
                       # must comfortably exceed a worst-case full scan so a
                       # slow-but-alive run never has its lock stolen
NET_FS = set(['nfs', 'nfs4', 'cifs', 'smbfs', 'smb3', 'fuse.sshfs', 'lustre',
              'gpfs', 'ceph', 'glusterfs', 'afs', '9p', 'fuse.glusterfs', 'beegfs'])
PSEUDO_FS = set(['tmpfs', 'devtmpfs', 'squashfs', 'overlay', 'proc', 'sysfs',
                 'devpts', 'cgroup', 'cgroup2', 'pstore', 'efivarfs', 'autofs',
                 'tracefs', 'debugfs', 'securityfs', 'fusectl', 'ramfs', 'bpf',
                 'binfmt_misc', 'rpc_pipefs'])
# mounts whose *entire* contents get the by-owner remainder walk (on top of
# any mounts that turn out to hold a scan root); non-mounts are skipped
FULL_WALK_MOUNTS = ['/', '/tmp', '/var']
SMALL_LABEL = '(small users)'  # pooled entry for below-threshold users

# env overrides
if os.environ.get('TOP_DU_ROOTS'):
    DU_ROOTS = os.environ['TOP_DU_ROOTS'].split(':')
if os.environ.get('TOP_DU_MIN_GB'):
    try:
        MIN_GB = float(os.environ['TOP_DU_MIN_GB'])
    except ValueError:
        pass
WALK_OVERRIDE = os.environ.get('TOP_DU_WALK_MOUNTS') is not None
if WALK_OVERRIDE:
    FULL_WALK_MOUNTS = [p for p in os.environ['TOP_DU_WALK_MOUNTS'].split(':') if p]


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


def uname_of(uid):
    """Username for uid, or the numeric uid as a string if unmapped."""
    try:
        return pwd.getpwuid(uid).pw_name
    except KeyError:
        return str(uid)


def owner_of(path):
    """Owning username of path, numeric uid if unmapped, None if unstattable."""
    try:
        return uname_of(os.stat(path).st_uid)
    except OSError:
        return None


def walk_by_owner(top, skip, agg, deadline):
    """Sum disk blocks (what df counts, not apparent size) by file owner for
    everything under top on the same device, skipping the subtrees in `skip`
    (already scanned) and anything on another filesystem. Best effort:
    unreadable entries are ignored, multiply-linked files count once, and the
    walk stops adding at deadline (undercount then just stays grey)."""
    top = os.path.realpath(top)
    try:
        dev = os.stat(top).st_dev
    except OSError:
        return
    seen = set()   # (dev, ino) of files with nlink > 1
    stack = [top]
    n = 0
    while stack:
        d = stack.pop()
        try:
            entries = list(os.scandir(d))
        except OSError:
            continue
        for e in entries:
            n += 1
            if n % 4096 == 0 and time.time() > deadline:
                return
            try:
                st = e.stat(follow_symlinks=False)
            except OSError:
                continue
            if st.st_dev != dev:
                continue   # another filesystem's mount point
            if e.is_dir(follow_symlinks=False):
                if e.path in skip:
                    continue
                agg_user = uname_of(st.st_uid)
                agg[agg_user] = agg.get(agg_user, 0.0) + st.st_blocks * 512 / 1e9
                stack.append(e.path)
                continue
            if st.st_nlink > 1:
                key = (st.st_dev, st.st_ino)
                if key in seen:
                    continue
                seen.add(key)
            agg_user = uname_of(st.st_uid)
            agg[agg_user] = agg.get(agg_user, 0.0) + st.st_blocks * 512 / 1e9


# be gentle on the machine (CPU and, for our own walk, I/O)
try:
    os.nice(19)
except Exception:
    pass
if shutil.which('ionice'):
    try:
        Popen(['ionice', '-c3', '-p', str(os.getpid())],
              stdout=DEVNULL, stderr=DEVNULL).communicate()
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
scanned_rp = set()  # realpaths of scan roots, excluded from the remainder walk
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
        scanned_rp.add(os.path.realpath(root))
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

    # ---- remainder pass: attribute everything not under a scan root to its
    # file owner, so the OS install appears as 'root' instead of as grey ----
    mount_types = dict(MOUNTS)
    candidates = set(FULL_WALK_MOUNTS)
    if not WALK_OVERRIDE:
        candidates |= set(mount_of(r) for r in scanned_rp)
    for m in sorted(candidates):
        rp = os.path.realpath(m)
        if not WALK_OVERRIDE:
            ty = mount_types.get(rp)
            if ty is None or ty in NET_FS or ty in PSEUDO_FS:
                continue   # not a local-disk mount (or lives inside one above)
        if rp in scanned_rp:
            continue       # a scan root that is its own mount: already covered
        agg = result.setdefault(mount_of(rp), {})
        walk_by_owner(rp, scanned_rp, agg, time.time() + DU_TIMEOUT)

    now = int(time.time())
    dat = "<?php\n"
    dat += "$dutime['%s'] = %d;\n" % (hostname, now)
    parts = []
    for mount, usage in result.items():
        ranked = sorted(usage.items(), key=lambda kv: kv[1], reverse=True)
        top = [(u, gb) for (u, gb) in ranked if gb >= MIN_GB][:TOP_N]
        shown = set(u for (u, _gb) in top)
        # below-threshold and past-TOP_N users are pooled, not dropped, so
        # light users are distinguishable from the truly unattributed grey
        small = sum(gb for (u, gb) in ranked if u not in shown)
        if small >= 0.5:
            top.append((SMALL_LABEL, small))
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
