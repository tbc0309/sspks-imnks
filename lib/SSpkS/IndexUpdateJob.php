<?php

namespace SSpkS;

use PDO;
use SSpkS\Package\Package;
use SSpkS\Package\PackageFinder;
use think\facade\Cache;
use think\facade\Db;

/** Durable, bounded work units. The HTTP handler owns authentication and the request lock. */
final class IndexUpdateJob
{
    private PDO $state;
    private array $existingRows = [];
    private array $sourceRows = [];
    private string $fingerprint;
    private string $root;
    private const HASH_CHUNK = 1024 * 1024;
    private const HASH_BUDGET = 64 * 1024 * 1024;
    private const ROW_BUDGET = 25;

    public function __construct(private Config $config)
    {
        $this->root = $config->basePath;
        $directory = $this->root . DIRECTORY_SEPARATOR . 'runtime';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create index task directory');
        }
        $filename = $directory . DIRECTORY_SEPARATOR . 'index-update.sqlite3';
        $this->state = new PDO('sqlite:' . $filename, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->state->exec('PRAGMA busy_timeout=1000');
        $this->state->exec('CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, mode TEXT NOT NULL, fingerprint TEXT NOT NULL, phase TEXT NOT NULL, total INTEGER NOT NULL, cursor INTEGER NOT NULL DEFAULT 0, delete_cursor INTEGER NOT NULL DEFAULT 0, max_id INTEGER NOT NULL, added INTEGER NOT NULL DEFAULT 0, changed INTEGER NOT NULL DEFAULT 0, unchanged INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0, failed INTEGER NOT NULL DEFAULT 0, updated INTEGER NOT NULL)');
        $this->state->exec('CREATE TABLE IF NOT EXISTS files (job TEXT NOT NULL, ordinal INTEGER NOT NULL, path TEXT NOT NULL, status TEXT NOT NULL DEFAULT "pending", identity TEXT, offset INTEGER NOT NULL DEFAULT 0, context BLOB, digest TEXT, attempts INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(job,ordinal), UNIQUE(job,path))');
        $this->state->exec('CREATE TABLE IF NOT EXISTS source_stats (path TEXT PRIMARY KEY, identity TEXT NOT NULL, md5 TEXT NOT NULL, fingerprint TEXT NOT NULL)');
        $this->state->exec('CREATE TABLE IF NOT EXISTS events (seq INTEGER PRIMARY KEY AUTOINCREMENT, job TEXT NOT NULL, type TEXT NOT NULL, name TEXT NOT NULL, detail TEXT NOT NULL, category TEXT NOT NULL)');
        $this->state->exec('CREATE INDEX IF NOT EXISTS events_job_seq ON events(job,seq)');
        @chmod($filename, 0660);
        $files = glob(__DIR__ . '/Package/*.php') ?: [];
        $this->fingerprint = hash('sha256', serialize([
            'version' => 2, 'php' => PHP_VERSION, 'packages' => $config->packages,
            'path' => $config->paths['packages'],
            'parser' => Package::CACHE_VERSION,
        ]));
    }

    public function start(string $mode): array
    {
        if (!in_array($mode, ['incremental', 'verify'], true)) {
            throw new \InvalidArgumentException('Invalid index update mode');
        }
        $active = $this->query('SELECT * FROM jobs WHERE phase != ? ORDER BY updated DESC LIMIT 1', ['complete'])->fetch(PDO::FETCH_ASSOC);
        if ($active) {
            if (!hash_equals($this->fingerprint, $active['fingerprint'])) {
                $this->execute('UPDATE jobs SET phase=? WHERE id=?', ['complete', $active['id']]);
            } else {
                return $this->snapshot($active['id'], 0);
            }
        }
        $files = (new PackageFinder($this->config))->getAllPackageFiles();
        if ($files === []) {
            throw new \RuntimeException('No SPK files found; the existing index was preserved');
        }
        $id = bin2hex(random_bytes(16));
        $this->state->beginTransaction();
        try {
            // Retain one completed task for response-loss recovery, plus the active task.
            $old = $this->query('SELECT id FROM jobs WHERE phase=? ORDER BY updated DESC', ['complete'])->fetchAll(PDO::FETCH_COLUMN);
            foreach (array_slice($old, 1) as $oldId) {
                foreach (['files', 'events'] as $table) {
                    $this->execute('DELETE FROM ' . $table . ' WHERE job=?', [$oldId]);
                }
                $this->execute('DELETE FROM jobs WHERE id=?', [$oldId]);
            }
            $maxId = (int) (Db::name('spk')->max('id') ?? 0);
            $this->execute('INSERT INTO jobs(id,mode,fingerprint,phase,total,max_id,updated) VALUES(?,?,?,?,?,?,?)', [$id,$mode,$this->fingerprint,'scan',count($files),$maxId,time()]);
            foreach ($files as $ordinal => $file) {
                $this->execute('INSERT INTO files(job,ordinal,path) VALUES(?,?,?)', [$id,$ordinal,$file]);
            }
            $this->state->commit();
        } catch (\Throwable $e) {
            $this->state->rollBack();
            throw $e;
        }
        return $this->snapshot($id, 0);
    }

