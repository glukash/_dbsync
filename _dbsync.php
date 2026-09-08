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
 *   _dbsync.php?action=checkupdate    -> sprawdzenie nowszej wersji na GitHubie
 *   _dbsync.php?action=update         -> pobranie i zainstalowanie nowszej wersji
 *
 * Dane dostepowe do bazy (DB_NAME, DB_USER, DB_PASSWORD, DB_HOST) sa
 * czytane po kolei z:
 *   - wp-config.php (WordPress) - jesli istnieje
 *   - app/config/parameters.php (PrestaShop 1.7+) - jesli nie ma wp-config.php
 *   - config/settings.inc.php (PrestaShop 1.6) - jesli nie ma powyzszych
 * Do zrzutu uzywana jest binarka mysqldump,
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

/* Wersja skryptu (podbijana przy kazdym wydaniu) i repozytorium GitHub, */
/* z ktorego sprawdzane sa aktualizacje (tagi vX.Y.Z). */
define('DBSYNC_DATE', '2026-09-08');
define('DBSYNC_VERSION', '1.1.2');
define('DBSYNC_GITHUB_REPO', 'glukash/_dbsync');
define('DBSYNC_GITHUB_BRANCH', 'main');

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

function db_sync_parse_ps_config($path)
{
    $text = @file_get_contents($path);
    if ($text === false) {
        throw new Exception('Nie moge odczytac pliku parameters.php: ' . $path);
    }
    $creds = array('DB_NAME' => '', 'DB_USER' => '', 'DB_PASSWORD' => '', 'DB_HOST' => '');
    $port  = '';
    $map = array(
        'database_name'     => 'DB_NAME',
        'database_user'     => 'DB_USER',
        'database_password' => 'DB_PASSWORD',
        'database_host'     => 'DB_HOST',
        'database_port'     => 'DB_PORT',
    );
    foreach ($map as $psKey => $outKey) {
        if (!preg_match("/'" . preg_quote($psKey, '/') . "'\s*=>\s*(?:'([^']*)'|\"([^\"]*)\"|NULL)/i", $text, $m)) {
            continue;
        }
        $val = '';
        if (isset($m[1]) && $m[1] !== '') {
            $val = $m[1];
        } elseif (isset($m[2]) && $m[2] !== '') {
            $val = $m[2];
        }
        if ($outKey === 'DB_PORT') {
            $port = $val;
        } else {
            $creds[$outKey] = $val;
        }
    }
    if ($port !== '' && strpos($creds['DB_HOST'], '/') !== 0 && strpos($creds['DB_HOST'], ':') === false) {
        $creds['DB_HOST'] = $creds['DB_HOST'] . ':' . $port;
    }
    return $creds;
}

function db_sync_parse_ps16_config($path)
{
    $keys = array(
        '_DB_SERVER_' => 'DB_HOST',
        '_DB_NAME_'   => 'DB_NAME',
        '_DB_USER_'   => 'DB_USER',
        '_DB_PASSWD_' => 'DB_PASSWORD',
    );
    $creds = array('DB_NAME' => '', 'DB_USER' => '', 'DB_PASSWORD' => '', 'DB_HOST' => '');
    $lines = @file($path);
    if ($lines === false) {
        throw new Exception('Nie moge odczytac pliku settings.inc.php: ' . $path);
    }
    foreach ($lines as $line) {
        foreach ($keys as $const => $outKey) {
            if (stripos($line, $const) === false) {
                continue;
            }
            if (preg_match("/define\(\s*['\"]" . preg_quote($const, '/') . "['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/i", $line, $m)) {
                $creds[$outKey] = $m[1];
            }
        }
    }
    return $creds;
}

