<?php
/**
 * _dbsync.php
 *
 * Narzedzie do synchronizacji bazy danych WordPressa przez HTTP.
 *
 * Dostep do narzedzia chroniony jest logowaniem (login + haslo, sesja PHP).
 * Dane logowania to stale DBSYNC_AUTH_LOGIN / DBSYNC_AUTH_PASS_HASH
 * na gorze pliku. Hash hasla wygenerujesz poleceniem:
 *   php -r "echo password_hash('TwojeHaslo', PASSWORD_DEFAULT);"
 *
 *   _dbsync.php (bez action)   -> lista plikow dumpu (pobieranie, SYNC, usuwanie)
 *   _dbsync.php?action=dump    -> zrzut bazy do ./_dbsync/YYMMDD-HHMMSS-dump-{db}.sql
 *   _dbsync.php?action=download&file=NAZWA -> pobranie pliku dumpu
 *   _dbsync.php?action=sync&file=NAZWA    -> wczytanie pliku do bazy
 *   _dbsync.php?action=delete (POST files[]) -> usuniecie zaznaczonych plikow
 *
 * Dane dostepowe do bazy (DB_NAME, DB_USER, DB_PASSWORD, DB_HOST) sa
 * czytane z wp-config.php. Do zrzutu uzywana jest binarka mysqldump,
 * do importu binarka mysql. Jesli binarki nie sa w PATH, sciezke mozna
 * podac recznie:
 *
 *   _dbsync.php?action=dump&MYSQLDUMP=/sciezka/mysqldump
 *   _dbsync.php?action=sync&MYSQL=/sciezka/mysql
 *
 * Jesli nie podano sciezki, binarki sa wykrywane automatycznie:
 * wyszukiwanie w PATH (where/which) oraz w znanych katalogach
 * (XAMPP, WAMP, Laragon, C:\srv\mysql-5.7\bin, /usr/bin,
 * /usr/local/mysql/bin, /opt/lampp/bin, MariaDB itd.).
 *
 * Skrypt wyswietla wszystkie bledy i komunikaty (w tym dokladna
 * komende), aby mozna bylo debugowac go zdalnie po wrzuceniu na
 * produkcje.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');

/* ---- Polyfill dla PHP < 5.6 ---- */
if (!function_exists('hash_equals')) {
    function hash_equals($known_string, $user_string)
    {
        $known_string = (string) $known_string;
        $user_string  = (string) $user_string;
        $known_len = strlen($known_string);
        $user_len  = strlen($user_string);
        if ($known_len !== $user_len) {
            return false;
        }
        $result = 0;
        for ($i = 0; $i < $known_len; $i++) {
            $result |= ord($known_string[$i]) ^ ord($user_string[$i]);
        }
        return $result === 0;
    }
}

/* ---- Polyfill dla PHP < 7.2 (brak stałej PHP_OS_FAMILY) ---- */
if (!defined('PHP_OS_FAMILY')) {
    define('PHP_OS_FAMILY', (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'Windows' : 'Unknown'));
}

define('DB_SYNC_DIR',  __DIR__ . '/_dbsync');

/* Dane logowania do narzedzia (niezalezne od konta WordPressa). */
/* Hash hasla wygenerujesz poleceniem: */
/*   php -r "echo password_hash('TwojeHaslo', PASSWORD_DEFAULT);" */
define('DBSYNC_AUTH_LOGIN', 'dbsync');
define('DBSYNC_AUTH_PASS_HASH', '$2y$12$EkVxv90j9DnzYPAg2K1vTOrcV46VmWiaQ8sqVmTj.BoefhHlyb9ee');

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

function db_sync_log($label, $value = '', $error = false)
{
    if ($error) {
        $tag = '<b style="color:#c00">[BLAD]</b>';
    } else {
        $tag = '<b style="color:#080">[OK]</b>';
    }
    echo '<div style="font-family:Consolas,monospace;font-size:13px;line-height:1.55">'
        . $tag . ' ' . htmlspecialchars($label)
        . ($value !== '' ? ': ' . htmlspecialchars($value) : '')
        . '</div>' . "\n";
    flush();
}

function db_sync_log_raw($text)
{
    echo '<div style="font-family:Consolas,monospace;font-size:12px;line-height:1.4;color:#333;background:#f4f4f4;border:1px solid #ddd;padding:6px 8px;margin:4px 0;white-space:pre-wrap">'
        . htmlspecialchars($text) . '</div>' . "\n";
    flush();
}

