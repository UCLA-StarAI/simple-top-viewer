<?php
$seconds_to_cache = 250;
$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
header("Expires: $ts");
header("Pragma: cache");
header("Cache-Control: max-age=$seconds_to_cache");

// generic defaults; override them in config.local.php (not in git),
// see config.example.php for a documented template
$TITLE = 'Simple Top';
$RULES_HTML = '<b>Basic rules:</b> be nice';
$RULES_LINK_URL = '';   // optional link shown at the right of the rules banner
$RULES_LINK_TEXT = '';
$families = array();    // families of machines, e.g. array( 1 => array('host1','host2') )
$families_notes = array( 0 => 'Other machines reporting' ); // machines not in a family
$specs = array();       // hostname => description, shown in the Specifications section
if (file_exists(__DIR__.'/config.local.php')) {
    include(__DIR__.'/config.local.php');
}
?>
<!DOCTYPE html>
<html>
 <head>
  <title><?php print($TITLE); ?></title>
  <meta http-equiv="refresh" content="300" >
  <link href="css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" type="text/css" href="css/style.css" media="screen" />
 </head>

<body>
<?php
/*ini_set('display_errors', 1);
error_reporting(E_ALL);*/
$EXT = 'dat';
$DIR = __DIR__ . '/'; // .dat files live next to this script

$cpu = array();
$mem = array();
$output = array();
$gpu = array();
$index = array();
$name = array();
$utilizationgpu = array();
$utilizationmemory = array();
$memorytotal = array();
$memoryfree = array();
$memoryused = array();

// Open a known directory, and proceed to read its contents
if (is_dir($DIR)) {
    if ($dh = opendir($DIR)) {
        while (($f = readdir($dh)) !== false) {
            $file = $DIR.$f;
            if (is_file($file) && pathinfo($file, PATHINFO_EXTENSION) == $EXT) {
                //print("Loading ".$file."<br />\n");
                include($file);
            }else{
                //print("Not loading ".$file."<br />\n");
            }
        }
        closedir($dh);
    }
}

//print_r($output);
//print_r($cpu);

function color_bg($percent) {
  return 'style="background-color: hsl('.(120-120*$percent/100).', 100%, 85%);"';
}

asort($cpu);
$time_local = time();
$not_responding = array();
$top_users = array();
$all = array_keys($cpu);
$gpu_machines = array_keys($gpu);

$rules_link = '';
if ($RULES_LINK_URL != '')
    $rules_link = '<a href="'.$RULES_LINK_URL.'" style="position:absolute; right:15px; top:50%; transform:translateY(-50%); pointer-events:auto; color:inherit; text-decoration:underline; font-weight:normal;">'.$RULES_LINK_TEXT.' &raquo;</a>';
print('<div class="btn btn-large btn-block disabled" type="button" style="position:relative;">'.$RULES_HTML.$rules_link.'</div>');

print('<div class="left"><h3>Available machines</h3>');
for ($i = count($families); $i >= 0; $i--) {
  // filter families
  $todo = array();
  if ($i == 0) {
    $todo = $all;
  } else {
    $todo = array_intersect($all, $families[$i]);
    $all = array_diff($all, $todo);
  }
  asort($todo);

  if (!empty($todo)) {
      // filler above families
      printf('<div>');
      //printf('<h5>%s</h5>', $families_notes[$i]);

      // start table
      print('<table class="table table-bordered table-condensed">');
      print('<tr><th>Server</th><th>CPU</th><th>MEM</th><th>Load</th><th colspan="9">Users <i>(bold = cpu-intensive process)</i> </th></tr>');

      // the loop
      foreach($todo as $key) {
        $usr_counts = array_count_values($users[$key]);
        $uss = '';
        foreach ($usr_counts as $usr => $count) {
          $uss .= "{$count} x {$usr}, ";
          if (array_key_exists($usr, $top_users))
            $top_users[$usr] += $count;
          else
            $top_users[$usr] = $count;
        }
        $uss = substr($uss,0,-2);
        if ($uss == '1 x ') { // there are no users...
            $uss = "<i>...crickets...</i>";
        }
        if ($time_local - round(@$time[$key]) > 590) {
          array_push($not_responding, $key);
        } else {
          // property of TR (class="success, error, warning, info")
          $tr_prop = '';

          $myload = $load[$key][0];
          $myusers = count($users[$key]);
          if (false && $myusers < floatval($myload)*0.75) {
            // too much load, probably swapping, warn
            $tr_prop = ' class="error"';
          }
          if (floatval($cpu[$key]) < 0.1 &&
              floatval($mem[$key]) < 0.1 &&
              floatval($myload) < 0.1)
            $tr_prop = ' class="success"';

          printf('<tr%s> <td><a href="#%s">%s</a></td> <td %s>%.1f%%</td> <td %s>%.1f%%</td> <td><i>%s</i></td><td>%s</td></tr>',
                  $tr_prop, $key, $key, color_bg($cpu[$key]), $cpu[$key], color_bg($mem[$key]), $mem[$key], $myload, $uss);
          //print('<tr><td>'.$key.'</td><td><a href="'.$value.'</td><td>'.$cpu[$value].'</td>
          //      <td>'.$key.'</td><td>'.$mem_keys[$key].'</td><td>'.$mem[$mem_keys[$key]].'</td><tr>');
        }
      }

      print('</table></div>');
  }
}
print('</div>');



