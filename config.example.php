<?php
// Site-local configuration template.
//
// Copy this file to config.local.php and edit it for your installation.
// config.local.php is git-ignored, so your machine names, links, and specs
// stay on your server and out of the public repository.
// Every setting is optional: index.php provides generic defaults for all of them.

// page title
$TITLE = 'My Lab Top';

// contents of the banner at the top of the page,
// with an optional link shown at its right-hand side
$RULES_HTML = '<b>Basic rules:</b> be nice';
$RULES_LINK_URL = 'https://example.org/';
$RULES_LINK_TEXT = 'My Lab';

// families of machines: each family gets its own table,
// machines not in any family are grouped under $families_notes[0]
$families = array(
                   1 => array('host1','host2','host3')
                 );

$families_notes = array( 0 => 'Other machines reporting', // machines not in a family
                         1 => '',
                       );

// basic specs, shown at the bottom of the page (omit or leave empty to hide)
$specs = array(
    'host1' => '40 threads, 504 Gb memory',
    'host2' => '32 threads, 126 Gb memory, 4 GPUs',
);
?>