function db_sync_mask_password($cmd)
{
    return preg_replace('/--password=\S+/', '--password=***', $cmd);
}

function db_sync_exec($cmd, &$output, &$code)
{
    $output = array();
    $code   = -1;
    if (!function_exists('exec')) {
        throw new Exception('Funkcja exec() jest wylaczona na serwerze - nie moge uruchomic narzedzi CLI (mysql/mysqldump).');
    }
    exec($cmd, $output, $code);
}

function db_sync_parse_wp_config($path)
{
    $keys  = array('DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST');
    $creds = array_fill_keys($keys, '');
    $lines = @file($path);
    if ($lines === false) {
        throw new Exception('Nie moge odczytac pliku wp-config.php: ' . $path);
    }
    foreach ($lines as $line) {
        foreach ($keys as $key) {
            if (stripos($line, $key) === false) {
                continue;
            }
            if (preg_match("/define\(\s*['\"]" . preg_quote($key, '/') . "['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/i", $line, $m)) {
                $creds[$key] = $m[1];
            }
        }
    }
    return $creds;
}

function db_sync_host_port($host)
{
    if (strpos($host, '/') === 0) {
        // unix socket, np. /var/run/mysqld/mysqld.sock
        return array($host, '');
    }
    if (strpos($host, ':') !== false) {
        $parts = explode(':', $host, 2);
        return array($parts[0], $parts[1]);
    }
    return array($host, '');
}

function db_sync_safe_name($name)
{
    return preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
}

function db_sync_dump_filename($dbName)
{
    return date('ymd-His') . '-dump-' . db_sync_safe_name($dbName) . '.sql';
}

function db_sync_file_path($file)
{
    $file = basename($file);
    if ($file === '' || $file === '.' || $file === '..' || substr($file, -4) !== '.sql') {
        return false;
    }
    $path = DB_SYNC_DIR . '/' . $file;
    return is_file($path) ? $path : false;
}

