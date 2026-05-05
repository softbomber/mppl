<?php
#ini_set('session.save_path','/var/www/sessions/');
ini_set('session.cookie_lifetime',24*60*60);
ini_set('session.gc_maxlifetime',24*60*60);
error_reporting(E_ERROR | E_PARSE);
if (!isset($_SESSION)) session_start();
//session_name('mpLogin');
error_reporting(E_ALL);

include_once("functions.php");
$messages=array();
$mess=array();
$dbhost="localhost";
$dbuser="root";
$dbpass="uiF5bcaw8";
$dbname="mpol";
$link;
connectToDB();

?>