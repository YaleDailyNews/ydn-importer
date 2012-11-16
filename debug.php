<?php
include('simplemongophp/Db.php');

define( 'MONGODB_NAME', 'ydn_working2');
define( 'MONGODB_IP', "mongodb://50.116.62.82");

$mongo = new Mongo(MONGODB_IP);
Db::addConnection($mongo, MONGODB_NAME);

$testq = Db::find("photo", array("el_id"=>"30645"), array());
$el_photo = $testq->getNext();

var_dump($el_photo);

$first_name =  array_key_exists("el_photographer_first_name", $el_photo) ? trim($el_photo["el_photographer_first_name"]) : "";
$last_name =  array_key_exists("el_photographer_last_name", $el_photo)  ? trim($el_photo["el_photographer_last_name"]) : "";
$authors = Db::find("wp_user", array("true_user" => false,
                                     "first_name" => $first_name, 
                                     "last_name" =>  $last_name),
                               array("limit" => 1) );
printf($authors->count());
var_dump($authors->getNext());
?>  
