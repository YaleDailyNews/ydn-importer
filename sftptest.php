<?php
include('Net/SFTP.php');
$sftp = new Net_SFTP('yaledailynews.wpengine.com');
printf("SFTP Username: ");
fscanf(STDIN, "%s\n", $user);
printf("SFTP password: ");
fscanf(STDIN, "%s\n", $pass);
if (!$sftp->login($user,$pass)) {
  exit("Login failed\n");
}
$test_dir = 'wp-content/dne/dne2/dne3/dsafdfasd/a';
$path_elts = explode('/',$test_dir);
$current_path = '';
for($i = 0; $i < count($path_elts) - 1; $i++) {
  $current_path = $current_path . '/' . $path_elts[$i];
  $sftp->mkdir($current_path);
}
?>
