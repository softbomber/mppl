<?php
/**
 * Shared backend logic for dealer.php and mb.php.
 * Initialises session variables, loads dealer profile and active-account count.
 *
 * Expects: config.php already included, session started.
 * Provides: $user, $demail, $dId, $dealerId, $t_fname, $t_lname, $t_usr,
 *           $daccid, $accsum, $accpwd, $accdreg, $acceml, $accph,
 *           $firstenter, $ppd, $defserver, $currency, $active, $intrst, $now
 */

$user     = '';
$demail   = '';
$dId      = 0;
$dealerId = '';
$t_fname  = '';
$t_lname  = '';
$t_usr    = '';

$dId = $_SESSION['d'];

if (isset($_SESSION['user_email']))
    $demail = $_SESSION['user_email'];

if (isset($_SESSION['t_usr']))
    $t_usr = $_SESSION['t_usr'];
if (isset($_SESSION['t_fname']))
    $t_fname = $_SESSION['t_fname'];
if (isset($_SESSION['t_lname']))
    $t_lname = $_SESSION['t_lname'];
if (isset($_SESSION['l']))
    $user = $_SESSION['l'];

if (!empty($t_usr)) {
    $dealerId = $t_usr;
} elseif (!empty($t_fname) && !empty($t_lname)) {
    $dealerId = $t_fname . " " . $t_lname;
} elseif (!empty($t_fname)) {
    $dealerId = $t_fname;
} elseif (!empty($t_lname)) {
    $dealerId = $t_lname;
} else {
    $dealerId = $user;
}

$stmt = $link->prepare(
    "SELECT id,user,sum,a,pwd,DATE_FORMAT(dreg,'%d.%m.%y %H:%i') as dreg,eml,phone,fe,postpaid,defserver,currency
     FROM dealers
     WHERE ((user=? or eml=?) or (t_fname=? or t_lname=? or t_usr=?)) and id=?"
);
$stmt->bind_param('sssssi', $user, $demail, $t_fname, $t_lname, $t_usr, $dId);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows == 1) {
    $row        = $res->fetch_assoc();
    $daccid     = $row['id'];
    $accsum     = $row['sum'];
    $accpwd     = $row['pwd'];
    $accdreg    = $row['dreg'];
    $acceml     = $row['eml'];
    $accph      = $row['phone'];
    $firstenter = $row['fe'];
    $ppd        = $row['postpaid'];
    $defserver  = $row['defserver'];
    $currency   = $row['currency'];
}

$now = time();

$stmt2 = $link->prepare(
    "SELECT accounts.id FROM pdates JOIN accounts ON pdates.user_id = accounts.id WHERE accounts.dealer = ? AND pdates.dend >= NOW()"
);
$stmt2->bind_param('i', $daccid);
$stmt2->execute();
$res = $stmt2->get_result();
$active = $res->num_rows;
$intrst = getInterestRate(intval($active));
