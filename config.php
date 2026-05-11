<?php
#ini_set('session.save_path','/var/www/sessions/');
ini_set('session.cookie_lifetime',24*60*60);
ini_set('session.gc_maxlifetime',24*60*60);
error_reporting(E_ERROR | E_PARSE);
if (!isset($_SESSION)) session_start();
//session_name('mpLogin');
error_reporting(E_ALL);

require_once(__DIR__ . '/env_loader.php');
include_once("functions.php");
$messages=array();
$mess=array();
$dbhost=getenv('DB_HOST') ?: 'localhost';
$dbuser=getenv('DB_USER') ?: 'root';
$dbpass=getenv('DB_PASS') ?: '';
$dbname=getenv('DB_NAME') ?: 'mpol';
$link;
connectToDB();

?>