function db_sync_human_size($bytes)
{
    $bytes = (float) $bytes;
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function db_sync_creds_summary($creds)
{
    $parts = array();
    foreach (array('DB_NAME', 'DB_USER', 'DB_HOST', 'DB_PASSWORD') as $key) {
        $val = isset($creds[$key]) ? $creds[$key] : '';
        if ($key === 'DB_PASSWORD') {
            $parts[] = 'DB_PASSWORD=' . ($val !== '' ? '***' : 'BRAK');
        } else {
            $parts[] = $key . '=' . ($val !== '' ? $val : 'BRAK');
        }
    }
    return implode(' | ', $parts);
}

function db_sync_test_connection($creds, $host, $port)
{
    $mysql = !empty($_GET['MYSQL']) ? trim($_GET['MYSQL']) : '';
    if ($mysql === '') {
        $mysql = db_sync_find_binary(array('mysql', 'mysql.exe'), db_sync_binary_candidates('mysql'));
    }
    if ($mysql === false) {
        return 'nie mozna przetestowac (brak binarki mysql)';
    }

    $cmd = db_sync_bin_arg($mysql)
        . db_sync_conn_args($host, $port)
        . ' --user=' . escapeshellarg($creds['DB_USER'])
        . ' --password=' . escapeshellarg($creds['DB_PASSWORD'])
        . ' --connect-timeout=5 --skip-column-names --execute=' . escapeshellarg('SELECT 1')
        . ' 2>&1';

    db_sync_exec($cmd, $out, $code);
    if ($code !== 0) {
        return 'BLAD: ' . trim(implode("\n", $out));
    }
    return true;
}

function db_sync_list_files()
{
    $files = array();
    if (is_dir(DB_SYNC_DIR)) {
        foreach (glob(DB_SYNC_DIR . '/*.sql') as $path) {
            $files[] = basename($path);
        }
    }
    // sortowanie po nazwie pliku malejaco (timestamp w nazwie = kolejka)
    rsort($files);
    return $files;
}

function db_sync_render_list()
{
    $files = db_sync_list_files();
    echo '<div style="font-family:Consolas,monospace;font-size:14px">';
    echo '<p style="margin:0 0 6px;display:flex;gap:10px;align-items:center">'
        . '<button type="button" onclick="location.href=\'' . htmlspecialchars($_SERVER['PHP_SELF']) . '\'" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#555;color:#fff;border:0;border-radius:4px">HOME</button>'
        . '<a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '?logout" style="font-family:Consolas,monospace;font-size:13px;color:#036">[wyloguj]</a>'
        . '</p>';
    echo '<h3 style="margin:4px 0 6px">Pliki dumpu bazy (' . count($files) . ')</h3>';
    if (empty($files)) {
        echo '<p>Brak plikow .sql w katalogu <b>_dbsync</b>.</p>';
    } else {
        echo '<form method="post" action="">'
            . '<ul style="list-style:none;padding:0;margin:0">';
        foreach ($files as $f) {
            $size = db_sync_human_size(filesize(DB_SYNC_DIR . '/' . $f));
            $downloadHref = '?action=download&file=' . rawurlencode($f);
            echo '<li style="margin:5px 0">'
                . '<input type="checkbox" name="files[]" value="' . htmlspecialchars($f) . '" style="margin-right:6px;vertical-align:middle">'
                . '<a href="' . $downloadHref . '" title="Pobierz plik dumpu">' . htmlspecialchars($f) . '</a>'
                . ' <small style="color:#888">(' . $size . ')</small></li>';
        }
        echo '</ul>'
            . '<button type="submit" name="action" value="sync" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#036;color:#fff;border:0;border-radius:4px;margin-top:10px;margin-right:6px" onclick="var c=this.form.querySelectorAll(\'input[type=checkbox]:checked\');if(!c.length){alert(\'Zaznacz pliki do synchronizacji\');return false;}if(c.length>1){alert(\'Do synchronizacji mozesz zaznaczyc tylko JEDEN plik (zaznaczono \'+c.length+\')\');return false;}return confirm(\'Synchronizowac zaznaczony plik do bazy?\')">SYNC</button>'
            . '<button type="submit" name="action" value="delete" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#c00;color:#fff;border:0;border-radius:4px;margin-top:10px" onclick="var c=this.form.querySelectorAll(\'input[type=checkbox]:checked\');if(!c.length){alert(\'Zaznacz pliki do usuniecia\');return false;}return confirm(\'Usunac zaznaczone pliki dumpu?\')">Usun zaznaczone</button>'
            . '</form>';
    }
    echo '<form method="get" action="" style="margin:14px 0 0">'
        . '<input type="hidden" name="action" value="dump">'
        . '<button type="submit" style="font-family:Consolas,monospace;font-size:14px;padding:8px 14px;cursor:pointer;background:#080;color:#fff;border:0;border-radius:4px">Generuj nowy dump bazy</button>'
        . '</form>';
    echo '</div>';
}

function db_sync_conn_args($host, $port)
{
    if (strpos($host, '/') === 0) {
        return ' --socket=' . escapeshellarg($host);
    }
    $args = ' --host=' . escapeshellarg($host);
    if ($port !== '') {
        $args .= ' --port=' . escapeshellarg($port);
    }
    return $args;
}

function db_sync_bin_arg($bin)
{
    if (PHP_OS_FAMILY === 'Windows') {
        return '"' . str_replace('"', '', $bin) . '"';
    }
    return escapeshellarg($bin);
}

function db_sync_binary_candidates($name)
{
    $candidates = array();
    if (PHP_OS_FAMILY === 'Windows') {
        $roots = array(
            'C:/srv/mysql-5.7/bin',
            'C:/xampp/mysql/bin',
            'C:/wamp64/bin/mysql',
            'C:/wamp/bin/mysql',
            'C:/laragon/bin/mysql',
            'C:/Program Files/MySQL',
            'C:/Program Files (x86)/MySQL',
            'C:/Program Files/MariaDB',
        );
        $exe = $name . '.exe';
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $candidates[] = $root . '/' . $exe;
            foreach (glob($root . '/*/bin/' . $exe) ?: array() as $p) {
                $candidates[] = $p;
            }
        }
    } else {
        $dirs = array('/usr/bin', '/usr/local/bin', '/usr/local/mysql/bin', '/opt/mysql/bin', '/opt/lampp/bin');
        foreach ($dirs as $dir) {
            $candidates[] = $dir . '/' . $name;
        }
        if ($name === 'mysqldump') {
            $candidates[] = '/usr/bin/mariadb-dump';
        }
        if ($name === 'mysql') {
            $candidates[] = '/usr/bin/mariadb';
        }
    }
    return $candidates;
}

