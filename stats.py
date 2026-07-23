#!/usr/bin/python3
# gather linux system statistics and output as PHP data for this machine
#
# Each machine runs this from cron and writes <hostname>.dat (PHP) into the
# shared directory next to this script; index.php reads all the .dat files.
# A small rolling history is kept in <hostname>.hist for sparklines.

from subprocess import *
import getpass
import html
import os
import pwd
import time
import csv

NUMPROCS = 100
DIR = os.path.dirname(os.path.abspath(__file__))  # shared web directory
EXT = "dat"
HIST_EXT = "hist"
HIST_SAMPLES = 288  # keep ~24h of history at one sample per 5 minutes
# filesystems worth watching; non-existent ones are skipped, so this is safe
# to ship generically (a lab's /scratch, a laptop's plain /, etc.)
DISK_CANDIDATES = ['/', '/tmp', '/scratch', '/scratch2', '/space', '/data', '/local', '/var']

this_user = getpass.getuser()
this_script = os.path.abspath(__file__)


def sh(cmd):
    """Run a shell command, return stdout+stderr as text."""
    return Popen(cmd, shell=True, stdout=PIPE, stderr=STDOUT, close_fds=True,
                 universal_newlines=True).communicate()[0]


def run(args):
    """Run an argv list, return stdout as text (stderr discarded)."""
    return Popen(args, stdout=PIPE, stderr=DEVNULL, close_fds=True,
                 universal_newlines=True).communicate()[0]


def cell(s):
    """Escape an arbitrary value for safe embedding in a single-quoted PHP
    string that will later be printed as HTML. HTML-escaping (quote=True)
    removes all quotes, so only backslashes still need PHP-escaping."""
    return html.escape(str(s), quote=True).replace("\\", "\\\\")


def php_array(items):
    """Render a python list of already-escaped strings as a PHP array literal."""
    return "array('" + "', '".join(items) + "')" if items else "array()"


########

hostname = run(["hostname"]).strip().lower().split('.')[0] or "unknown"

# uptime / load
uptime = sh("uptime").strip()
if 'load average: ' in uptime:
    load = uptime.split('load average: ')[1].replace(',', ' ').split()[:3]
else:
    load = ['0.0', '0.0', '0.0']

# cpu count
ncpus = os.sysconf("SC_NPROCESSORS_ONLN")

# real memory usage from /proc/meminfo (used %, plus GB detail)
mem_total_kb = mem_avail_kb = 0
try:
    with open('/proc/meminfo') as mf:
        for line in mf:
            if line.startswith('MemTotal:'):
                mem_total_kb = int(line.split()[1])
            elif line.startswith('MemAvailable:'):
                mem_avail_kb = int(line.split()[1])
except IOError:
    pass
ram_total_gb = mem_total_kb / 1048576.0
ram_avail_gb = mem_avail_kb / 1048576.0
mem_pct = 100.0 * (mem_total_kb - mem_avail_kb) / mem_total_kb if mem_total_kb else 0.0

# processes: structured rows [user, nice, cpu, mem, elapsed, command]
procs_raw = sh("ps axw -o user:25,nice,pcpu,pmem,etime,args --sort -pcpu | head -n %i"
               % (NUMPROCS + 3)).strip().split("\n")

procs = []       # list of escaped [user, ni, cpu, mem, elapsed, cmd]
users = []       # clean usernames of real people running listed processes
totcpu = 0.0
for proc in procs_raw:
    if len(procs) > NUMPROCS:
        break
    d = [x for x in proc.strip().split(" ") if x != '']
    if len(d) < 6:
        continue

    # skip idle (<=3% cpu and <=3% mem), the header, cron, this script, etc.
    if (d[2] != '%CPU' and float(d[2]) <= 3 and float(d[3]) <= 3) or \
       (d[0] == 'root' and d[5] in ('/USR/SBIN/CRON', 'CRON')) or \
       d[5] in ('/sbin/plymouthd', '/usr/sbin/unity-greeter') or \
       (d[0] == this_user and d[5] in ('crond', '[head]')) or \
       (d[0] == this_user and d[5] == 'head' and d[6] == '-n') or \
       (d[0] == this_user and d[5] == 'ps' and d[6] == 'axw') or \
       (d[0] == this_user and d[5] == '/bin/sh' and len(d) > 8 and d[7] == 'ps' and d[8] == 'axw') or \
       d[-1] == this_script or \
       (d[5].startswith('/usr/bin/python') and len(d) > 6 and d[6] == this_script):
        continue

    user = cell(d[0])
    ni = cell(d[1])
    cpu = cell(d[2])
    pmem = cell(d[3])
    elapsed = cell(d[4])
    command = cell(" ".join(d[5:]))
    procs.append([user, ni, cpu, pmem, elapsed, command])

    if d[0] not in ('root', 'USER'):
        users.append(user)
    if d[2] != '%CPU':
        try:
            totcpu += float(d[2])
        except ValueError:
            pass

totcpu = totcpu / ncpus if ncpus else totcpu  # busy % of the whole machine

# disk usage per distinct filesystem
disk = []  # (mount, used_pct, used_gb, total_gb)
seen_dev = set()
for path in DISK_CANDIDATES:
    if not os.path.isdir(path):
        continue
    try:
        devid = os.stat(path).st_dev
        st = os.statvfs(path)
    except OSError:
        continue
    if devid in seen_dev:
        continue
    seen_dev.add(devid)
    total = st.f_blocks * st.f_frsize
    free = st.f_bavail * st.f_frsize
    if total <= 0:
        continue
    used_pct = 100.0 * (total - free) / total
    disk.append((path, used_pct, (total - free) / 1e9, total / 1e9))

