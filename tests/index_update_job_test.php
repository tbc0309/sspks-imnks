<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use SSpkS\Config;
use SSpkS\IndexUpdateJob;
use think\facade\Db;
$repo = dirname(__DIR__);
$originalCwd = getcwd();
$temp = sys_get_temp_dir() . '/sspks-index-job-' . bin2hex(random_bytes(8));
mkdir($temp,0700);
$check = static function (bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } };
$cleanup = static function (string $dir) use (&$cleanup): void {
    foreach (new FilesystemIterator($dir) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { $cleanup($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($dir);
};
try {
    foreach (['conf','cache','runtime','packages2025','languages'] as $dir) { mkdir($temp.'/'.$dir,0700); }
    foreach (glob($repo.'/conf/*.yaml') as $file) { copy($file,$temp.'/conf/'.basename($file)); }
    foreach (glob($repo.'/languages/*.php') ?: [] as $file) { copy($file,$temp.'/languages/'.basename($file)); }
    file_put_contents($temp.'/conf/database.yaml',"database:\n  type: sqlite\n  database: runtime/test.sqlite\n  prefix: test_\nmanagement_password: test-only\n");
    putenv('SSPKS_DB_TYPE=sqlite');
    chdir($temp);
    $config = Config::getInstance($temp);
    $config->paths = array_replace($config->paths,['packages'=>'packages2025/']);
    $build = static function (string $name,string $version,int $payload = 0) use ($temp): string {
        $filename = $temp.'/'.bin2hex(random_bytes(6)).'.tar';
        $archive = new PharData($filename);
        $archive->addFromString('INFO',"package=\"$name\"\nversion=\"$version\"\ndisplayname=\"$name\"\narch=\"x86_64\"\nos_min_ver=\"7.2.1-69057\"\ndescription=\"Job regression\"\nmaintainer=\"Test\"\n");
        if ($payload) { $archive->addFromString('package.tgz',str_repeat('x',$payload)); }
        unset($archive);
        $target = $temp.'/packages2025/'.$name.'.spk';
        if (is_file($target)) { unlink($target); }
        rename($filename,$target);
        return $target;
    };
    $file = $build('First','1.0-1',3*1024*1024);
    $job = new IndexUpdateJob($config);
    $started = $job->start('incremental');
    $first = $job->step($started['job'],0,5.0,65536);
    $check($first['fileBytes'] > 0 && $first['fileBytes'] < filesize($file),'A large checksum must pause before EOF');
    $job = null;
    $job = new IndexUpdateJob($config);
    $resumed = $job->start('verify');
    $check($resumed['job'] === $started['job'] && $resumed['mode'] === 'incremental','Lost start responses must resume the same task');
    $second = $job->step($started['job'],0,5.0,65536);
    $check($second['fileBytes'] > $first['fileBytes'],'Checksum offset must survive a new PHP service instance');
    $finish = static function (array $state) use ($config,$check): array {
        for ($i=0; $state['type'] !== 'complete' && $i<200; $i++) {
            $worker = new IndexUpdateJob($config);
            $state = $worker->step($state['job'],0,5.0,65536);
            unset($worker);
        }
        $check($state['type'] === 'complete','Task must finish');
        return $state;
    };
    $complete = $finish($second);
    $check($complete['added']===1 && Db::name('spk')->count()===1,'First task must add one package');
    $check(Db::name('spk')->value('md5')===md5_file($file),'Resumed MD5 must equal the native MD5');
    $id = Db::name('spk')->value('id');
    $incremental = $finish((new IndexUpdateJob($config))->start('incremental'));
    $check($incremental['unchanged']===1 && Db::name('spk')->value('id')===$id,'Unchanged files must preserve their index records');
    $verify = (new IndexUpdateJob($config))->start('verify');
    $verify = (new IndexUpdateJob($config))->step($verify['job'],0,5.0,65536);
    $check($verify['fileBytes']===65536,'Full verification must hash unchanged files');
    $finish($verify);
    $nfo = $temp . '/cache/First.nfo';
    $beforeMd5 = Db::name('spk')->value('md5');
    $text = file_get_contents($nfo);
    file_put_contents($nfo,preg_replace('/displayname="[^"]*"/','displayname="Edited metadata"',$text));
    $edited = (new IndexUpdateJob($config))->start('incremental');
    $edited = (new IndexUpdateJob($config))->step($edited['job'],0,5.0,1);
    $check($edited['changed']===1 && $edited['fileBytes']===0,'NFO edits must refresh without hashing SPK');
    $finish($edited);
    $check(Db::name('spk')->value('displayname')==='Edited metadata' && Db::name('spk')->value('md5')===$beforeMd5,'Edited NFO metadata must reach the index with the original MD5');
    $stable = $finish((new IndexUpdateJob($config))->start('incremental'));
    $check($stable['unchanged']===1,'An unchanged edited NFO must reuse the index');
    $validNfo = file_get_contents($nfo);
    file_put_contents($nfo,str_repeat('x',4*1024*1024+1));
    $oversized = $finish((new IndexUpdateJob($config))->start('incremental'));
    $check($oversized['failed']===1 && Db::name('spk')->value('displayname')==='Edited metadata','Oversized NFO must fail without replacing the good index');
    file_put_contents($nfo,$validNfo);
    // Corruption of a replacement preserves the last known-good row.
    file_put_contents($file,'not an SPK');
    $failed = $finish((new IndexUpdateJob($config))->start('incremental'));
    $check($failed['failed']===1 && Db::name('spk')->value('version')==='1.0-1','Parse failure must preserve the old record');
    $build('First','2.0-1');
    $build('Second','1.0-1');
    $changed = $finish((new IndexUpdateJob($config))->start('incremental'));
    $check($changed['changed']===1 && $changed['added']===1 && Db::name('spk')->count()===2,'Replacement and addition must be classified separately');
    unlink($temp.'/packages2025/Second.spk');
    $deleted = $finish((new IndexUpdateJob($config))->start('incremental'));
    $check($deleted['deleted']===1 && Db::name('spk')->count()===1,'Removed files must be deleted from the index');
    unlink($file);
    $rejected=false;
    try { (new IndexUpdateJob($config))->start('incremental'); } catch (RuntimeException $e) { $rejected=true; }
    $check($rejected && Db::name('spk')->count()===1,'Empty directory must never clear the old index');
    $file=$build('First','3.0-1',1024*1024);
    $state=(new IndexUpdateJob($config))->start('verify');
    $state=(new IndexUpdateJob($config))->step($state['job'],0,5.0,65536);
    file_put_contents($file,'changed during hashing');
    $state=$finish($state);
    $check($state['failed']===1 && Db::name('spk')->value('version')==='2.0-1','Mutation during hashing must preserve the old row');
    // Lost final responses must be retrievable without executing the job again.
    $again=(new IndexUpdateJob($config))->step($state['job'],0);
    $check($again['failed']===1 && $again['job']===$state['job'],'Completed steps must be idempotent');
    echo "Index job tests passed: resumable MD5, incremental reuse, verification, mutation, failure preservation, deletion and response recovery.\n";
} finally {
    unset($job);
    Db::close();
    chdir($originalCwd);
    gc_collect_cycles();
    $cleanup($temp);
}