function db_sync_find_binary($names, $candidates = array())
{
    // 1) szukanie w PATH przez where (Windows) / which (Linux)
    foreach ($names as $name) {
        foreach (array('where', 'which') as $lookup) {
            $out = array();
            $code = -1;
            db_sync_exec($lookup . ' ' . escapeshellarg($name) . ' 2>&1', $out, $code);
            if ($code === 0 && !empty($out)) {
                $path = trim($out[0]);
                if ($path !== ''
                    && stripos($path, 'not recognized') === false
                    && stripos($path, 'not found') === false
                    && stripos($path, 'could not find') === false) {
                    return $path;
                }
            }
        }
    }
    // 2) sprawdzenie znanych katalogow
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && (PHP_OS_FAMILY === 'Windows' || is_executable($candidate))) {
            return $candidate;
        }
    }
    return false;
}

function db_sync_probe($binary, $getParam)
{
    $bin = !empty($_GET[$getParam]) ? trim($_GET[$getParam]) : '';
    if ($bin === '') {
        $bin = db_sync_find_binary(array($binary, $binary . '.exe'), db_sync_binary_candidates($binary));
    }
    if ($bin === false) {
        $diag = array();
        db_sync_exec('where ' . escapeshellarg($binary) . ' 2>&1', $diag, $diagCode);
        throw new Exception(
            'Nie znaleziono binarki "' . $binary . '". Podaj sciezke recznie: ?' . $getParam . '=/pelna/sciezka/' . $binary
            . "\nSzczegoly: " . trim(implode("\n", $diag))
            . "\nPrzeszukano: PATH (where/which) oraz znane katalogi XAMPP, WAMP, Laragon, C:\\srv\\mysql-5.7\\bin, /usr/bin, /usr/local/mysql/bin, /opt/lampp/bin."
        );
    }
    $cmd = db_sync_bin_arg($bin) . ' --version 2>&1';
    db_sync_exec($cmd, $out, $code);
    if ($code !== 0) {
        throw new Exception(
            'Binarka "' . $bin . '" nie odpowiada (kod ' . $code . '). ' . trim(implode("\n", $out))
        );
    }
    db_sync_log('Binarka', $bin . ' -> ' . trim(implode("\n", $out)));
    return $bin;
}

/* ------------------------------------------------------------------ */
/* Dump                                                                */
/* ------------------------------------------------------------------ */

function db_sync_dump($creds, $host, $port)
{
    $sqlFile = DB_SYNC_DIR . '/' . db_sync_dump_filename($creds['DB_NAME']);
    db_sync_log('AKCJA', 'dump -> ' . $sqlFile);

    if (!is_dir(DB_SYNC_DIR)) {
        if (!@mkdir(DB_SYNC_DIR, 0755, true)) {
            throw new Exception('Nie moge utworzyc katalogu ' . DB_SYNC_DIR);
        }
        db_sync_log('KATALOG', 'utworzono ' . DB_SYNC_DIR);
    }
    if (!is_writable(DB_SYNC_DIR)) {
        throw new Exception('Katalog ' . DB_SYNC_DIR . ' nie jest zapisywalny.');
    }

    $mysqldump = db_sync_probe('mysqldump', 'MYSQLDUMP');

    $cmd = db_sync_bin_arg($mysqldump)
        . db_sync_conn_args($host, $port)
        . ' --user=' . escapeshellarg($creds['DB_USER'])
        . ' --password=' . escapeshellarg($creds['DB_PASSWORD'])
        . ' --single-transaction --quick --skip-lock-tables --no-tablespaces'
        . ' --default-character-set=utf8'
        . ' --add-drop-table'
        . ' --result-file=' . escapeshellarg($sqlFile)
        . ' ' . escapeshellarg($creds['DB_NAME'])
        . ' 2>&1';

    db_sync_log('KOMENDA', db_sync_mask_password($cmd));
    db_sync_exec($cmd, $output, $code);

    if ($code !== 0 || !is_file($sqlFile) || filesize($sqlFile) === 0) {
        throw new Exception(
            'mysqldump zakonczyl sie bledem (kod ' . $code . ').'
            . "\n" . trim(implode("\n", $output))
        );
    }

    $size = filesize($sqlFile);
    db_sync_log('DUMP GOTOWY', db_sync_human_size($size) . ' -> ' . $sqlFile);
    return $sqlFile;
}

/* ------------------------------------------------------------------ */
/* Sync (import)                                                       */
/* ------------------------------------------------------------------ */

