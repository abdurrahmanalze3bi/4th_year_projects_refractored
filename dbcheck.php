<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
DB::enableQueryLog();
$t = microtime(true);
App\Models\Message::create(['conversation_id'=>2,'sender_id'=>16,'body'=>'perftest','type'=>'text']);
$conv = App\Models\Conversation::find(2);
$conv->touch();
echo 'TOTAL: '.round((microtime(true)-$t)*1000)."ms\n";
foreach (DB::getQueryLog() as $q) {
    echo round($q['time']).'ms  '.substr($q['query'],0,90)."\n";
}
