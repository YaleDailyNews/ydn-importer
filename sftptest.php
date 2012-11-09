<?php
include('Net/SFTP.php');
function fetch_file($url, $destination) {
$sftp = new Net_SFTP('yaledailynews.wpengine.com');
printf("SFTP Username: ");
fscanf(STDIN, "%s\n", $user);
printf("SFTP password: ");
fscanf(STDIN, "%s\n", $pass);
if (!$sftp->login($user,$pass)) {
  exit("Login failed\n");
}

$ch = curl_init(); //create a cURL resource
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$file_contents = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if(curl_errno($ch) != 0 || $http_code != 200) {
      printf('error\n');
      return;
    } 
    //close cURL resource, and free up system resources
    $sftp->put($destination,$file_contents);
    curl_close($ch);
    unset($file_contents);
}
fetch_file("http://yaledailynews.media.clients.ellingtoncms.com/img/photos/2012/10/24/JacobGeiger_DivDeanInauguration-85_r470x350.jpg","/wp-content/geiger_test.jpg");
?>