//----->GPU
print('<div class="left"><h3>GPUs</h3>');
print('<div>');

// start table
print('<table class="table table-bordered table-condensed">');
print('<tr><th>Machine</th><th>Index</th><th>GPU</th><th>MEM</th><th>Name</th><th>Free memory</th><th>Used memory</th></tr>');

asort($gpu_machines);
foreach($gpu_machines as $key) {
    $gpus = $gpu[$key];
    foreach($gpus as $gpu_key) {
        $ind = $index[$gpu_key];
        $nam = $name[$gpu_key];
        $gpuload = $utilizationgpu[$gpu_key];
        $gpumem = $utilizationmemory[$gpu_key];
        $freemem = $memoryfree[$gpu_key];
        $usedmem = $memoryused[$gpu_key];
        printf('<tr> <td><a  href="#%s">%s</a></td> <td>%s</td> <td %s>%s</td> <td %s>%s</td> <td>%s</td> <td>%s</td> <td>%s</td> </tr>',
                $key,$key,$ind,color_bg($gpuload),$gpuload,color_bg($gpumem),$gpumem,$nam,$freemem,$usedmem);
        }

}

print('</table></div>');
print('</div>');
//<---GPU

// machines declared in a family that have never reported at all (no .dat file)
$declared = array();
foreach ($families as $fam)
    $declared = array_merge($declared, $fam);
foreach (array_diff($declared, array_keys($cpu)) as $key)
    $not_responding[] = $key;

// the non-responding machines, including static ones
if (count($not_responding) > 0) {
    sort($not_responding);
    print('<div class="left"><h3>Unavailable machines</h3><ul>');
    foreach($not_responding as $key) {
        if (isset($time[$key]))
            printf('<li><a href="#%s">%s</a>, no data received since %s</li>',
                    $key, $key, date('l jS \of F, G:i:s', round($time[$key])));
        else
            printf('<li><a href="#%s">%s</a>, no data received (never reported)</li>',
                    $key, $key);
    }
    print('</ul><p></p></div>');
}

print('<div class="left"><h3>Detailed machine information</h3>');
ksort($output);
foreach ($output as $key => $value) {
    print('<a name="'.$key.'"><h4>'.$key.'</h4></a>');
    print('<table class="table table-bordered table-condensed">');
    print($value);
    print('</table>');
}
print('</div>');

if (count($top_users) != 0) {
    arsort($top_users);
    print('<div class="left"><h3>Top users</h3><ol class="unstyled">');
    foreach ($top_users as $u => $c) {
        if ($u != '')
            print('<li><i>'.$u.'</i>: '.$c.' processes</li>');
    }
    print('</ol></div>');
}

// show some basic specs
if (count($specs) != 0) {
    print('<div class="left"><h3>Specifications</h3><ol class="unstyled">');
    foreach ($specs as $machine => $description)
        print('<li><i>'.$machine.'</i>: '.$description.'</li>');
    print('</ol></div>');
}

?>

</body>

</html>
