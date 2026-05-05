<?php
include_once("config.php");

function getInterestRate($numberOfAccounts) {
global $link;
$query = "SELECT rate 
              FROM interest_rates 
              WHERE min_accounts <= $numberOfAccounts
              AND (max_accounts >= $numberOfAccounts OR max_accounts IS NULL)
              ORDER BY min_accounts DESC 
              LIMIT 1";
    $r = $link->query($query);
    $result = $r->fetch_assoc();
    return $result ? (float)$result['rate'] : 5.0;
}



?>