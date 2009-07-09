<?php

#ob_start("ob_gzhandler");

require_once "../lib/vistable.php";

$tqx = isset($_GET['tqx']) ? $_GET['tqx'] : "";
$tq = isset($_GET['tq']) ? $_GET['tq'] : "";
$tqrt = isset($_GET['tqrt']) ? $_GET['tqrt'] : "";
$tz = isset($_GET['tz']) ? $_GET['tz'] : "PDT";
$locale = isset($_SERVER["HTTP_ACCEPT_LANGUAGE"]) ? $_SERVER["HTTP_ACCEPT_LANGUAGE"] : "en_US";

if (isset($_GET['locale'])) {
    $locale = $_GET['locale'];
}

$file = '../data/data.csv';

$extra = array();
if (isset($_GET['debug']) && $_GET['debug']) {
    header('Content-type: text/plain; charset="UTF-8"');
    $extra['debug'] = 1;
}

$vt = new csv_vistable($tqx, $tq, $tqrt,$tz,$locale,$extra);

$data = file_get_contents($file);
if ($file === FALSE) {
    die("Couldnt read `$file'");
}

$vt->setup_table($data);

$result = $vt->execute();

echo $result;

#ob_end_flush();
