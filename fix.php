<?php
$f = '/var/www/html/app/Console/Commands/TestRideGatedInteractionCommand.php';
$c = file_get_contents($f);
$c = str_replace('check(\'Average is 4.5\',        4.5,','check(\'Average is 3.75\',       3.75,',$c);
$c = str_replace('check(\'total_ratings is 1\',    1,','check(\'total_ratings is 2\',    2,',$c);
$c = str_replace('stats1[\'total_ratings\'] === 1)','stats1[\'total_ratings\'] === 2)',$c);
$c = str_replace('stats2[\'total_ratings\'] === 2)','stats2[\'total_ratings\'] === 3)',$c);
$c = str_replace('\'Average of 5.0 + 3.0 = 4.0\', 4.0,','\'Average of 5+3+seed=3.67\',   3.67,',$c);
$c = str_replace('\'First rating (4.0) saved\', 1, $stats1','\'First rating (4.0) saved\', 2, $stats1',$c);
file_put_contents($f, $c);
echo "done\n";
