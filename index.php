<?php
$seconds_to_cache = 60;
$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
header("Expires: $ts");
header("Pragma: cache");
header("Cache-Control: max-age=$seconds_to_cache");

// ---- generic defaults; override in config.local.php (git-ignored) ----
$TITLE = 'Simple Top';
$RULES_HTML = '<b>Basic rules:</b> be nice';
$RULES_LINK_URL = '';
$RULES_LINK_TEXT = '';
$families = array();
$families_notes = array( 0 => 'Other machines reporting' );
$specs = array();
$DISK_WARN = 85;   // %% full -> amber
$DISK_CRIT = 95;   // %% full -> red
if (file_exists(__DIR__.'/config.local.php')) {
    include(__DIR__.'/config.local.php');
}

$STALE = 590; // seconds without an update before a machine is "unavailable"
?>
<!DOCTYPE html>
<html lang="en">
 <head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php print(htmlspecialchars($TITLE)); ?></title>
  <meta http-equiv="refresh" content="120">
  <style>
  :root{
    --bg:#f6f7f9; --card:#fff; --ink:#1c2128; --muted:#6a737d; --line:#e3e6ea;
    --accent:#2563eb; --good:#16a34a; --warn:#d97706; --crit:#dc2626;
    --chip:#eef1f5; --shadow:0 1px 2px rgba(0,0,0,.06),0 1px 3px rgba(0,0,0,.04);
    --other:#c2cad4;
  }
  @media (prefers-color-scheme:dark){
    :root{
      --bg:#0d1117; --card:#161b22; --ink:#e6edf3; --muted:#8b949e; --line:#2a313c;
      --accent:#4f8cff; --good:#3fb950; --warn:#d29922; --crit:#f85149;
      --chip:#21262d; --shadow:none; --other:#4b545f;
    }
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
    font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}
  a{color:var(--accent);text-decoration:none} a:hover{text-decoration:underline}
  .wrap{max-width:1200px;margin:0 auto;padding:16px}
  h2{font-size:15px;font-weight:700;margin:26px 0 10px;letter-spacing:.02em;text-transform:uppercase;color:var(--muted)}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);overflow:hidden}
  .pad{padding:12px 14px}

  /* banner */
  .banner{display:flex;align-items:center;justify-content:space-between;gap:12px;
    background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px 16px;box-shadow:var(--shadow)}

  /* summary strip */
  .strip{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}
  .stat{flex:1 1 150px;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px;box-shadow:var(--shadow)}
  .stat .n{font-size:22px;font-weight:750;line-height:1.1}
  .stat .l{font-size:12px;color:var(--muted);margin-top:2px}
  .stat .sub{font-size:12px;color:var(--muted);margin-top:4px}

  /* machine table */
  table{border-collapse:collapse;width:100%}
  th{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);text-align:left;font-weight:600;padding:8px 10px;border-bottom:1px solid var(--line)}
  td{padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
  tr:last-child td{border-bottom:none}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px}
  .host{font-weight:700}
  .muted{color:var(--muted)}
  .right{text-align:right}
  .nowrap{white-space:nowrap}

  /* saturation bar */
  .bar{position:relative;height:16px;border-radius:8px;background:var(--chip);overflow:hidden;min-width:90px}
  .bar > span{position:absolute;inset:0 auto 0 0;border-radius:8px}
  .bar > em{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    font-size:10.5px;font-style:normal;font-weight:650;mix-blend-mode:normal}
  .badge{display:inline-block;font-size:11px;font-weight:650;padding:1px 7px;border-radius:20px;background:var(--chip);color:var(--muted)}
  .badge.free{background:color-mix(in srgb,var(--good) 18%,transparent);color:var(--good)}
  .badge.rec{background:color-mix(in srgb,var(--accent) 16%,transparent);color:var(--accent)}
  .dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:baseline}
  .spark{display:block;color:var(--accent)}
  .spark polyline{fill:none;stroke:currentColor;stroke-width:1.4;stroke-linejoin:round;stroke-linecap:round}
  details{margin-top:6px} summary{cursor:pointer;color:var(--muted);font-size:12px}
  summary:hover{color:var(--accent)}
  .grp td{border-bottom:1px dashed var(--line)}
  .gpucard{display:flex;flex-wrap:wrap;gap:10px}
  .gpu{flex:1 1 220px;border:1px solid var(--line);border-radius:10px;padding:10px 12px;background:var(--card)}
  .gpu.free{border-color:color-mix(in srgb,var(--good) 45%,var(--line))}
  .gpu .top{display:flex;justify-content:space-between;align-items:baseline;gap:8px}
  .gpu .nm{font-size:12px;color:var(--muted)}
  .h2row{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:26px 0 10px}
  .h2row h2{margin:0}
  #uf{padding:5px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);font-size:13px;width:210px;max-width:55vw}
  .dim{opacity:.28} .hit{background:color-mix(in srgb,var(--accent) 12%,transparent)}
  .warnrow{color:var(--warn)} .critrow{color:var(--crit);font-weight:650}
  .foot{color:var(--muted);font-size:12px;margin:24px 0 8px;text-align:center}
  .bar.mini{height:8px;min-width:50px}
  .mrow{display:flex;align-items:center;gap:8px;margin-top:4px;font-size:11px}
  .mrow .k{width:26px;color:var(--muted);text-transform:uppercase;letter-spacing:.02em}
  .mrow .bar{flex:1}
  .mrow .v{width:60px;text-align:right;color:var(--muted);font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
  .chip{display:inline-flex;align-items:baseline;gap:5px;background:var(--chip);border-radius:20px;padding:1px 9px;font-size:11px;margin:0 5px 4px 0}
  .dhead{display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap}
  .stack{display:flex;height:22px;border-radius:8px;overflow:hidden;margin-top:10px;
    background:color-mix(in srgb,var(--good) 22%,transparent)}
  .stack > span{height:100%;transition:width .3s}
  .stack > span + span{box-shadow:inset 1px 0 0 rgba(255,255,255,.35)}
  .legend{margin-top:9px;display:flex;flex-wrap:wrap}
  @media (max-width:640px){ .hide-sm{display:none} h2{margin-top:20px} }
  </style>
 </head>
<body>
<div class="wrap">
<?php
$EXT = 'dat';
$DIR = __DIR__ . '/';

$cpu=$mem=$cores=$ram=$load=$users=$time=$procs=$disk=$output=array();
$gpu=$gpuindex=$name=$utilizationgpu=$utilizationmemory=array();
$memorytotal=$memoryfree=$memoryused=$temperaturegpu=$powerdraw=$gpuusers=array();
$dutime=$duusers=array(); // per-user disk usage, refreshed nightly by diskusage.py
// (older .dat files also set $index; harmless)

if (is_dir($DIR)) {
    if ($dh = opendir($DIR)) {
        while (($f = readdir($dh)) !== false) {
            $e = pathinfo($f, PATHINFO_EXTENSION);
            if (($e === $EXT || $e === 'du') && is_file($DIR.$f)) {
                include($DIR.$f);
            }
        }
        closedir($dh);
    }
}

$now = time();
$all = array_keys($cpu);
sort($all);

// ---------------- helpers ----------------
function h($s){ return htmlspecialchars($s, ENT_QUOTES); }

function clean_users($arr){
    $o = array();
    if (!is_array($arr)) return $o;
    foreach ($arr as $u){ $u = trim(strip_tags($u)); if ($u!=='') $o[] = $u; }
    return $o;
}
function is_up($host){ global $time,$now,$STALE; return isset($time[$host]) && ($now-$time[$host]) <= $STALE; }

function sat_ratio($host){ // load1 per core, or null if unknown
    global $cores,$load;
    if (empty($cores[$host]) || !isset($load[$host][0])) return null;
    return floatval($load[$host][0]) / $cores[$host];
}
function free_cores($host){
    global $cores,$load;
    if (empty($cores[$host]) || !isset($load[$host][0])) return null;
    return max(0, $cores[$host] - floatval($load[$host][0]));
}
function hue_bar($frac){ // 0=green .. 1=red, clamped
    $frac = max(0, min(1, $frac));
    $hue = 120 - 120*$frac;
    return "hsl($hue,68%,45%)";
}
function bar($frac, $label, $title=''){
    $w = max(2, min(100, $frac*100));
    $col = hue_bar($frac);
    $t = $title!=='' ? ' title="'.h($title).'"' : '';
    return '<div class="bar"'.$t.'><span style="width:'.round($w,1).'%;background:'.$col.'"></span><em>'.h($label).'</em></div>';
}
function pbar($frac){ // compact, unlabeled bar
    $w = max(2, min(100, $frac*100));
    return '<div class="bar mini"><span style="width:'.round($w,1).'%;background:'.hue_bar($frac).'"></span></div>';
}
function fmt_ago($secs){
    $secs = max(0,(int)$secs);
    if ($secs < 90) return $secs.'s ago';
    if ($secs < 5400) return round($secs/60).'m ago';
    if ($secs < 172800) return round($secs/3600).'h ago';
    return round($secs/86400).'d ago';
}
function fmt_gb($gb){ // compact size label from a GB value
    if ($gb >= 1000) return number_format($gb/1000,1).' TB';
    if ($gb >= 10)   return number_format($gb,0).' GB';
    return number_format($gb,1).' GB';
}
function ucolor($name){ // stable colour for a username, consistent across servers
    global $UCOLORS, $ucolor_map;
    if (isset($ucolor_map[$name])) return $ucolor_map[$name];
    return $UCOLORS[(crc32(strtolower($name)) & 0x7fffffff) % count($UCOLORS)];
}
function prog_of($cmd){ // short program name from a command line
    $cmd = trim(strip_tags($cmd));
    $first = strtok($cmd, ' ');
    if ($first === false) return '?';
    $base = basename($first);
    // interpreters: show the script instead of "python"/"node" when obvious
    if (preg_match('/^(python[0-9.]*|perl|ruby|node|bash|sh|java|Rscript)$/', $base)){
        $rest = trim(substr($cmd, strlen($first)));
        $tok = strtok($rest, ' ');
        while ($tok !== false && ($tok==='' || $tok[0]==='-')) $tok = strtok(' ');
        if ($tok !== false && $tok!=='') $base .= ' '.basename($tok);
    }
    return $base;
}
function hist_col($host, $col){
    global $DIR;
    $file = $DIR.$host.'.hist';
    if (!is_file($file)) return array();
    $out = array();
    foreach (file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
        $p = explode(',', $line);
        if (isset($p[$col]) && $p[$col] !== '') $out[] = floatval($p[$col]);
    }
    return $out;
}
function sparkline($vals, $w=110, $hgt=22, $max=100){
    $n = count($vals);
    if ($n < 2) return '';
    $pts = '';
    for ($i=0;$i<$n;$i++){
        $x = $i*($w-2)/($n-1)+1;
        $v = $max>0 ? min($vals[$i],$max)/$max : 0;
        $y = $hgt-1 - $v*($hgt-2);
        $pts .= round($x,1).','.round($y,1).' ';
    }
    return '<svg class="spark" width="'.$w.'" height="'.$hgt.'" viewBox="0 0 '.$w.' '.$hgt.'" preserveAspectRatio="none"><polyline points="'.trim($pts).'"/></svg>';
}

// GPU helpers
function gpu_util($g){ global $utilizationgpu; return isset($utilizationgpu[$g]) ? floatval(str_replace('%','',$utilizationgpu[$g])) : 0; }
function gpu_is_free($g){
    global $gpuusers;
    if (isset($gpuusers[$g])) return count($gpuusers[$g])===0;
    return gpu_util($g) < 5; // fallback for old .dat without owners
}

// ---------------- aggregate the fleet ----------------
$responding = array_values(array_filter($all, 'is_up'));
$down = array();
$total_cores = 0; $sum_load = 0.0; $have_core_data = false;
$all_users = array();
$gpu_total = 0; $gpu_free = 0; $gpu_hosts = array();
foreach ($responding as $host){
    if (!empty($cores[$host])){ $total_cores += $cores[$host]; $sum_load += isset($load[$host][0])?floatval($load[$host][0]):0; $have_core_data = true; }
    foreach (clean_users($users[$host]) as $u) $all_users[$u] = true;
    if (!empty($gpu[$host])){
        $gpu_hosts[] = $host;
        foreach ($gpu[$host] as $g){ $gpu_total++; if (gpu_is_free($g)) $gpu_free++; }
    }
}
$fleet_busy = ($have_core_data && $total_cores>0) ? 100*$sum_load/$total_cores : null;

// freest / busiest
$freest = null; $freest_v = -1; $busiest = null; $busiest_v = -1;
foreach ($responding as $host){
    $fc = free_cores($host); if ($fc!==null && $fc>$freest_v){ $freest_v=$fc; $freest=$host; }
    $sr = sat_ratio($host); if ($sr!==null && $sr>$busiest_v){ $busiest_v=$sr; $busiest=$host; }
}

// ---------------- banner ----------------
$rules_link = '';
if ($RULES_LINK_URL != '')
    $rules_link = '<a href="'.h($RULES_LINK_URL).'" style="color:inherit;text-decoration:underline;font-weight:normal;font-size:13px">'.h($RULES_LINK_TEXT).' &raquo;</a>';
print('<div class="banner"><div>'.$RULES_HTML.'</div><div>'.$rules_link.'</div></div>');

// ---------------- available machines ----------------
// the user filter floats at the top-right of the Machines heading
print('<div class="h2row"><h2>Machines</h2><input id="uf" type="search" placeholder="highlight a user\'s jobs…" oninput="ufilter(this.value)" autocomplete="off"></div>');
$listed = $all;
for ($i = count($families); $i >= 0; $i--){
    if ($i == 0){ $todo = $listed; }
    else { $todo = array_values(array_intersect($listed, $families[$i])); $listed = array_values(array_diff($listed, $todo)); }
    sort($todo);
    $todo = array_values(array_filter($todo, 'is_up'));
    if (empty($todo)) continue;

    print('<div class="card" style="margin-bottom:12px"><table><thead><tr>');
    print('<th>Server</th><th>Saturation (load / cores)</th><th class="hide-sm">Mem</th><th class="hide-sm">GPU</th><th>Who&rsquo;s on it</th><th class="right nowrap">Updated</th>');
    print('</tr></thead><tbody>');
    foreach ($todo as $host){
        $sr = sat_ratio($host);
        if ($sr!==null){
            $lbl = round($sr*100).'%  ('.h($load[$host][0]).' / '.(int)$cores[$host].')';
            $barhtml = bar($sr, $lbl);
        } else {
            $barhtml = '<span class="mono muted">load '.(isset($load[$host][0])?h($load[$host][0]):'?').'</span>';
        }
        // memory (bar, GB detail on hover)
        if (isset($mem[$host])){
            $mtitle = isset($ram[$host][0]) ? round($ram[$host][0]-$ram[$host][1]).' / '.round($ram[$host][0]).' GB used' : '';
            $memtxt = bar($mem[$host]/100, round($mem[$host]).'%', $mtitle);
        } else $memtxt = '<span class="muted">?</span>';
        // gpu quick count
        $gtxt='&ndash;';
        if (!empty($gpu[$host])){ $gf=0;$gt=0; foreach($gpu[$host] as $g){$gt++; if(gpu_is_free($g))$gf++;} $gtxt = $gf.'/'.$gt.' free'; }
        // users summary (per-user process + cpu rollup from $procs)
        $usum = machine_user_summary($host);
        // spark
        $spark = sparkline(hist_col($host,1)); // col1 = cpu busy %
        // freshness
        $ago = isset($time[$host]) ? fmt_ago($now-$time[$host]) : '?';
        $freebadge = (free_cores($host)!==null && free_cores($host) >= 0.5*$cores[$host]) ? ' <span class="badge free">free</span>' : '';

        printf('<tr id="%s" data-users="%s"><td class="nowrap"><span class="dot" style="background:%s"></span><a href="#d_%s" class="host">%s</a>%s<div class="hide-sm">%s</div></td>'.
               '<td style="min-width:150px">%s</td><td class="hide-sm">%s</td><td class="hide-sm nowrap">%s</td><td>%s</td><td class="right muted nowrap">%s</td></tr>',
               h($host), h(strtolower(implode(' ', array_keys(user_counts($host))))),
               ($sr!==null?hue_bar($sr):'var(--muted)'), h($host), h($host), $freebadge, $spark,
               $barhtml, $memtxt, $gtxt, $usum, $ago);
    }
    print('</tbody></table></div>');
}

// ---------------- GPUs ----------------
if ($gpu_total>0){
    print('<h2>GPUs</h2><div class="gpucard">');
    sort($gpu_hosts);
    foreach ($gpu_hosts as $host){
        foreach ($gpu[$host] as $g){
            $free = gpu_is_free($g);
            $util = isset($utilizationgpu[$g]) ? floatval($utilizationgpu[$g]) : 0;   // %
            $mu = isset($memoryused[$g]) ? floatval($memoryused[$g]) : 0;             // MiB
            $mt = isset($memorytotal[$g]) ? floatval($memorytotal[$g]) : 0;           // MiB
            $memfrac = $mt>0 ? $mu/$mt : 0;
            $nm = isset($name[$g]) ? $name[$g] : '';
            $temp = isset($temperaturegpu[$g]) ? floatval($temperaturegpu[$g]) : null;
            $pow = isset($powerdraw[$g]) ? floatval($powerdraw[$g]) : null;
            $owners = isset($gpuusers[$g]) ? $gpuusers[$g] : array();
            $idx = preg_replace('/^.*_/', '', $g);
            if (count($owners)){
                $who = '';
                foreach ($owners as $o){
                    list($on,$om) = owner_parts($o);
                    $omtxt = $om===null ? '' : '<span class="muted">'.($om<1?'&lt;1':$om).'G</span>';
                    $who .= '<span class="chip"><span class="host">'.h($on).'</span>'.$omtxt.'</span>';
                }
            } else $who = '<span class="badge free">free</span>';
            $meta = array();
            if ($temp!==null) $meta[] = round($temp).'&deg;';
            if ($pow!==null) $meta[] = round($pow).'W';
            printf('<div class="gpu%s" data-users="%s"><div class="top"><div><a href="#%s" class="host">%s</a> <span class="muted">#%s</span></div><div class="mono muted" style="font-size:11px">%s</div></div>'.
                   '<div class="nm">%s</div><div style="margin:7px 0 3px">%s</div>'.
                   '<div class="mrow"><span class="k">util</span>%s<span class="v">%d%%</span></div>'.
                   '<div class="mrow"><span class="k">mem</span>%s<span class="v">%d/%d G</span></div></div>',
                   $free?' free':'', h(strtolower(implode(' ', array_map('gpu_owner_name',$owners)))),
                   h($host), h($host), h($idx), implode(' &middot; ', $meta),
                   h($nm), $who,
                   pbar($util/100), round($util),
                   pbar($memfrac), round($mu/1024), round($mt/1024));
        }
    }
    print('</div>');
}

// ---------------- unavailable ----------------
$declared = array();
foreach ($families as $fam) $declared = array_merge($declared, $fam);
$unavailable = array_values(array_diff(array_merge($all, $declared), $responding));
sort($unavailable);
if (count($unavailable)){
    print('<h2>Unavailable</h2><div class="card pad"><ul style="margin:0;padding-left:18px">');
    foreach ($unavailable as $host){
        if (isset($time[$host]))
            printf('<li><a href="#%s">%s</a> &mdash; last seen %s</li>', h($host), h($host), fmt_ago($now-$time[$host]));
        else
            printf('<li><a href="#%s">%s</a> &mdash; never reported</li>', h($host), h($host));
    }
    print('</ul></div>');
}

// ---------------- detailed per-machine ----------------
print('<h2>Detailed processes</h2>');
foreach ($responding as $host){
    printf('<div class="card" style="margin-bottom:10px" id="d_%s"><div class="pad"><span class="host">%s</span> <span class="muted">CPU %s%% &middot; mem %s%% &middot; load %s &middot; %s</span></div>',
            h($host), h($host),
            isset($cpu[$host])?round($cpu[$host]):'?', isset($mem[$host])?round($mem[$host]):'?',
            isset($load[$host][0])?h($load[$host][0]):'?', isset($time[$host])?fmt_ago($now-$time[$host]):'?');

    if (!empty($procs[$host]) && is_array($procs[$host])){
        // aggregate by (user, program)
        $groups = array();   // key -> [user, prog, count, cpu, mem]
        $full = array();
        foreach ($procs[$host] as $p){
            if (!isset($p[2]) || $p[2]==='%CPU') continue;
            $u = strip_tags($p[0]); $prog = prog_of($p[5]);
            $k = $u.'|'.$prog;
            if (!isset($groups[$k])) $groups[$k] = array($u,$prog,0,0.0,0.0);
            $groups[$k][2]++; $groups[$k][3]+=floatval($p[2]); $groups[$k][4]+=floatval($p[3]);
            $full[] = $p;
        }
        uasort($groups, function($a,$b){ return $b[3] <=> $a[3]; });
        print('<table><thead><tr><th>User</th><th>What</th><th class="right">Procs</th><th class="right">CPU</th><th class="right hide-sm">Mem</th></tr></thead><tbody>');
        foreach ($groups as $g){
            printf('<tr class="grp" data-users="%s"><td class="host">%s</td><td class="mono">%s%s</td><td class="right">%d</td><td class="right mono">%s%%</td><td class="right mono hide-sm">%s%%</td></tr>',
                    h(strtolower($g[0])), h($g[0]), h($g[1]), $g[2]>1?' <span class="muted">&times;'.$g[2].'</span>':'',
                    $g[2], round($g[3]), round($g[4],1));
        }
        print('</tbody></table>');
        // full list, collapsed
        printf('<details><summary>all %d processes</summary><table class="mono"><tbody>', count($full));
        foreach ($full as $p){
            $busy = floatval($p[2])>=50 ? ' style="font-weight:650"' : '';
            printf('<tr data-users="%s"><td%s>%s</td><td class="right">%s%%</td><td class="right">%s%%</td><td class="right muted">%s</td><td style="word-break:break-all">%s</td></tr>',
                    h(strtolower(strip_tags($p[0]))), $busy, h(strip_tags($p[0])), h($p[2]), h($p[3]), h($p[4]), $p[5]);
        }
        print('</tbody></table></details>');
    } elseif (!empty($output[$host])){
        print('<table>'.$output[$host].'</table>'); // fallback for old .dat
    }
    print('</div>');
}

// ---------------- top users ----------------
$tu_cpu = array(); $tu_cnt = array();
foreach ($responding as $host){
    if (empty($procs[$host])) continue;
    foreach ($procs[$host] as $p){
        if (!isset($p[2]) || $p[2]==='%CPU') continue;
        $u = strip_tags($p[0]);
        if ($u==='' || $u==='root') continue;
        $tu_cpu[$u] = (isset($tu_cpu[$u])?$tu_cpu[$u]:0) + floatval($p[2]);
        $tu_cnt[$u] = (isset($tu_cnt[$u])?$tu_cnt[$u]:0) + 1;
    }
}
arsort($tu_cpu);
if (count($tu_cpu)){
    print('<h2>Top users</h2><div class="card pad"><table><thead><tr><th>User</th><th class="right">Processes</th><th class="right">Total CPU</th></tr></thead><tbody>');
    foreach ($tu_cpu as $u=>$c){
        printf('<tr data-users="%s"><td class="host">%s</td><td class="right">%d</td><td class="right mono">%s cores</td></tr>',
                h(strtolower($u)), h($u), $tu_cnt[$u], number_format($c/100,1));
    }
    print('</tbody></table></div>');
}

// ---------------- disk pressure (with per-user breakdown) ----------------
$UCOLORS = array('#4f8cff','#f2994a','#27ae60','#eb5757','#9b51e0','#17becf',
                 '#e0a000','#e056a0','#00b8a3','#c0563b','#7d8ca3','#b07cd6',
                 '#8c6d31','#6c7ae0');
$disk_rows = array();
foreach ($responding as $host){
    if (empty($disk[$host])) continue;
    foreach ($disk[$host] as $mount=>$info){
        if ($info[0] >= $DISK_WARN) $disk_rows[] = array($host,$mount,$info);
    }
}
if (count($disk_rows)){
    usort($disk_rows, function($a,$b){ return $b[2][0] <=> $a[2][0]; });
    // Assign colours by total usage rank across all shown disks, so the biggest
    // (most visible) users always get distinct colours and the same user keeps
    // one colour everywhere. Hashing alone collided big users (e.g. two 800GB
    // users landing on the same slot); ranking avoids that for the ones on show.
    $utotal = array();
    foreach ($disk_rows as $r){
        if (empty($duusers[$r[0]][$r[1]])) continue;
        foreach ($duusers[$r[0]][$r[1]] as $u=>$gb)
            $utotal[$u] = (isset($utotal[$u]) ? $utotal[$u] : 0) + $gb;
    }
    arsort($utotal);
    $ucolor_map = array(); $rk = 0;
    foreach ($utotal as $u=>$t){ $ucolor_map[$u] = $UCOLORS[$rk % count($UCOLORS)]; $rk++; }
    print('<h2>Disk pressure <span class="muted" style="font-weight:400;font-size:.7em">bar shows which users fill each disk</span></h2>');
    foreach ($disk_rows as $r){
        list($host,$mount,$info) = $r;
        list($pct,$used_gb,$total_gb) = $info;
        $cls = $pct >= $DISK_CRIT ? 'critrow' : 'warnrow';
        printf('<div class="card pad" style="margin-bottom:10px"><div class="dhead">'.
               '<div><a href="#%s" class="host">%s</a> <span class="mono">%s</span> &middot; <span class="%s">%s%% full</span></div>'.
               '<div class="mono muted">%s / %s</div></div>',
               h($host), h($host), h($mount), $cls, round($pct), fmt_gb($used_gb), fmt_gb($total_gb));

        $users = isset($duusers[$host][$mount]) ? $duusers[$host][$mount] : array();
        if ($users && $total_gb > 0){
            // stacked bar over the whole disk: top users coloured, the rest of
            // the used space grouped as "other", the remainder shown as free.
            $seg = ''; $legend = ''; $shown_gb = 0; $i = 0; $TOPN = 8;
            foreach ($users as $u=>$gb){
                if ($i >= $TOPN) break;
                $w = $gb/$total_gb*100; $col = ucolor($u); $shown_gb += $gb;
                $seg .= sprintf('<span style="width:%.3f%%;background:%s" title="%s: %s (%.0f%% of disk)"></span>',
                                $w, $col, h($u), fmt_gb($gb), $w);
                $legend .= sprintf('<span class="chip"><span class="dot" style="background:%s"></span>%s <span class="muted">%s &middot; %.0f%%</span></span>',
                                $col, h($u), fmt_gb($gb), $w);
                $i++;
            }
            $other = $used_gb - $shown_gb;
            if ($other > 0.5){
                $w = $other/$total_gb*100;
                $seg .= sprintf('<span style="width:%.3f%%;background:var(--other)" title="other used: %s (%.0f%%)"></span>', $w, fmt_gb($other), $w);
                $legend .= sprintf('<span class="chip"><span class="dot" style="background:var(--other)"></span>other used <span class="muted">%s</span></span>', fmt_gb($other));
            }
            $legend .= sprintf('<span class="chip"><span class="dot" style="background:color-mix(in srgb,var(--good) 45%%,transparent)"></span>free <span class="muted">%s</span></span>', fmt_gb(max(0,$total_gb-$used_gb)));
            $ago = isset($dutime[$host]) ? ' &middot; measured '.h(fmt_ago($now-$dutime[$host])) : '';
            printf('<div class="stack" title="%s of %s used">%s</div><div class="legend">%s</div>'.
                   '<div class="muted" style="font-size:11px;margin-top:4px">who\'s using it%s</div>',
                   fmt_gb($used_gb), fmt_gb($total_gb), $seg, $legend, $ago);
        } else {
            // no per-user data for this filesystem (e.g. a shared/NFS mount)
            printf('<div style="margin-top:6px;max-width:300px">%s</div>', bar($pct/100, round($pct).'% full'));
        }
        print('</div>');
    }
}

// ---------------- specs ----------------
if (count($specs)){
    print('<h2>Specifications</h2><div class="card pad"><table><tbody>');
    foreach ($specs as $m=>$d)
        printf('<tr><td class="host">%s</td><td class="muted">%s</td></tr>', h($m), h($d));
    print('</tbody></table></div>');
}

print('<div class="foot">auto-refreshes every 2 min &middot; '.date('H:i:s').' server time</div>');

// ----- functions that need globals -----
function user_counts($host){
    global $procs;
    $c = array();
    if (empty($procs[$host])) return $c;
    foreach ($procs[$host] as $p){
        if (!isset($p[2]) || $p[2]==='%CPU') continue;
        $u = strip_tags($p[0]); if ($u==='') continue;
        $c[$u] = (isset($c[$u])?$c[$u]:0)+1;
    }
    arsort($c);
    return $c;
}
function machine_user_summary($host){
    $c = user_counts($host);
    if (empty($c)) return '<span class="muted">&hellip;idle&hellip;</span>';
    $parts = array();
    foreach ($c as $u=>$n){ $parts[] = '<span class="host">'.h($u).'</span>'.($n>1?' <span class="muted">&times;'.$n.'</span>':''); }
    return implode(', ', $parts);
}
function gpu_owner_name($s){ return preg_replace('/\s*\(.*$/','',strip_tags($s)); }
function owner_parts($s){ // "name (19332MiB)" -> array('name', 19)
    if (preg_match('/^(.*?)\s*\((\d+)\s*MiB\)\s*$/', strip_tags($s), $m))
        return array($m[1], (int)round($m[2]/1024));
    return array(strip_tags($s), null);
}
?>
</div>
<script>
function ufilter(q){
  q = (q||'').trim().toLowerCase();
  document.querySelectorAll('[data-users]').forEach(function(el){
    el.classList.remove('dim','hit');
    if(!q) return;
    var u = el.getAttribute('data-users')||'';
    if(u.split(' ').some(function(x){return x && x.indexOf(q)>=0;})) el.classList.add('hit');
    else el.classList.add('dim');
  });
}
</script>
</body>
</html>