    public function step(string $id, int $after = 0, float $seconds = 5.0, int $byteLimit = self::HASH_BUDGET): array
    {
        $job = $this->getJob($id);
        if ($job['phase'] === 'complete') {
            return $this->snapshot($id, $after);
        }
        if (!hash_equals($this->fingerprint, $job['fingerprint'])) {
            throw new \RuntimeException('Parser or configuration changed; start a new update');
        }
        $deadline = microtime(true) + max(0.01, min(5.0, $seconds));
        $bytes = 0;
        $processed = 0;
        $paths = $this->query('SELECT path FROM files WHERE job=? AND ordinal>=? ORDER BY ordinal LIMIT 25', [$id,(int)$job['cursor']])->fetchAll(PDO::FETCH_COLUMN);
        $this->existingRows = [];
        $this->sourceRows = [];
        if ($paths !== []) {
            foreach (Db::name('spk')->whereIn('spk',$paths)->select()->toArray() as $row) { $this->existingRows[$row['spk']] = $row; }
            $marks = implode(',',array_fill(0,count($paths),'?'));
            foreach ($this->query('SELECT * FROM source_stats WHERE path IN ('.$marks.')',$paths)->fetchAll(PDO::FETCH_ASSOC) as $row) { $this->sourceRows[$row['path']] = $row; }
        }
        while ($job['phase'] === 'scan' && microtime(true) < $deadline && $processed < self::ROW_BUDGET) {
            $entry = $this->query('SELECT * FROM files WHERE job=? AND ordinal=?', [$id,(int)$job['cursor']])->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                $this->execute('UPDATE jobs SET phase=?,updated=? WHERE id=?', ['delete',time(),$id]);
                break;
            }
            try {
                $finished = $this->processFile($job, $entry, $deadline, $bytes, max(1,$byteLimit));
            } catch (\Throwable $e) {
                $this->finishFile($id, $entry, 'failed', $e->getMessage());
                $finished = true;
            }
            if (!$finished) {
                break;
            }
            $processed++;
            $job = $this->getJob($id);
            if ($bytes >= $byteLimit) {
                break;
            }
        }
        $job = $this->getJob($id);
        if ($job['phase'] === 'delete' && microtime(true) < $deadline) {
            $this->removeMissingFiles($job, $deadline);
        }
        return $this->snapshot($id, $after);
    }

    public function snapshot(string $id, int $after): array
    {
        $job = $this->getJob($id);
        $events = $this->query('SELECT seq,type,name,detail,category FROM events WHERE job=? AND seq>? ORDER BY seq LIMIT 100', [$id,max(0,$after)])->fetchAll(PDO::FETCH_ASSOC);
        $tail = $events === [] ? max(0,$after) : (int)end($events)['seq'];
        $pending = (bool)$this->query('SELECT 1 FROM events WHERE job=? AND seq>? LIMIT 1', [$id,$tail])->fetchColumn();
        $entry = $this->query('SELECT path,offset,identity,status FROM files WHERE job=? AND ordinal=?', [$id,(int)$job['cursor']])->fetch(PDO::FETCH_ASSOC);
        $fraction = 0.0;
        if ($entry && $entry['identity']) {
            $identity = json_decode($entry['identity'], true);
            $fraction = min(1.0,(int)$entry['offset'] / max(1,(int)($identity['size'] ?? 1)));
        }
        $percent = $job['phase'] === 'complete' ? 100 : min(94,(int)floor(94*((int)$job['cursor']+$fraction)/max(1,(int)$job['total'])));
        return [
            'type' => $job['phase'] === 'complete' && !$pending ? 'complete' : 'progress',
            'job' => $id, 'mode' => $job['mode'], 'phase' => $job['phase'], 'percent' => $percent,
            'total' => (int)$job['total'], 'processed' => (int)$job['cursor'],
            'added' => (int)$job['added'], 'changed' => (int)$job['changed'],
            'unchanged' => (int)$job['unchanged'], 'deleted' => (int)$job['deleted'],
            'failed' => (int)$job['failed'], 'success' => (int)$job['added']+(int)$job['changed']+(int)$job['unchanged'],
            'file' => $entry ? basename($entry['path']) : '', 'fileBytes' => $entry ? (int)$entry['offset'] : 0,
            'filePhase' => $entry ? $entry['status'] : '', 'filePercent' => (int)floor($fraction*100),
            'events' => $events, 'after' => $tail,
        ];
    }

    private function processFile(array $job, array $entry, float $deadline, int &$bytes, int $byteLimit): bool
    {
        $id = $job['id'];
        $file = $entry['path'];
        $absolute = $this->absolute($file);
        $identity = $this->identity($absolute);
        $encodedIdentity = json_encode($identity, JSON_THROW_ON_ERROR);
        $existing = $this->existingRows[$file] ?? null;
        $saved = $this->sourceRows[$file] ?? null;
        $savedIdentity = $saved ? json_decode($saved['identity'],true,512,JSON_THROW_ON_ERROR) : [];
        $metadataStamp = $this->metadataStamp($file);
        $sameMetadata = array_key_exists('nfo',$savedIdentity) && $savedIdentity['nfo'] === $metadataStamp;
        $sameSource = $existing && $saved && ($savedIdentity['size'] ?? null) === $identity['size'] && ($savedIdentity['mtime'] ?? null) === $identity['mtime']
            && hash_equals((string)$existing['md5'], $saved['md5'])
            && (int)$existing['filesize'] === $identity['size']
            && (int)$existing['filemtime'] === $identity['mtime'];
        if ($entry['status'] === 'pending' && $job['mode'] === 'incremental' && $sameSource && $sameMetadata && hash_equals($this->fingerprint,$saved['fingerprint'])) {
            $this->finishFile($id, $entry, 'unchanged', (string)$existing['displayname']);
            return true;
        }
        if ($entry['identity'] !== null && $entry['identity'] !== $encodedIdentity) {
            throw new \RuntimeException('SPK changed during this task; retry in a new update');
        }
        if ($entry['identity'] === null) {
            $digest = $job['mode'] === 'incremental' && $sameSource ? $saved['md5'] : null;
            $this->execute('UPDATE files SET identity=?,digest=? WHERE job=? AND ordinal=?', [$encodedIdentity,$digest,$id,$entry['ordinal']]);
            $entry['identity'] = $encodedIdentity;
            $entry['digest'] = $digest;
        }
        if ($entry['digest'] === null) {
            $context = $entry['context'] === null ? hash_init('md5') : unserialize($entry['context'], ['allowed_classes'=>[\HashContext::class]]);
            if (!$context instanceof \HashContext) {
                throw new \RuntimeException('Invalid saved checksum state');
            }
            $handle = @fopen($absolute, 'rb');
            if (!$handle || fseek($handle,(int)$entry['offset']) !== 0) {
                if (is_resource($handle)) { fclose($handle); }
                throw new \RuntimeException('Cannot open SPK checksum stream');
            }
            $offset = (int)$entry['offset'];
            try {
                while ($offset < $identity['size'] && microtime(true) < $deadline && $bytes < $byteLimit) {
                    $chunk = fread($handle, min(self::HASH_CHUNK,$identity['size']-$offset,$byteLimit-$bytes));
                    if (!is_string($chunk) || $chunk === '') { throw new \RuntimeException('Incomplete SPK checksum read'); }
                    hash_update($context,$chunk);
                    $offset += strlen($chunk);
                    $bytes += strlen($chunk);
                    $this->execute('UPDATE files SET offset=?,context=?,status=? WHERE job=? AND ordinal=?', [$offset,serialize($context),'hashing',$id,$entry['ordinal']]);
                }
            } finally { fclose($handle); }
            if ($encodedIdentity !== json_encode($this->identity($absolute),JSON_THROW_ON_ERROR)) {
                throw new \RuntimeException('SPK changed while calculating checksum');
            }
            if ($offset < $identity['size']) { return false; }
            $entry['digest'] = hash_final($context);
            $this->execute('UPDATE files SET digest=?,context=NULL WHERE job=? AND ordinal=?', [$entry['digest'],$id,$entry['ordinal']]);
            // Parsing gets its own request budget; do not append it to a full hashing request.
            return false;
        }
        if ($sameSource && $sameMetadata && $saved && hash_equals($saved['md5'],$entry['digest']) && hash_equals($this->fingerprint,$saved['fingerprint'])) {
            $this->finishFile($id,$entry,'unchanged',(string)$existing['displayname']);
            return true;
        }
        if ($existing && $sameMetadata && $saved && hash_equals((string)$existing['md5'],$entry['digest']) && hash_equals($this->fingerprint,$saved['fingerprint'])) {
            $row = ['filesize'=>$identity['size'],'filemtime'=>$identity['mtime']];
            $category = 'unchanged';
            $detail = (string)$existing['displayname'];
        } else {
            if ((int)$entry['attempts'] >= 2) {
                throw new \RuntimeException('SPK parsing repeatedly timed out; previous index record was preserved');
            }
            $this->execute('UPDATE files SET status=?,attempts=attempts+1 WHERE job=? AND ordinal=?', ['parsing',$id,$entry['ordinal']]);
            $package = new Package($this->config,$file,$entry['digest']);
            if ($encodedIdentity !== json_encode($this->identity($absolute),JSON_THROW_ON_ERROR)) {
                throw new \RuntimeException('SPK changed while parsing');
            }
            if (!empty($this->config->browser_url_obfuscation['package_images'])) {
                $publisher = new \SSpkS\Package\BrowserImageObfuscator($this->config);
                $publisher->publishUrls(array_merge((array)$package->thumbnail,(array)$package->snapshot));
            }
            $package->filesize = $identity['size'];
            $package->md5 = $entry['digest'];
            $row = ['displayname'=>$package->displayname ?? '', 'package'=>$package->package,
                'version'=>$package->version,'arch'=>implode(',',$package->arch),'os_min_ver'=>$package->os_min_ver,
                'beta'=>$package->beta?1:0,'spk'=>$file,'filesize'=>$identity['size'],'md5'=>$entry['digest'],
                'filemtime'=>$identity['mtime'],'params'=>serialize($package)];
            $category = $existing ? 'changed' : 'added';
            $detail = $package->displayname . ' · ' . $package->version;
        }
        Db::startTrans();
        try {
            if ($existing) { Db::name('spk')->where('id',$existing['id'])->update($row+['update_time'=>time()]); }
            else { Db::name('spk')->insert($row+['create_time'=>time(),'update_time'=>time()]); }
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
        $this->execute('INSERT INTO source_stats(path,identity,md5,fingerprint) VALUES(?,?,?,?) ON CONFLICT(path) DO UPDATE SET identity=excluded.identity,md5=excluded.md5,fingerprint=excluded.fingerprint', [$file,json_encode($identity + ['nfo'=>$this->metadataStamp($file)],JSON_THROW_ON_ERROR),$entry['digest'],$this->fingerprint]);
        $this->finishFile($id,$entry,$category,$detail);
        return true;
    }

    private function finishFile(string $id, array $entry, string $category, string $detail): void
    {
        if (!in_array($category,['added','changed','unchanged','failed'],true)) { throw new \LogicException('Invalid result category'); }
        $this->state->beginTransaction();
        try {
            $this->execute('UPDATE files SET status=?,context=NULL WHERE job=? AND ordinal=?', [$category,$id,$entry['ordinal']]);
            $this->execute('UPDATE jobs SET cursor=cursor+1,' . $category . '=' . $category . '+1,updated=? WHERE id=?', [time(),$id]);
            $this->execute('INSERT INTO events(job,type,name,detail,category) VALUES(?,?,?,?,?)', [$id,$category==='failed'?'failure':'success',basename($entry['path']),$detail,$category]);
            $this->state->commit();
        } catch (\Throwable $e) { $this->state->rollBack(); throw $e; }
    }

    private function removeMissingFiles(array $job, float $deadline): void
    {
        $directory = $this->absolute($this->config->paths['packages']);
        if (!is_dir($directory) || (new PackageFinder($this->config))->getAllPackageFiles() === []) {
            throw new \RuntimeException('Package directory unavailable or empty; deletion stopped and existing records preserved');
        }
        if ((int)$job['added']+(int)$job['changed']+(int)$job['unchanged'] === 0) {
            $this->complete($job['id']);
            return;
        }
        $rows = Db::name('spk')->where('id','>',(int)$job['delete_cursor'])->where('id','<=',(int)$job['max_id'])->order('id')->limit(100)->select()->toArray();
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) { return; }
            $known = $this->query('SELECT 1 FROM files WHERE job=? AND path=?', [$job['id'],$row['spk']])->fetchColumn();
            if (!$known && !is_file($this->absolute($row['spk']))) {
                Db::startTrans();
                try { Db::name('spk')->where('id',$row['id'])->delete(); Db::commit(); }
                catch (\Throwable $e) { Db::rollback(); throw $e; }
                $this->execute('UPDATE jobs SET deleted=deleted+1 WHERE id=?', [$job['id']]);
                $this->execute('DELETE FROM source_stats WHERE path=?', [$row['spk']]);
                $this->execute('INSERT INTO events(job,type,name,detail,category) VALUES(?,?,?,?,?)', [$job['id'],'success',basename($row['spk']),(string)$row['displayname'],'deleted']);
            }
            $this->execute('UPDATE jobs SET delete_cursor=?,updated=? WHERE id=?', [$row['id'],time(),$job['id']]);
        }
        if (count($rows) < 100) { $this->complete($job['id']); }
    }

    private function complete(string $id): void
    {
        // Clearing derived caches is cheap; existing published images are reused lazily by the catalog.
        Cache::clear();
        $this->execute('UPDATE jobs SET phase=?,updated=? WHERE id=?', ['complete',time(),$id]);
    }

    private function metadataStamp(string $file): ?string
    {
        $path = $this->absolute($this->config->paths['cache'] . pathinfo(basename($file),PATHINFO_FILENAME) . '.nfo');
        if (!is_file($path)) { return null; }
        clearstatcache(true,$path);
        $size = filesize($path);
        if ($size === false || $size > 4 * 1024 * 1024) {
            throw new \RuntimeException('Cached INFO exceeds the metadata size limit');
        }
        return hash_file('sha256',$path);
    }

    private function identity(string $absolute): array
    {
        clearstatcache(true,$absolute);
        $stat = @stat($absolute);
        if (!$stat || !is_file($absolute) || !is_readable($absolute)) { throw new \RuntimeException('SPK is missing or unreadable'); }
        return ['size'=>(int)$stat['size'],'mtime'=>(int)$stat['mtime']];
    }

    private function absolute(string $path): string
    {
        return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$path) ? $path : $this->root . DIRECTORY_SEPARATOR . $path;
    }

    private function getJob(string $id): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D',$id) !== 1) { throw new \InvalidArgumentException('Invalid index task identifier'); }
        $row = $this->query('SELECT * FROM jobs WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new \RuntimeException('Index task not found; start a new update'); }
        return $row;
    }

    private function query(string $sql, array $values): \PDOStatement
    {
        $statement = $this->state->prepare($sql);
        $statement->execute($values);
        return $statement;
    }

    private function execute(string $sql, array $values): void
    {
        $this->query($sql,$values);
    }
}