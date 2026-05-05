<div id="rset" class="uedbox" style="top:50%;left:50%;display:none">
    <div class="ulog">НАСТРОЙКИ ПЛАГИНОВ</div>
    <div class="clear cell1"><p>Выберите тюнер:<select><option>Xcruiser</option><option>Skystar SS2</option></select></p></div>
    <div class="clear cell1"><div class=row>
            <?php
            $link->sql_query("SELECT purse,exch,purse.`desc` FROM purse") or die("SQL req. error: ".mysql_error());
            $rc=$link->sql_numrows();
            for($i=0;$i<$rc;$i++)
            {
                $prs[$i]=$link->sql_fetchrow();
                echo "<el class=p1>Webmoney".' '.$prs[$i]['desc'].' '." (1:".$prs[$i]['exch'].') <el class=pn>'.$prs[$i]['purse'].'</el></el>';
            }
            $link->sql_close();
            ?>
        </div></div>
    <div id=plist class="pluso-list"></div>
</div>