#!/usr/bin/php
<?php

interface DbMigration
{
    function up(PDO $pdo);
    function down(PDO $pdo);
}

function getPdo(){
    $dsn = getenv('PDO_DSN');
    if (!$dsn){
        throw new Exception('PDO_DSN env required');
    }

    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    return $pdo;
}

function getDbInfo(PDO $pdo){
    $sql = "select count(*) from pg_tables where tablename = 'app_migration_info'";
    $count = null;
    foreach ($pdo->query($sql) as $row)
        $count = $row[0];
    if ($count === null)
        throw new Exception('BUG');
    if ($count === 0){
        createSchemaInfo($pdo);
    }

    $sql = "select * from app_migration_info order by created_at desc";
    $ret = [];
    foreach ($pdo->query($sql) as $row){
        $ret[] = $row;
    }
    return $ret;
}

function createSchemaInfo(PDO $pdo){
    $pdo->exec('
create table app_migration_info(
  hash varchar(40) not null,
  file_name text not null,
  data text not null,
  created_at timestamp not null default clock_timestamp()
)');
}

function getFiles(){
    $ite = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            'db-migration',
            FilesystemIterator::CURRENT_AS_FILEINFO |
            FilesystemIterator::KEY_AS_PATHNAME |
            FilesystemIterator::SKIP_DOTS
        )
    );
    $ite = new RegexIterator($ite, '/^.+\.php$/i', RecursiveRegexIterator::MATCH);
    $names = [];
    foreach ($ite as $path => $info){
        $names[] = $path;
    }
    natsort($names);
    $files = [];
    foreach ($names as $name){
        require_once $name;
        $cls = get_declared_classes();
        $declared = end($cls);
        $files[$name] = $declared;
    }
    return $files;
}


function prompt($msg){
    echo "$msg [y/n]: ";
    $line = trim(fgets(STDIN));
    $line = strtolower($line);
    if ($line === 'y' || $line === 'yes')
        return true;
    else if ($line === 'n' || $line === 'no')
        return false;

    return prompt($msg);
}

function decorate($str, $color){
    $colors = array('bold'    => 1,
                    'white'   => 37,
                    'red'     => 31,
                    'green'   => 32,
                    'bgred'   => 41,
                    'bggreen' => 42,
                    );
    if (!is_array($color) && !isset($colors[$color]))
        return $str;

    if (is_array($color)){
        $patterns = array();
        foreach ($color as $c)
            $patterns[] = $colors[$c];
        $pattern = implode(';', $patterns);
    }else{
        $pattern = $colors[$color];
    }

    echo "\x1b[" . $pattern . 'm' . $str . "\x1b[m\n";
}

function get_git_hash($file)
{
    $data = file_get_contents($file);
    $prefix = 'blob ' . strlen($data) . "\0";
    $sha1 = sha1($prefix . $data);
    return $sha1;
}

function run($force, $nocheck, $nodown, $test){
    $files = getFiles();
    $pdo = getPdo();

    $rows = getDbInfo($pdo);

    // store hash
    $dbHashes = [];
    foreach ($rows as $row){
        $dbHashes[] = $row['hash'];
    }
    $fileHashes = [];
    foreach ($files as $file => $clazz){
        $sha1 = get_git_hash($file);
        $fileHashes[$file] = $sha1;
    }

    // format print data
    $down = [];
    foreach ($rows as $row){
        $hash = $row['hash'];
        if (in_array($hash, $fileHashes))
            continue;

        if (!preg_match('/class ([^ ]*) implements/', $row['data'], $matches))
            continue;
        $down[] = sprintf(" %6.6s %30.30s %20.20s  %19.19s\n",
                          $row['hash'], preg_replace('|^db-migration/|', '', $row['file_name'])
                          , $matches[1], $row['created_at']);
    }
    if ($down){
        echo "Donwgrade:\n";
        foreach ($down as $line) echo $line;
    }
    $up = [];
    foreach ($files as $file => $clazz){
        $sha1 = $fileHashes[$file];
        if (in_array($sha1, $dbHashes))
            continue;

        $up[] = sprintf(" %6.6s %30.30s %20.20s  %19.19s\n",
                        $sha1, preg_replace('|^db-migration/|', '', $file), $clazz, '');
    }
    if ($up){
        echo "upgrade:\n";
        foreach ($up as $line) echo $line;
    }
    echo "\n";

    if (!$down && !$up){
        echo "No changed.\n";
        return;
    }

    if (!$force && !prompt('Migrate?'))
        return;

    if ($down && $nodown)
        throw new Exception('Needs Downgrade!! Try manually.');


    foreach ($rows as $row){
        $hash = $row['hash'];
        if (in_array($hash, $fileHashes))
            continue;

        $data = str_replace('<?php', '', $row['data']);
        $data = preg_replace('/class ([^ ]*) implements/', 'class $1_down_'
                             . $hash . ' implements', $data);
        eval($data);
        $cls = get_declared_classes();
        $declared = end($cls);
        $obj = new $declared();
        echo "down: $hash";
        $obj->down($pdo);
        echo ' ...';
        $pdo->exec("delete from app_migration_info where hash = '$hash'");
        echo "ok\n";
    }

    foreach ($files as $file => $clazz){
        $sha1 = $fileHashes[$file];
        if (in_array($sha1, $dbHashes))
            continue;

        $obj = new $clazz();
        echo "up  : $sha1";
        $obj->up($pdo);

        if (!$nocheck){
            echo ' .';
            $obj->down($pdo);
            echo '.';
            $obj->up($pdo);
            echo '.';
        }else{
            echo ' ...';
        }

        $stmt = $pdo->prepare("
insert into app_migration_info(hash, file_name, data) values(?, ?, ?)");
        $stmt->execute([$sha1, $file, file_get_contents($file)]);
        echo "ok\n";
    }

    if ($test)
        $pdo->rollback();
    else
        $pdo->commit();

    decorate("Success!!", array('bggreen', 'white'));
}

$shortopts = 'hy';
$longopts = [
    'help'
    , 'yes'
    , 'dev'
    , 'prod'
    , 'test'
];
$opts = getopt($shortopts, $longopts);
if (isset($opts['h']) || isset($opts['help'])){
    echo "migration.php [-h|--help] [-y|--yes] [--dev]\n";
    echo "\n";
    echo 'PDO_DSN="pgsql:host=..." vendor/bin/db-migration.php' . "\n";
    echo "  or export PDO_DSN=\"...\"\n";
    echo "\n";
    echo "    -y --yes: no prompt\n";
    echo "       --dev: run upgrade downgrade upgrade, for check correct downgrade\n";
    echo "      --prod: downgrade not allowed\n";
    echo "      --test: test only, rollback transaction\n";
    echo "\n";
    exit(1);
}
$yes = isset($opts['y']) || isset($opts['yes']);
$nocheck = !isset($opts['dev']);
$prod = isset($opts['prod']);
$test = isset($opts['test']);

try {
    run($yes, $nocheck, $prod, $test);
}catch (Exception $e){
    decorate("Error!! " . $e->getMessage(), array('bgred', 'white'));
    throw $e;
}