function db_sync_creds_from_disk()
{
    $wp = __DIR__ . '/wp-config.php';
    if (is_file($wp)) {
        return array('source' => 'wp-config.php', 'creds' => db_sync_parse_wp_config($wp));
    }
    $ps = __DIR__ . '/app/config/parameters.php';
    if (is_file($ps)) {
        return array('source' => 'app/config/parameters.php (PrestaShop 1.7+)', 'creds' => db_sync_parse_ps_config($ps));
    }
    $ps16 = __DIR__ . '/config/settings.inc.php';
    if (is_file($ps16)) {
        return array('source' => 'config/settings.inc.php (PrestaShop 1.6)', 'creds' => db_sync_parse_ps16_config($ps16));
    }
    throw new Exception('Nie znaleziono konfiguracji bazy danych (wp-config.php, app/config/parameters.php ani config/settings.inc.php).');
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

function db_sync_common_prefix($names)
{
    $names = array_values(array_filter(array_map('strval', $names)));
    if (count($names) < 2) {
        return '';
    }
    $prefix = $names[0];
    foreach (array_slice($names, 1) as $n) {
        while (substr($n, 0, strlen($prefix)) !== $prefix) {
            $prefix = substr($prefix, 0, -1);
            if ($prefix === '') {
                return '';
            }
        }
    }
    // ucina tylko gdy prefiks ma co najmniej 2 znaki, inaczej brak oszczednosci
    if (strlen($prefix) < 2) {
        return '';
    }
    return $prefix;
}

function db_sync_dump_filename($dbName, $exclude = array(), $prefix = '')
{
    $name = db_sync_safe_name($dbName);
    $suffix = '';
    if (!empty($exclude)) {
        $shown = array();
        foreach (array_slice($exclude, 0, 5) as $t) {
            $short = $t;
            if ($prefix !== '' && stripos($t, $prefix) === 0) {
                $short = substr($t, strlen($prefix));
                if ($short === '') {
                    $short = $t;
                }
            }
            $shown[] = db_sync_safe_name($short);
        }
        $suffix = implode('-', $shown);
        if (count($exclude) > 5) {
            $suffix .= '+' . (count($exclude) - 5);
        }
        $suffix = '_EXCLUDE[' . $suffix . ']';
    }
    return date('ymd-His') . '-dump-' . $name . $suffix . '.sql';
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
    $mysql = db_sync_get_param('MYSQL');
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
        . '<a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '?action=checkupdate" style="font-family:Consolas,monospace;font-size:13px;color:#080;font-weight:bold">[sprawdz aktualizacje]</a>'
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

function db_sync_table_size_label($bytes)
{
    $bytes = (float) $bytes;
    if ($bytes <= 0) {
        return '&mdash;';
    }
    if ($bytes >= 1048576) {
        return sprintf('%.2f MB', $bytes / 1048576);
    }
    if ($bytes >= 1024) {
        return sprintf('%.1f KB', $bytes / 1024);
    }
    return (int) $bytes . ' B';
}

function db_sync_render_dump_form($creds, $host, $port)
{
    echo '<div style="font-family:Consolas,monospace;font-size:14px">';
    echo '<p style="margin:0 0 6px">'
        . '<button type="button" onclick="location.href=\'' . htmlspecialchars($_SERVER['PHP_SELF']) . '\'" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#555;color:#fff;border:0;border-radius:4px">HOME</button>'
        . '</p>';

    try {
        $tables = db_sync_list_tables($creds, $host, $port);
    } catch (Exception $e) {
        echo '<p style="color:#c00">Nie moge pobrac listy tabel: ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</div>';
        return;
    }

    $excluded = db_sync_exclude_load($creds['DB_NAME']);

    echo '<h3 style="margin:4px 0 6px">Nowy dump bazy: ' . htmlspecialchars($creds['DB_NAME']) . ' (' . count($tables) . ' tabel)</h3>';
    echo '<p style="margin:0 0 10px;color:#555">Zaznaczenie checkboxa = tabela <b>WYKLUCZONA</b> ze zrzutu w calosci (struktura i dane). Zaznaczanie dziala odwrotnie niz w phpMyAdmin: zaznaczone tabele sa pomijane.</p>';
    echo '<form method="post" action="">'
        . '<input type="hidden" name="action" value="dump">';
    foreach (array('MYSQLDUMP', 'MYSQL') as $p) {
        if (!empty($_GET[$p])) {
            echo '<input type="hidden" name="' . $p . '" value="' . htmlspecialchars($_GET[$p]) . '">';
        }
    }
    echo '<p style="margin:0 0 8px">'
        . '<button type="button" onclick="var b=this.form.querySelectorAll(\'input[type=checkbox]\');for(var i=0;i<b.length;i++){b[i].checked=true;}" style="font-family:Consolas,monospace;font-size:13px;padding:4px 10px;cursor:pointer;background:#eee;border:1px solid #ccc;border-radius:4px;margin-right:6px">Zaznacz wszystkie</button>'
        . '<button type="button" onclick="var b=this.form.querySelectorAll(\'input[type=checkbox]\');for(var i=0;i<b.length;i++){b[i].checked=false;}" style="font-family:Consolas,monospace;font-size:13px;padding:4px 10px;cursor:pointer;background:#eee;border:1px solid #ccc;border-radius:4px">Odznacz wszystkie</button>'
        . '</p>'
        . '<table style="border-collapse:collapse;margin:12px 0">'
        . '<thead><tr style="background:#f2f2f2">'
        . '<th style="border:1px solid #ddd;padding:4px 10px;text-align:left">#</th>'
        . '<th style="border:1px solid #ddd;padding:4px 10px;text-align:left">Tabela</th>'
        . '<th style="border:1px solid #ddd;padding:4px 10px;text-align:right">Rozmiar</th>'
        . '<th style="border:1px solid #ddd;padding:4px 10px;text-align:center">Wyklucz ze zrzutu</th>'
        . '</tr></thead><tbody>';
    $i = 0;
    foreach ($tables as $t => $size) {
        $i++;
        $checked  = in_array($t, $excluded) ? ' checked' : '';
        $sizeHtml = db_sync_table_size_label($size);
        $bold     = $size > 10 * 1048576 ? 'font-weight:bold;' : '';
        $rowStyle = trim(($checked !== '' ? 'background:#ffe9e9;' : '') . $bold);
        echo '<tr' . ($rowStyle !== '' ? ' style="' . $rowStyle . '"' : '') . '>'
            . '<td style="border:1px solid #ddd;padding:4px 10px">' . $i . '</td>'
            . '<td style="border:1px solid #ddd;padding:4px 10px">' . htmlspecialchars($t) . '</td>'
            . '<td style="border:1px solid #ddd;padding:4px 10px;text-align:right">' . $sizeHtml . '</td>'
            . '<td style="border:1px solid #ddd;padding:4px 10px;text-align:center"><input type="checkbox" name="exclude[]" value="' . htmlspecialchars($t) . '"' . $checked . '></td>'
            . '</tr>';
    }
    echo '</tbody></table>'
        . '<button type="submit" onclick="var n=this.form.querySelectorAll(\'input[type=checkbox]:checked\').length;return confirm(\'Wykonac dump?\nWykluczonych tabel: \' + n);" style="font-family:Consolas,monospace;font-size:14px;padding:8px 14px;cursor:pointer;background:#080;color:#fff;border:0;border-radius:4px">Wykonaj dump</button>'
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

function db_sync_get_param($key)
{
    $v = isset($_POST[$key]) ? trim($_POST[$key]) : '';
    if ($v === '') {
        $v = isset($_GET[$key]) ? trim($_GET[$key]) : '';
    }
    return $v;
}

function db_sync_probe($binary, $getParam)
{
    $bin = db_sync_get_param($getParam);
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
/* Lista tabel i konfiguracja wykluczen                                */
/* ------------------------------------------------------------------ */

function db_sync_list_tables($creds, $host, $port)
{
    $mysql = db_sync_get_param('MYSQL');
    if ($mysql === '') {
        $mysql = db_sync_find_binary(array('mysql', 'mysql.exe'), db_sync_binary_candidates('mysql'));
    }
    if ($mysql === false) {
        throw new Exception('Nie moge pobrac listy tabel (brak binarki mysql). Podaj sciezke recznie: ?MYSQL=/pelna/sciezka/mysql');
    }

    $sql = "SELECT table_name, IFNULL(data_length + index_length, 0) "
        . "FROM information_schema.tables WHERE table_schema = '"
        . str_replace("'", "''", $creds['DB_NAME']) . "' ORDER BY table_name";

    $cmd = db_sync_bin_arg($mysql)
        . db_sync_conn_args($host, $port)
        . ' --user=' . escapeshellarg($creds['DB_USER'])
        . ' --password=' . escapeshellarg($creds['DB_PASSWORD'])
        . ' --database=' . escapeshellarg($creds['DB_NAME'])
        . ' --connect-timeout=5 --skip-column-names --execute=' . escapeshellarg($sql)
        . ' 2>&1';

    db_sync_exec($cmd, $out, $code);
    if ($code !== 0) {
        throw new Exception('Nie moge pobrac listy tabel: ' . trim(implode("\n", $out)));
    }

    $tables = array();
    foreach ($out as $line) {
        $line = rtrim($line);
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line, 2);
        if (count($parts) !== 2 || $parts[0] === '' || !preg_match('/^\d+(?:\.\d+)?$/', $parts[1])) {
            continue;
        }
        $name = $parts[0];
        $size = (float) $parts[1];
        $tables[$name] = $size;
    }
    return $tables;
}

function db_sync_exclude_path($dbName)
{
    return DB_SYNC_DIR . '/exclude-' . db_sync_safe_name($dbName) . '.json';
}

function db_sync_exclude_load($dbName)
{
    $path = db_sync_exclude_path($dbName);
    $json = is_file($path) ? @file_get_contents($path) : false;
    if ($json === false) {
        return array();
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['exclude']) || !is_array($data['exclude'])) {
        return array();
    }
    return db_sync_clean_exclude_list($data['exclude']);
}

function db_sync_exclude_save($dbName, $exclude)
{
    if (!is_dir(DB_SYNC_DIR)) {
        if (!@mkdir(DB_SYNC_DIR, 0755, true)) {
            throw new Exception('Nie moge utworzyc katalogu ' . DB_SYNC_DIR);
        }
    }
    $flags = defined('JSON_PRETTY_PRINT') ? (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 0;
    $path  = db_sync_exclude_path($dbName);
    $json  = json_encode(array('db' => $dbName, 'exclude' => array_values($exclude)), $flags);
    if (@file_put_contents($path, $json) === false) {
        throw new Exception('Nie moge zapisac pliku wykluczen: ' . $path);
    }
    db_sync_log('WYKLUCZENIA ZAPISANE', basename($path));
}

function db_sync_clean_exclude_list($exclude)
{
    $out = array();
    if (is_array($exclude)) {
        foreach ($exclude as $t) {
            $t = is_string($t) ? trim($t) : '';
            if ($t !== '') {
                $out[] = $t;
            }
        }
    }
    return array_values(array_unique($out));
}

function db_sync_filter_tables($creds, $host, $port, $exclude)
{
    if (empty($exclude)) {
        return array();
    }
    try {
        $tables = db_sync_list_tables($creds, $host, $port);
    } catch (Exception $e) {
        return $exclude;
    }
    return array_values(array_intersect($exclude, array_keys($tables)));
}

/* ------------------------------------------------------------------ */
/* Dump                                                                */
/* ------------------------------------------------------------------ */

function db_sync_dump($creds, $host, $port, $exclude = array())
{
    if (!is_dir(DB_SYNC_DIR)) {
        if (!@mkdir(DB_SYNC_DIR, 0755, true)) {
            throw new Exception('Nie moge utworzyc katalogu ' . DB_SYNC_DIR);
        }
        db_sync_log('KATALOG', 'utworzono ' . DB_SYNC_DIR);
    }
    if (!is_writable(DB_SYNC_DIR)) {
        throw new Exception('Katalog ' . DB_SYNC_DIR . ' nie jest zapisywalny.');
    }

    $prefix = '';
    if (!empty($exclude)) {
        try {
            $allTables = db_sync_list_tables($creds, $host, $port);
            $exclude = array_values(array_intersect($exclude, array_keys($allTables)));
            $prefix  = db_sync_common_prefix(array_keys($allTables));
        } catch (Exception $e) {
            // lista niedostepna - zostaw wykluczenia bez filtrowania
        }
    }

    $sqlFile = DB_SYNC_DIR . '/' . db_sync_dump_filename($creds['DB_NAME'], $exclude, $prefix);
    db_sync_log('AKCJA', 'dump -> ' . $sqlFile);

    if (!empty($exclude)) {
        db_sync_log('WYKLUCZONE TABELE', count($exclude) . ': ' . implode(', ', $exclude));
    } else {
        db_sync_log('WYKLUCZONE TABELE', 'brak (pelny dump)');
    }

    $mysqldump = db_sync_probe('mysqldump', 'MYSQLDUMP');

    $cmd = db_sync_bin_arg($mysqldump)
        . db_sync_conn_args($host, $port)
        . ' --user=' . escapeshellarg($creds['DB_USER'])
        . ' --password=' . escapeshellarg($creds['DB_PASSWORD'])
        . ' --single-transaction --quick --skip-lock-tables --no-tablespaces'
        . ' --default-character-set=utf8'
        . ' --add-drop-table';
    foreach ($exclude as $table) {
        $cmd .= ' --ignore-table=' . escapeshellarg($creds['DB_NAME'] . '.' . $table);
    }
    $cmd .= ' --result-file=' . escapeshellarg($sqlFile)
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

    if (!empty($exclude)) {
        db_sync_prepend_comment($sqlFile, $creds['DB_NAME'], $exclude);
    }

    $size = filesize($sqlFile);
    db_sync_log('DUMP GOTOWY', db_sync_human_size($size) . ' -> ' . $sqlFile);
    return $sqlFile;
}

function db_sync_prepend_comment($sqlFile, $dbName, $exclude)
{
    $comment = '-- _dbsync partial dump; baza: ' . $dbName
        . '; wykluczone tabele: ' . implode(', ', $exclude) . "\n";
    $tmp = $sqlFile . '.tmp';
    $in  = @fopen($sqlFile, 'rb');
    $out = @fopen($tmp, 'wb');
    if ($in === false || $out === false) {
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($out)) {
            fclose($out);
        }
        @unlink($tmp);
        return;
    }
    fwrite($out, $comment);
    while (!feof($in)) {
        $chunk = fread($in, 1048576);
        if ($chunk === false) {
            break;
        }
        fwrite($out, $chunk);
    }
    fclose($in);
    fclose($out);
    @rename($tmp, $sqlFile);
    @unlink($tmp);
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
/* Aktualizacje (GitHub)                                               */
/* ------------------------------------------------------------------ */

define('DBSYNC_GITHUB_TAGS_URL', 'https://api.github.com/repos/' . DBSYNC_GITHUB_REPO . '/tags?per_page=20');
define('DBSYNC_GITHUB_RAW_URL', 'https://raw.githubusercontent.com/' . DBSYNC_GITHUB_REPO . '/' . DBSYNC_GITHUB_BRANCH . '/_dbsync.php');

function db_sync_http_get($url, $timeout = 10)
{
    // 1) curl (najczesciej dostepny na hostingach)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => '_dbsync/' . DBSYNC_VERSION,
            CURLOPT_HTTPHEADER => array('Accept: application/vnd.github+json'),
        ));
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body !== false && $httpCode === 200) {
            return $body;
        }
        throw new Exception('curl: ' . ($err !== '' ? $err : 'HTTP ' . $httpCode));
    }
    // 2) file_get_contents (wymaga allow_url_fopen=On)
    if ((bool) ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => 'User-Agent: _dbsync/' . DBSYNC_VERSION
                    . "\r\nAccept: application/vnd.github+json\r\n",
            ),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false) {
            return $body;
        }
    }
    throw new Exception('Brak mozliwosci pobrania danych z sieci (curl wylaczony i allow_url_fopen=Off).');
}