function db_sync_import($creds, $host, $port, $file)
{
    $sqlFile = db_sync_file_path($file);
    if ($sqlFile === false) {
        throw new Exception('Nieprawidlowy lub nieistniejacy plik dumpu: ' . $file);
    }
    db_sync_log('AKCJA', 'sync <- ' . $sqlFile);

    if (filesize($sqlFile) === 0) {
        throw new Exception('Plik ' . $sqlFile . ' jest pusty.');
    }

    $mysql = db_sync_probe('mysql', 'MYSQL');

    $cmd = db_sync_bin_arg($mysql)
        . db_sync_conn_args($host, $port)
        . ' --user=' . escapeshellarg($creds['DB_USER'])
        . ' --password=' . escapeshellarg($creds['DB_PASSWORD'])
        . ' --max_allowed_packet=1G'
        . ' --default-character-set=utf8'
        . ' --database=' . escapeshellarg($creds['DB_NAME'])
        . ' < ' . escapeshellarg($sqlFile)
        . ' 2>&1';

    db_sync_log('KOMENDA', db_sync_mask_password($cmd));
    db_sync_exec($cmd, $output, $code);

    if ($code !== 0) {
        throw new Exception(
            'mysql zakonczyl sie bledem (kod ' . $code . ').'
            . "\n" . trim(implode("\n", $output))
        );
    }

    db_sync_log('SYNC GOTOWY', 'baza ' . $creds['DB_NAME'] . ' zostala zaladowana z ' . $sqlFile);
}

/* ------------------------------------------------------------------ */
/* Download                                                            */
/* ------------------------------------------------------------------ */

function db_sync_download($file)
{
    $path = db_sync_file_path($file);
    if ($path === false) {
        throw new Exception('Nieprawidlowy lub nieistniejacy plik dumpu: ' . $file);
    }
    $size = filesize($path);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: no-cache, must-revalidate');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    readfile($path);
    exit;
}

/* ------------------------------------------------------------------ */
/* Delete                                                              */
/* ------------------------------------------------------------------ */

function db_sync_delete($files)
{
    if (empty($files) || !is_array($files)) {
        throw new Exception('Nie zaznaczono zadnych plikow do usuniecia.');
    }
    $deleted = 0;
    foreach ($files as $file) {
        $path = db_sync_file_path($file);
        if ($path === false) {
            db_sync_log('POMINIETO', 'nieprawidlowy plik: ' . $file, true);
            continue;
        }
        if (@unlink($path)) {
            $deleted++;
            db_sync_log('USUNIETO', $file);
        } else {
            db_sync_log('BLAD USUWANIA', 'nie moge usunac: ' . $file, true);
        }
    }
    db_sync_log('USUWANIE GOTOWE', $deleted . ' plik(ow) usunieto');
}

/* ------------------------------------------------------------------ */
/* Autentykacja                                                         */
/* ------------------------------------------------------------------ */

