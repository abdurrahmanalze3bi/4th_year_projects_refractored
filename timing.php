<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$t0 = microtime(true);
echo "START\n";

$user = \App\Models\User::with('profile')->find(16);
echo 'Step1 user+profile load: '.round((microtime(true)-$t0)*1000)."ms\n";

$conv = \Illuminate\Support\Facades\Cache::get('conversation.2');
echo 'Step2 conv from cache: '.round((microtime(true)-$t0)*1000)."ms\n";

$msg = \App\Models\Message::create(['conversation_id'=>2,'sender_id'=>16,'body'=>'timing','type'=>'text']);
echo 'Step3 DB insert: '.round((microtime(true)-$t0)*1000)."ms\n";

\App\Models\Conversation::where('id',2)->update(['updated_at'=>now()]);
echo 'Step4 conv touch: '.round((microtime(true)-$t0)*1000)."ms\n";

\Illuminate\Support\Facades\Queue::push('test');
echo 'Step5 queue push: '.round((microtime(true)-$t0)*1000)."ms\n";

$exists = \Illuminate\Support\Facades\Storage::disk('public')->exists('profiles/profile_photo/default.jpg');
echo 'Step6 storage exists: '.round((microtime(true)-$t0)*1000)."ms\n";

echo 'TOTAL: '.round((microtime(true)-$t0)*1000)."ms\n";