function db_sync_normalize_version($tag)
{
    $v = trim((string) $tag);
    $v = preg_replace('/^[vV]\s*/', '', $v);
    return $v;
}

function db_sync_github_latest_tag()
{
    $json = db_sync_http_get(DBSYNC_GITHUB_TAGS_URL);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new Exception('Nie udalo sie sparsowac odpowiedzi GitHub API.');
    }
    if (isset($data['message'])) {
        throw new Exception('GitHub API: ' . $data['message']);
    }
    $tags = array();
    foreach ($data as $item) {
        if (!is_array($item) || !isset($item['name'])) {
            continue;
        }
        $v = db_sync_normalize_version($item['name']);
        if ($v !== '' && preg_match('/^\d+(\.\d+)*([.-][A-Za-z0-9.]+)?$/', $v)) {
            $tags[] = $v;
        }
    }
    if (empty($tags)) {
        throw new Exception('Na GitHubie nie znaleziono tagow z wersja (np. v1.0.12).');
    }
    usort($tags, 'version_compare');
    return end($tags);
}

function db_sync_remote_script()
{
    $body = db_sync_http_get(DBSYNC_GITHUB_RAW_URL);
    if (stripos(ltrim($body), '<?php') !== 0) {
        throw new Exception('Pobrany plik nie zaczyna sie od <?php - to nie jest skrypt _dbsync.php.');
    }
    return $body;
}

