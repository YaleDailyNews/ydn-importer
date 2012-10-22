<?php 
gc_enable();
define('DOING_AJAX', true);
define('WP_USE_THEMES', false);
DEFINE('PROFILE', true);
$_SERVER = array(
  "HTTP_HOST" => "yaledailynews.staging.wpengine.com",
  "SERVER_NAME" => "yaledailynews.staging.wpengine.com",
  "REQUEST_URI" => "/",
  "REQUEST_METHOD" => "GET"
);

require_once('/var/www/wordpress/wp-load.php');
require_once('ydn-importer.php');

$cli_opts = getopt("t:");
if(!array_key_exists("t",$cli_opts)) {
  printf("Error: must specify a target. users/main/weekend/crosscampus\n");
  die();
}

if (extension_loaded('xhprof') && PROFILE) {
  include_once '/usr/share/php/xhprof_lib/utils/xhprof_lib.php';
  include_once '/usr/share/php/xhprof_lib/utils/xhprof_runs.php';
  xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
}

$importer = new YDN_Importer($cli_opts['t']);

if (extension_loaded('xhprof') && PROFILE) {
  $profiler_namespace = 'myapp';  // namespace for your application
  $xhprof_data = xhprof_disable();
  $xhprof_runs = new XHProfRuns_Default();
  $run_id = $xhprof_runs->save_run($xhprof_data, $profiler_namespace);

  // url to the XHProf UI libraries (change the host name and path)
  $profiler_url = sprintf('http://50.116.62.82:8080/xhprof_html/index.php?run=%s&source=%s', $run_id, $profiler_namespace);
  printf("\n%s\n",$profiler_url);
}
?>