function db_sync_render_login($error = '')
{
    $self  = htmlspecialchars($_SERVER['PHP_SELF']);
    $style = <<<CSS
body{margin:0;font-family:sans-serif;background:#f0f0f0;display:flex;justify-content:center;align-items:center;min-height:100vh}
.login{background:#fff;border:1px solid #ddd;border-radius:8px;padding:28px 32px;width:280px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.login h2{margin:0 0 4px;font-size:20px}
.login .sub{margin:0 0 16px;color:#888;font-size:13px}
.login input{display:block;width:100%;box-sizing:border-box;margin:0 0 10px;padding:8px 10px;font-size:14px;border:1px solid #ccc;border-radius:4px}
.login button{width:100%;padding:9px;font-size:14px;background:#036;color:#fff;border:0;border-radius:4px;cursor:pointer}
.login .error{color:#c00;font-size:13px;margin:0 0 10px}
CSS;
    echo '<!doctype html><html><head><meta charset="utf-8"><title>_dbsync - logowanie</title><style>' . $style . '</style></head><body>';
    echo '<div class="login">';
    echo '<h2>_dbsync</h2><p class="sub">Wymagane logowanie</p>';
    if ($error !== '') {
        echo '<p class="error">' . htmlspecialchars($error) . '</p>';
    }
    echo '<form method="post" action="' . $self . '">';
    echo '<input type="text" name="login" placeholder="login" autofocus autocomplete="username">';
    echo '<input type="password" name="password" placeholder="haslo" autocomplete="current-password">';
    echo '<button type="submit">Zaloguj</button>';
    echo '</form>';
    echo '</div></body></html>';
    exit;
}

/* ------------------------------------------------------------------ */
/* Main                                                                */
/* ------------------------------------------------------------------ */

// ---- autentykacja (musi byc przed jakakolwiek akcja, w tym download) ----
session_name('_dbsync');
session_start();

if (isset($_GET['logout'])) {
    $_SESSION['dbsync_logged'] = false;
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$authOk  = !empty($_SESSION['dbsync_logged']);
$authErr = '';
if (!$authOk && isset($_POST['login'], $_POST['password'])) {
    if (hash_equals(DBSYNC_AUTH_LOGIN, trim($_POST['login']))
        && password_verify((string) $_POST['password'], DBSYNC_AUTH_PASS_HASH)) {
        $_SESSION['dbsync_logged'] = true;
        $authOk = true;
    } else {
        $authErr = 'Nieprawidlowy login lub haslo';
    }
}

if (!$authOk) {
    db_sync_render_login($authErr);
}

$start = microtime(true);

// pobranie dumpu - czysty plik, bez interfejsu (przed jakimkolwiek HTML)
$pendingError = '';
if (isset($_GET['action']) && strtolower(trim($_GET['action'])) === 'download') {
    try {
        db_sync_download(isset($_GET['file']) ? $_GET['file'] : '');
    } catch (Exception $e) {
        $pendingError = $e->getMessage();
    }
}

// okno logu - lekko blendowane tlo, wyswietlane nad lista
echo '<div style="background:rgba(0,0,0,0.05);border:1px solid rgba(0,0,0,0.1);border-radius:6px;padding:10px 14px;margin-bottom:16px">' . "\n";
flush();

$serverIp   = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'nieznany');
$serverName = function_exists('gethostname') ? gethostname() : php_uname('n');
echo '<div style="font-family:Consolas,monospace;font-size:14px;line-height:1.55"><b>SERWER: ' . htmlspecialchars($serverName) . ' | IP: ' . htmlspecialchars($serverIp) . '</b></div>' . "\n";
flush();

try {
    $action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
    $action = strtolower(trim($action));

    if ($pendingError !== '') {
        throw new Exception($pendingError);
    }

    $creds = db_sync_parse_wp_config(__DIR__ . '/wp-config.php');

    list($host, $port) = db_sync_host_port($creds['DB_HOST']);

    db_sync_log('KONFIGURACJA', db_sync_creds_summary($creds));

    $conn = db_sync_test_connection($creds, $host, $port);
    if ($conn === true) {
        db_sync_log('POLACZENIE Z BAZA', 'OK (' . $creds['DB_NAME'] . ')');
    } else {
        db_sync_log('POLACZENIE Z BAZA', $conn, true);
    }

    if (($action === 'dump' || $action === 'sync') && ($creds['DB_NAME'] === '' || $creds['DB_USER'] === '')) {
        throw new Exception('Nie udalo sie odczytac danych bazy z wp-config.php (DB_NAME/DB_USER) - nie moge wykonac akcji.');
    }

    if ($action === 'dump') {
        db_sync_dump($creds, $host, $port);
    } elseif ($action === 'sync') {
        $file  = isset($_GET['file']) ? trim($_GET['file']) : '';
        $files = isset($_POST['files']) && is_array($_POST['files']) ? $_POST['files'] : array();
        if ($file !== '') {
            db_sync_import($creds, $host, $port, $file);
        } elseif (!empty($files)) {
            if (count($files) > 1) {
                throw new Exception('Do synchronizacji mozesz wybrac tylko JEDEN plik (zaznaczono ' . count($files) . '). Zaznacz jeden plik i kliknij SYNC ponownie.');
            }
            foreach ($files as $f) {
                db_sync_import($creds, $host, $port, $f);
            }
        } else {
            throw new Exception('Nie wybrano pliku do synchronizacji (zaznacz pliki i kliknij SYNC).');
        }
    } elseif ($action === 'delete') {
        $files = isset($_POST['files']) && is_array($_POST['files']) ? $_POST['files'] : array();
        db_sync_delete($files);
    }
} catch (Exception $e) {
    db_sync_log('WYJATEK', $e->getMessage(), true);
}

$elapsed = round(microtime(true) - $start, 2);
db_sync_log('CZAS WYKONANIA', $elapsed . ' s');

// zamkniecie okna logu
 echo '</div>' . "\n";
flush();

// lista z przyciskami - widoczna zawsze pod logiem
db_sync_render_list();