function db_sync_remote_version($body)
{
    if (preg_match("/define\s*\(\s*'DBSYNC_VERSION'\s*,\s*'([^']+)'\s*\)/", $body, $m)) {
        return $m[1];
    }
    return '';
}

function db_sync_opcache_clear($target)
{
    // OPcache moze serwowac stara skompilowana wersje mimo zmiany pliku na
    // dysku (szczegolnie przy opcache.validate_timestamps=0). Na produkcji nie
    // da sie restarcic Apache, wiec czyscimy cache z poziomu PHP (tam gdzie
    // to mozliwe - php-cgi/fpm z wlaczonym opcache).
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($target, true);
        @opcache_invalidate($target . '.tmp', true);
    }
}

function db_sync_apply_update($body)
{
    $target = __FILE__;
    $tmp = $target . '.tmp';
    if (@file_put_contents($tmp, $body) === false) {
        throw new Exception('Nie moge zapisac pliku tymczasowego: ' . $tmp);
    }
    // Windows: rename() nad istniejacym plikiem moze sie nie udac, gdy plik
    // jest w tym momencie otwarty przez inny proces (drugie zadanie php-cgi,
    // antywirus) - wtedy probujemy ponownie i nadpisujemy w miejscu (copy/
    // file_put_contents), ktore nie wymaga prawa do kasowania pliku.
    for ($i = 0; $i < 5; $i++) {
        if (@rename($tmp, $target)) {
            db_sync_opcache_clear($target);
            return true;
        }
        usleep(250000);
    }
    for ($i = 0; $i < 5; $i++) {
        if (@copy($tmp, $target)) {
            @unlink($tmp);
            db_sync_opcache_clear($target);
            return true;
        }
        usleep(250000);
    }
    for ($i = 0; $i < 5; $i++) {
        if (@file_put_contents($target, $body) !== false) {
            @unlink($tmp);
            db_sync_opcache_clear($target);
            return true;
        }
        usleep(250000);
    }
    @unlink($tmp);
    throw new Exception('Nie moge zastapic pliku: ' . $target . ' (plik zablokowany? zamknij dodatkowe karty z _dbsync.php i sprobuj ponownie)');
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
$showDumpForm = false;

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
$isLocal    = ($serverIp === '127.0.0.1' || $serverIp === '::1' || $serverIp === 'localhost');
$serverColor = $isLocal ? '#e80' : '#ff0000';
echo '<div style="font-family:Consolas,monospace;font-size:14px;line-height:1.55"><b>_DBSYNC VER: ' . DBSYNC_VERSION . ', ' . DBSYNC_DATE . '</b></div>' . "\n";
$httpHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'nieznany';
$cwd      = function_exists('getcwd') ? getcwd() : 'nieznany';
echo '<div style="font-family:Consolas,monospace;font-size:14px;line-height:1.55"><b style="color:' . $serverColor . '">' . ($isLocal ? 'LOKALNY' : 'PRODUKCJA!') . ' | SERWER: ' . htmlspecialchars($serverName) . ' | IP: ' . htmlspecialchars($serverIp) . ' | DOMENA: ' . htmlspecialchars($httpHost) . ' | KATALOG: ' . htmlspecialchars($cwd) . '</b></div>' . "\n";
flush();

$creds = array('DB_NAME' => '', 'DB_USER' => '', 'DB_PASSWORD' => '', 'DB_HOST' => '');
$host  = '';
$port  = '';

try {
    $action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
    $action = strtolower(trim($action));

    if ($pendingError !== '') {
        throw new Exception($pendingError);
    }

    // checkupdate/update nie wymagaja konfiguracji bazy - musza dzialac,
    // nawet gdy na serwerze nie znaleziono wp-config.php/parameters.php,
    // zeby zawsze mozna bylo zaktualizowac skrypt do wersji, ktora np.
    // dodaje obsluge kolejnego formatu konfiguracji.
    $needsDbConfig = ($action !== 'checkupdate' && $action !== 'update');

    if ($needsDbConfig) {
        $dbConfig = db_sync_creds_from_disk();
        $creds = $dbConfig['creds'];

        db_sync_log('KONFIGURACJA', 'odczytano z ' . $dbConfig['source']);
        list($host, $port) = db_sync_host_port($creds['DB_HOST']);

        db_sync_log('KONFIGURACJA', db_sync_creds_summary($creds));

        $conn = db_sync_test_connection($creds, $host, $port);
        if ($conn === true) {
            db_sync_log('POLACZENIE Z BAZA', 'OK (' . $creds['DB_NAME'] . ')');
        } else {
            db_sync_log('POLACZENIE Z BAZA', $conn, true);
        }
    }

    if (($action === 'dump' || $action === 'sync') && ($creds['DB_NAME'] === '' || $creds['DB_USER'] === '')) {
        throw new Exception('Nie udalo sie odczytac danych bazy z konfiguracji (DB_NAME/DB_USER) - nie moge wykonac akcji.');
    }

    if ($action === 'dump') {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $exclude = isset($_POST['exclude']) ? $_POST['exclude'] : array();
            $exclude = db_sync_clean_exclude_list($exclude);
            $exclude = db_sync_filter_tables($creds, $host, $port, $exclude);
            db_sync_exclude_save($creds['DB_NAME'], $exclude);
            db_sync_dump($creds, $host, $port, $exclude);
        } else {
            $showDumpForm = true;
        }
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
    } elseif ($action === 'checkupdate') {
        $latest = db_sync_github_latest_tag();
        if (version_compare($latest, DBSYNC_VERSION, '>')) {
            db_sync_log('AKTUALIZACJA', 'Dostepna nowsza wersja: ' . $latest . ' (obecna: ' . DBSYNC_VERSION . ')');
            $updUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?action=update';
            echo '<div style="font-family:Consolas,monospace;font-size:14px;padding:10px 14px;margin:8px 0;border:1px solid #0a0;background:#eaffea;border-radius:4px">'
                . '<b>Dostepna aktualizacja do wersji ' . htmlspecialchars($latest) . '</b> '
                . '<a href="' . $updUrl . '" onclick="return confirm(\'Pobrac i zainstalowac wersje ' . htmlspecialchars($latest) . '?\\nObecny plik zostanie ZASTAPIONY bez kopii zapasowej.\\n\')" '
                . 'style="font-family:Consolas,monospace;font-size:13px;padding:6px 14px;background:#080;color:#fff;border:0;border-radius:4px;text-decoration:none;cursor:pointer">[UPDATE]</a>'
                . '</div>' . "\n";
        } else {
            db_sync_log('AKTUALIZACJA', 'Brak nowszej wersji (najnowsza na GitHubie: ' . $latest . ', obecna: ' . DBSYNC_VERSION . ')');
        }
    } elseif ($action === 'update') {
        $body = db_sync_remote_script();
        $newVersion = db_sync_remote_version($body);
        if ($newVersion === '') {
            throw new Exception('Pobrany plik nie zawiera stalej DBSYNC_VERSION - nie podmieniam.');
        }
        if (version_compare($newVersion, DBSYNC_VERSION, '<')) {
            throw new Exception('Pobrana wersja ' . $newVersion . ' jest starsza niz zainstalowana ' . DBSYNC_VERSION . ' - nie podmieniam.');
        }
        db_sync_apply_update($body);
        db_sync_log('AKTUALIZACJA', 'Zainstalowano wersje ' . $newVersion . ' - odswiez strone (F5).');
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
if ($showDumpForm) {
    db_sync_render_dump_form($creds, $host, $port);
} else {
    db_sync_render_list();
}