# GPUs (via nvidia-smi); everything is best-effort and skipped on non-GPU hosts
query_attributes = ['index', 'uuid', 'name', 'utilization.gpu', 'utilization.memory',
                    'memory.total', 'memory.free', 'memory.used',
                    'temperature.gpu', 'power.draw']
gpu_info = {att: {} for att in query_attributes}
gpus = []
uuid_to_name = {}
gpu_users = {}  # name -> ['user(1234MiB)', ...]
gpu = False
try:
    out = run(['nvidia-smi', '--query-gpu=' + ','.join(query_attributes), '--format=csv'])
    reader = csv.reader(out.strip().split("\n"), skipinitialspace=True)
    next(reader)  # header
    for row in reader:
        if len(row) < len(query_attributes):
            continue
        name = hostname + '_' + row[0]
        gpus.append(name)
        uuid_to_name[row[1]] = name
        for i, att in enumerate(query_attributes):
            gpu_info[att][name] = row[i]
    # which user occupies each GPU, and how much memory
    apps = run(['nvidia-smi', '--query-compute-apps=gpu_uuid,pid,used_gpu_memory',
                '--format=csv,noheader,nounits'])
    for row in csv.reader(apps.strip().split("\n"), skipinitialspace=True):
        if len(row) < 3:
            continue
        name = uuid_to_name.get(row[0])
        if not name:
            continue
        try:
            owner = pwd.getpwuid(os.stat('/proc/%s' % row[1]).st_uid).pw_name
        except (OSError, KeyError):
            owner = '?'
        gpu_users.setdefault(name, []).append('%s (%sMiB)' % (owner, row[2].strip()))
    gpu = len(gpus) > 0
except Exception:
    pass

# mean GPU utilisation for the history sample
gpu_mean = ''
if gpu:
    try:
        vals = [float(gpu_info['utilization.gpu'][g].replace('%', '').strip()) for g in gpus]
        gpu_mean = "%.0f" % (sum(vals) / len(vals))
    except (ValueError, ZeroDivisionError):
        gpu_mean = ''

now = time.time()

# ---- build the .dat (PHP) ----
dat = "<?php\n"
dat += "$cpu['%s'] = %.1f;\n" % (hostname, totcpu)
dat += "$mem['%s'] = %.1f;\n" % (hostname, mem_pct)
dat += "$cores['%s'] = %d;\n" % (hostname, ncpus)
dat += "$ram['%s'] = array(%.1f, %.1f);\n" % (hostname, ram_total_gb, ram_avail_gb)
dat += "$load['%s'] = %s;\n" % (hostname, php_array([cell(x) for x in load]))
dat += "$users['%s'] = %s;\n" % (hostname, php_array(users))
dat += "$time['%s'] = %s;\n" % (hostname, now)

# structured processes
rows = []
for p in procs:
    rows.append("array('" + "', '".join(p) + "')")
dat += "$procs['%s'] = array(%s);\n" % (hostname, ",".join(rows))

# disk
dparts = []
for (mount, pct, used_gb, total_gb) in disk:
    dparts.append("'%s' => array(%.1f, %.1f, %.1f)" % (cell(mount), pct, used_gb, total_gb))
dat += "$disk['%s'] = array(%s);\n" % (hostname, ", ".join(dparts))

# GPUs
if gpu:
    dat += "$gpu['%s'] = %s;\n" % (hostname, php_array([cell(g) for g in gpus]))
    for att in query_attributes:
        key = att.replace('.', '')
        for g in gpus:
            dat += "$%s['%s'] = '%s';\n" % (key, cell(g), cell(gpu_info[att][g]))
    for g in gpus:
        owners = gpu_users.get(g, [])
        dat += "$gpuusers['%s'] = %s;\n" % (cell(g), php_array([cell(o) for o in owners]))

# a pre-rendered detail table kept for backward compatibility with older
# index.php during rollout; the new index.php builds its own from $procs
detail = "<tr><td colspan=\"6\"><b>%s</b> (CPU:%.1f%% - MEM:%.1f%%)</td></tr>" % (hostname, totcpu, mem_pct)
detail += "<tr><td colspan=\"6\">%s</td></tr>" % cell(uptime)
for p in procs:
    detail += "<tr><td>%s</td><td>%s</td></tr>" % ("</td><td>".join(p[0:5]), p[5])
dat += "$output['%s'] = '%s';\n" % (hostname, detail.replace("'", "\\'"))
dat += "?>"

# ---- write atomically so concurrent readers never see a partial file ----
final = "%s/%s.%s" % (DIR, hostname, EXT)
tmp = "%s.tmp.%d" % (final, os.getpid())
with open(tmp, "w") as f:
    f.write(dat)
os.replace(tmp, final)

# ---- append to the rolling history (for sparklines) ----
histfile = "%s/%s.%s" % (DIR, hostname, HIST_EXT)
samples = []
try:
    with open(histfile) as hf:
        samples = [l.strip() for l in hf if l.strip()]
except IOError:
    pass
samples.append("%d,%.1f,%s,%.1f,%s" % (int(now), totcpu, load[0], mem_pct, gpu_mean))
samples = samples[-HIST_SAMPLES:]
htmp = "%s.tmp.%d" % (histfile, os.getpid())
with open(htmp, "w") as hf:
    hf.write("\n".join(samples) + "\n")
os.replace(htmp, histfile)
