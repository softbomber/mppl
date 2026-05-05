<?php
include_once("config.php");
 checkLoggedIn("yes");
echo '<div class="nws" id=news>';
 echo '<div class="n_title">НОВОСТНОЙ БЛОК</div>';
 //include("CuteNews/show_news.php");
 include("cutenews212/show_news.php");
 echo "</div>";
 ?>