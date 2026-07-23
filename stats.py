#!/usr/bin/python3
# gather linux system statistics and output in html/php for this machine

#import commands
from subprocess import *
import getpass
import html
import os
import time
import csv


def php_escape(s):
  """Escape a string for embedding in a single-quoted PHP string.

  Replaces python2's str.encode('string_escape'): escapes backslashes and
  non-printables, then single quotes (which unicode_escape leaves alone).
  """
  return s.encode('unicode_escape').decode('ascii').replace("'", "\\'")

NUMPROCS=100
# .dat files are written next to this script (the shared web directory)
DIR=os.path.dirname(os.path.abspath(__file__))
EXT="dat"

########

output = ""

# uptime information:
uptime = Popen("uptime", shell=True, stdout=PIPE, stderr=STDOUT, close_fds=True, universal_newlines=True).communicate()[0].strip()
output += "<tr><td colspan=\"6\">%s</td></tr>"%html.escape(uptime)
# uptime always reports load on Linux, but guard so a hiccup can't kill the update
if 'load average: ' in uptime:
    load = uptime.split('load average: ')[1].split(', ')
else:
    load = ['0.0', '0.0', '0.0']

procs = Popen("ps axw -o user:25,nice,pcpu,pmem,etime,args --sort -pcpu | head -n %i"%(NUMPROCS+3), shell=True, stdout=PIPE, stderr=STDOUT, close_fds=True, universal_newlines=True).communicate()[0].strip().split("\n")
#p = Popen("top -c -b -n1 | head -n14", shell=True, stdout=PIPE, stderr=STDOUT, close_fds=True)
#procs = p.stdout.readlines()
#procs = commands.getoutput("top -b -n1 | head -15").split("\n")

totcpu = 0
totmem = 0
users = []
nprocs = 0
for proc in procs:
  # only display and count NUMPROCS processes
  if nprocs > NUMPROCS:
    break

  # HTML-escape every field: usernames and command lines are attacker-influenced
  # (anyone can launch a process named '<script>...'), and index.php prints them
  # raw. Escaping the values here (before we add our own <b>/<i> markup) stops
  # stored XSS; the paths/numbers compared below contain no special chars, so
  # the filters are unaffected.
  d = [html.escape(x) for x in proc.strip().split(" ") if x != '']
  #dd = [x for x in procs[i].strip().split(" ") if x != '']
  #cols = [1,3,8,9,10,11]
  #d = [dd[i] for i in cols]

  this_user = getpass.getuser()
  this_script = os.path.abspath(__file__)
  # ignore (<3% cpu and <3%ram) comands and cron, plymouth as well as this script
  if (d[2] != '%CPU' and (float(d[2]) <= 3 and float(d[3]) <= 3)) or \
     (d[0] == 'root' and d[5] == '/USR/SBIN/CRON') or \
     (d[0] == 'root' and d[5] == 'CRON') or \
     d[5] == '/sbin/plymouthd' or \
     d[5] == '/usr/sbin/unity-greeter' or \
     (d[0] == this_user and d[5] == 'crond') or \
     (d[0] == this_user and d[5] == '[head]') or \
     (d[0] == this_user and d[5] == 'head' and d[6] == '-n') or \
     (d[0] == this_user and d[5] == 'ps' and d[6] == 'axw') or \
     (d[0] == this_user and d[5] == '/bin/sh' and d[7] == 'ps' and d[8] == 'axw') or \
     d[-1] == this_script or \
     (d[5].startswith('/usr/bin/python') and d[6] == this_script):
    continue

  # busy process in bold
  if d[2] != '%CPU' and float(d[2]) >= 50:
    d[0] = "<b>%s</b>"%d[0]

  # condor in italic
  if d[0] == "condor":
    d[0] = "<i>%s</i>"%d[0]

  # root and the header are not real people
  if not (d[0] in ("root","USER")):
    users.append(d[0])

  output += "<tr><td>%s</td><td>%s</td></tr>"%("</td><td>".join(d[0:5]), " ".join(d[5:]))
  nprocs += 1

  try:
    totcpu += float(d[2])
    totmem += float(d[3])
  except ValueError:
    continue

# divide by number of cpus
ncpus = os.sysconf("SC_NPROCESSORS_ONLN")
totcpu = totcpu/ncpus

p = Popen("hostname", shell=True, stdout=PIPE, close_fds=True, universal_newlines=True)
hostname = p.stdout.readline().strip().lower().split('.')[0]
#hostname = commands.getoutput("hostname")


query_attributes = ['index','name','utilization.gpu','utilization.memory','memory.total','memory.free','memory.used']
gpu_info = dict()
gpus = list()
for att in query_attributes:
    gpu_info[att]=dict()
gpu = False
###GPU
try:
    p2 = Popen(['nvidia-smi', '--query-gpu='+','.join(query_attributes), '--format=csv'], stdout=PIPE, close_fds=True, universal_newlines=True)
    gpu_csv = csv.reader(p2.stdout, skipinitialspace=True)
    next(gpu_csv)  # skip header row
    for row in gpu_csv:
        name = hostname+'_'+row[0]
        gpus.append(name)
        for i,att in enumerate(query_attributes):
            gpu_info[att][name] = row[i]
    gpu = True
    
except Exception:
    pass
###

#query_attributes = [s.replace('.','') for s in query_attributes]

output = "<tr><td colspan=\"6\"><b>%s</b> (CPU:%.1f%% - MEM:%.1f%%)</td></tr>"%(hostname, totcpu,totmem)+output

dat = "<?php\n"
dat += "$cpu['%s'] = %.1f;\n"%(hostname,totcpu)
if gpu:
    dat += "$gpu['{}'] = array('{}');\n".format(hostname,"', '".join(gpus))
dat += "$mem['%s'] = %.1f;\n"%(hostname,totmem)
dat += "$load['%s'] = array('%s');\n"%(hostname,"', '".join(load))
dat += "$users['%s'] = array('%s');\n"%(hostname,"', '".join(users))
dat += "$time['%s'] = %s;\n"%(hostname, time.time())
dat += "$output['%s'] = '%s';\n"%(hostname,php_escape(output))
if gpu:
    for att in query_attributes:
        for g in gpus:
            dat += "${}['{}'] = '{}';\n".format(att.replace('.',''),g,gpu_info[att][g])
dat += "?>"

# Write atomically: index.php include()s these files (over NFS) on every page
# load, so a reader must never observe a half-written file. Write to a temp
# file in the same directory, then rename it into place in one step.
final = "%s/%s.%s"%(DIR,hostname,EXT)
tmp = "%s.tmp.%d"%(final, os.getpid())
with open(tmp, "w") as f:
    f.write(dat)
os.replace(tmp, final)
