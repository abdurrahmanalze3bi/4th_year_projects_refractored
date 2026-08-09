<?php
$base = str_replace('\\', '/', realpath('.'));
echo "Base: $base\n";

// Patch 1: SIGINT
$f = 'vendor/laravel/octane/src/Commands/Concerns/InteractsWithServers.php';
$c = file_get_contents($f);
$c = str_replace('return [SIGINT, SIGTERM, SIGHUP];','return array_filter([defined(\'SIGINT\')?SIGINT:null,defined(\'SIGTERM\')?SIGTERM:null,defined(\'SIGHUP\')?SIGHUP:null]);',$c);
file_put_contents($f,$c); echo " SIGINT\n";

// Patch 2: posix_kill
$f = 'vendor/laravel/octane/src/PosixExtension.php';
$c = file_get_contents($f);
$c = str_replace('return posix_kill($processId, $signal);','if(function_exists(\'posix_kill\')){return posix_kill($processId,$signal);}if($signal===0){exec("tasklist /FI \"PID eq {$processId}\" 2>NUL",$o);return count($o)>2;}exec("taskkill /PID {$processId} /F 2>NUL");return true;',$c);
file_put_contents($f,$c); echo " posix_kill\n";

// Patch 3: Rewrite worker cleanly
$w = '#!/usr/bin/env php'."\n".'<?php'."\n";
$w .= 'ini_set(\'display_errors\',\'stderr\');'."\n";
$w .= 'error_reporting(E_ERROR);'."\n";
$w .= '$_ENV[\'APP_BASE_PATH\']=\''.$base.'\';'."\n";
$w .= '$_SERVER[\'APP_BASE_PATH\']=\''.$base.'\';'."\n\n";
$w .= 'use Laminas\Diactoros\ServerRequestFactory;'."\n";
$w .= 'use Laminas\Diactoros\StreamFactory;'."\n";
$w .= 'use Laminas\Diactoros\UploadedFileFactory;'."\n";
$w .= 'use Laravel\Octane\ApplicationFactory;'."\n";
$w .= 'use Laravel\Octane\RequestContext;'."\n";
$w .= 'use Laravel\Octane\RoadRunner\RoadRunnerClient;'."\n";
$w .= 'use Laravel\Octane\Stream;'."\n";
$w .= 'use Laravel\Octane\Worker;'."\n";
$w .= 'use Psr\Http\Message\ServerRequestInterface;'."\n";
$w .= 'use Spiral\Goridge\Exception\RelayException;'."\n";
$w .= 'use Spiral\Goridge\Relay;'."\n";
$w .= 'use Spiral\RoadRunner\Http\PSR7Worker;'."\n";
$w .= 'use Spiral\RoadRunner\Worker as RoadRunnerWorker;'."\n\n";
$w .= 'require __DIR__.\'/../laravel/octane/fixes/fix-symfony-dd.php\';'."\n";
$w .= '$basePath=require __DIR__.\'/../laravel/octane/bin/bootstrap.php\';'."\n\n";
$w .= '$rrc=new RoadRunnerClient($ps7=new PSR7Worker(new RoadRunnerWorker(Relay::create(\'pipes\')),new ServerRequestFactory,new StreamFactory,new UploadedFileFactory));'."\n";
$w .= '$wk=null;'."\n";
$w .= 'try{while($req=$ps7->waitRequest()){$wk=$wk?:tap((new Worker(new ApplicationFactory($basePath),$rrc)))->boot();if(!$req instanceof ServerRequestInterface)break;[$r,$c]=$rrc->marshalRequest(new RequestContext([\'psr7Request\'=>$req]));$wk->handle($r,$c);}}'."\n";
$w .= 'catch(Throwable $e){if(!$e instanceof RelayException){$wk?report($e):Stream::shutdown($e);}exit(1);}';
$w .= 'finally{if(!is_null($wk))$wk->terminate();}';
file_put_contents('vendor/bin/roadrunner-worker',$w);
echo " Worker rewritten\n";

// Test: must produce NO stdout
$d=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
$p=proc_open('php vendor/bin/roadrunner-worker',$d,$pipes);
fclose($pipes[0]);
$out=stream_get_contents($pipes[1]);
$err=stream_get_contents($pipes[2]);
proc_close($p);
echo $out?" STDOUT (bad): $out\n":" STDOUT clean\n";
echo $err?"STDERR: $err\n":" STDERR clean\n";
echo "\nRun: php artisan octane:start --server=roadrunner --port=9001 --workers=4\n";
