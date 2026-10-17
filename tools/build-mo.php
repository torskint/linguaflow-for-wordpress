<?php

// Compile languages/linguaflow-for-wordpress-fr_FR.po en .mo avec les classes du plugin (aucun outil gettext requis).
// Usage : php tools/build-mo.php

require __DIR__.'/../vendor/autoload.php';

use Torskint\LinguaFlowWp\Support\MoWriter;
use Torskint\LinguaFlowWp\Support\PoParser;

$po = __DIR__.'/../languages/linguaflow-for-wordpress-fr_FR.po';
$entries = (new PoParser)->parse((string) file_get_contents($po));

file_put_contents(preg_replace('/\.po$/', '.mo', $po), (new MoWriter)->mo($entries));
echo count((new MoWriter)->messages($entries))." messages compilés.\n";
