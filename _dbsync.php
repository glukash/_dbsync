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
 *                                 + lista archiwow plikow (pobieranie, usuwanie)
 *   _dbsync.php?action=dump    -> zrzut bazy do ./_dbsync/YYMMDD-HHMMSS-dump-{db}.sql
 *   _dbsync.php?action=download&file=NAZWA -> pobranie pliku dumpu
 *   _dbsync.php?action=sync&file=NAZWA    -> wczytanie pliku do bazy
 *   _dbsync.php?action=delete (POST files[]) -> usuniecie zaznaczonych plikow
 *   _dbsync.php?action=archive (GET)  -> formularz archiwum plikow serwisu
 *   _dbsync.php?action=archive (POST) -> utworzenie archiwum w ./_dbsyncf/
 *   _dbsync.php?action=downloadarchive&file=NAZWA -> pobranie archiwum
 *   _dbsync.php?action=deletearchive (POST archives[]) -> usuniecie archiwow
 *
 * Nazwa archiwum: YYMMDD-HHMMSS-{domena}.{ext}, np. 260923-103115-example.com.zip
 * (domena z zadania HTTP; przy braku - awaryjnie nazwa bazy).
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
 *   _dbsync.php?action=archive&7Z=/sciezka/7z (albo &ZIP=... / &TAR=...)
 *
 * Archiwa plikow tworzone sa narzedziami systemowymi: ZIP/7Z przez 7-Zip
 * (najbezpieczniejszy na Windows - poprawnie obsluguje polskie znaki),
 * TAR.GZ przez tar. Narzedzia sa wykrywane automatycznie (PATH + znane
 * katalogi), a archiwa zapisywane w ./_dbsyncf/ - katalog ten mozna wskazac
 * poza docroot zmieniajac stala DB_SYNC_ARCHIVE_DIR.
 *
 * Wykluczenia archiwum sa edytowalne w formularzu (plik
 * _dbsyncf/archive-exclude-{site}.txt). Domyslnie wykluczone sa m.in.
 * _dbsync/ (dumpy bazy), _dbsyncf/ (archiwa) i _dbsync.php (sam skrypt
 * narzedzia) - usuniecie wzorca z listy oznacza dolaczenie do archiwum.
 *
 * Archiwum powstaje najpierw jako plik *.part i dopiero po sprawdzeniu
 * wyniku jest przemianowywane na nazwe docelowa (YYMMDD-HHMMSS-{domena}.{ext}).
 * Jesli PHP zostanie ubity w trakcie pakowania (timeout serwera), narzedzie
 * systemowe zwykle konczy prace - przy nastepnym wejsciu na liste archiwow
 * plik .part jest weryfikowany (log narzedzia + zapisany kod wyjscia)
 * i automatycznie przemianowywany na nazwe docelowa.
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

/* Katalog na archiwa plikow serwisu (przycisk "Utworz archiwum").
   Mozna wskazac katalog poza docroot, np.: __DIR__ . '/../_dbsyncf' */
define('DB_SYNC_ARCHIVE_DIR', __DIR__ . '/_dbsyncf');

/* Dane logowania do narzedzia (niezalezne od konta WordPressa). */
/* Hash hasla wygenerujesz poleceniem: */
/*   php -r "echo password_hash('TwojeHaslo', PASSWORD_DEFAULT);" */
define('DBSYNC_AUTH_LOGIN', 'dbsync');
define('DBSYNC_AUTH_PASS_HASH', '$2y$12$EkVxv90j9DnzYPAg2K1vTOrcV46VmWiaQ8sqVmTj.BoefhHlyb9ee');

/* Wersja skryptu (podbijana przy kazdym wydaniu) i repozytorium GitHub, */
/* z ktorego sprawdzane sa aktualizacje (tagi vX.Y.Z). */
define('DBSYNC_DATE', '2026-09-23');
define('DBSYNC_VERSION', '1.5.0');
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

function db_sync_site_url()
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/') . '/';
    return $scheme . '://' . $host . $dir;
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
    if (is_dir(DB_SYNC_DIR)) {
        db_sync_htaccess_protect(DB_SYNC_DIR);
    }
    if (is_dir(DB_SYNC_ARCHIVE_DIR)) {
        db_sync_htaccess_protect(DB_SYNC_ARCHIVE_DIR);
    }
    echo '<div style="font-family:Consolas,monospace;font-size:14px">';
    echo '<p style="margin:0 0 6px;display:flex;gap:10px;align-items:center">'
        . '<button type="button" onclick="location.href=\'' . htmlspecialchars($_SERVER['PHP_SELF']) . '\'" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#555;color:#fff;border:0;border-radius:4px">HOME</button>'
        . '<button type="button" onclick="window.open(\'' . htmlspecialchars(db_sync_site_url()) . '\',\'_blank\')" title="Otworz serwis" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#555;color:#fff;border:0;border-radius:4px">-&gt;</button>'
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
            . '<button type="submit" name="action" value="sync" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#009eaf;color:#fff;border:0;border-radius:4px;margin-top:10px;margin-right:6px" onclick="var c=this.form.querySelectorAll(\'input[type=checkbox]:checked\');if(!c.length){alert(\'Zaznacz pliki do synchronizacji\');return false;}if(c.length>1){alert(\'Do synchronizacji mozesz zaznaczyc tylko JEDEN plik (zaznaczono \'+c.length+\')\');return false;}return confirm(\'Synchronizowac zaznaczony plik do bazy?\')">SYNC</button>'
            . '<button type="submit" name="action" value="delete" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#c00;color:#fff;border:0;border-radius:4px;margin-top:10px" onclick="var c=this.form.querySelectorAll(\'input[type=checkbox]:checked\');if(!c.length){alert(\'Zaznacz pliki do usuniecia\');return false;}return confirm(\'Usunac zaznaczone pliki dumpu?\')">Usun zaznaczone</button>'
            . '</form>';
    }
    echo '<form method="get" action="" style="margin:14px 0 0">'
        . '<input type="hidden" name="action" value="dump">'
        . '<button type="submit" style="font-family:Consolas,monospace;font-size:14px;padding:8px 14px;cursor:pointer;background:#af0090;color:#fff;border:0;border-radius:4px">Generuj nowy dump bazy</button>'
        . '</form>';

    $archives = db_sync_archive_list();
    echo '<h3 style="margin:20px 0 6px">Archiwa plikow (' . count($archives) . ')</h3>';
    if (empty($archives)) {
        echo '<p>Brak archiwow w katalogu <b>' . htmlspecialchars(basename(DB_SYNC_ARCHIVE_DIR)) . '</b>.</p>';
    } else {
        echo '<form method="post" action="">'
            . '<ul style="list-style:none;padding:0;margin:0">';
        foreach ($archives as $a) {
            $size = db_sync_human_size(filesize(DB_SYNC_ARCHIVE_DIR . '/' . $a));
            $downloadHref = '?action=downloadarchive&file=' . rawurlencode($a);
            echo '<li style="margin:5px 0">'
                . '<input type="checkbox" name="archives[]" value="' . htmlspecialchars($a) . '" style="margin-right:6px;vertical-align:middle">'
                . '<a href="' . $downloadHref . '" title="Pobierz archiwum">' . htmlspecialchars($a) . '</a>'
                . ' <small style="color:#888">(' . $size . ')</small></li>';
        }
        echo '</ul>'
            . '<button type="submit" name="action" value="deletearchive" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#c00;color:#fff;border:0;border-radius:4px;margin-top:10px" onclick="var c=this.form.querySelectorAll(\'input[type=checkbox]:checked\');if(!c.length){alert(\'Zaznacz archiwa do usuniecia\');return false;}return confirm(\'Usunac zaznaczone archiwa?\')">Usun zaznaczone</button>'
            . '</form>';
    }
    echo '<form method="get" action="" style="margin:14px 0 0">'
        . '<input type="hidden" name="action" value="archive">'
        . '<button type="submit" style="font-family:Consolas,monospace;font-size:14px;padding:8px 14px;cursor:pointer;background:#0a7;color:#fff;border:0;border-radius:4px">Utworz archiwum</button>'
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
    echo '<p style="margin:0 0 6px;display:flex;gap:10px;align-items:center">'
        . '<button type="button" onclick="location.href=\'' . htmlspecialchars($_SERVER['PHP_SELF']) . '\'" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#555;color:#fff;border:0;border-radius:4px">HOME</button>'
        . '<button type="button" onclick="window.open(\'' . htmlspecialchars(db_sync_site_url()) . '\',\'_blank\')" title="Otworz serwis" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#555;color:#fff;border:0;border-radius:4px">-&gt;</button>'
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
    echo '<p style="margin:0 0 12px">'
        . '<button type="submit" onclick="var n=this.form.querySelectorAll(\'input[type=checkbox]:checked\').length;return confirm(\'Wykonac dump?\nWykluczonych tabel: \' + n);" style="font-family:Consolas,monospace;font-size:14px;padding:8px 14px;cursor:pointer;background:#080;color:#fff;border:0;border-radius:4px">Wykonaj dump</button>'
        . '</p>'
        . '<p style="margin:0 0 8px">'
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

function db_sync_render_archive_form($creds)
{
    echo '<div style="font-family:Consolas,monospace;font-size:14px">';
    echo '<p style="margin:0 0 6px;display:flex;gap:10px;align-items:center">'
        . '<button type="button" onclick="location.href=\'' . htmlspecialchars($_SERVER['PHP_SELF']) . '\'" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#555;color:#fff;border:0;border-radius:4px">HOME</button>'
        . '<button type="button" onclick="window.open(\'' . htmlspecialchars(db_sync_site_url()) . '\',\'_blank\')" title="Otworz serwis" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#555;color:#fff;border:0;border-radius:4px">-&gt;</button>'
        . '</p>';

    if (is_dir(DB_SYNC_ARCHIVE_DIR)) {
        db_sync_htaccess_protect(DB_SYNC_ARCHIVE_DIR);
    }

    $status   = db_sync_archive_format_status();
    $root     = db_sync_archive_root();
    $site     = db_sync_archive_site_key($creds);
    $patterns = db_sync_archive_exclude_load($site);
    $hardDirs = db_sync_archive_hard_dirs();
    $stats    = db_sync_archive_collect($root, $patterns, $hardDirs, '', 5);
    $free     = is_dir(DB_SYNC_ARCHIVE_DIR) ? @disk_free_space(DB_SYNC_ARCHIVE_DIR) : @disk_free_space($root);

    $rows    = array();
    $default = '';
    foreach ($status as $key => $st) {
        $ok   = $st['ok'];
        $note = '';
        if ($ok) {
            $note = 'narzedzie: ' . $st['bin'];
            if (!db_sync_archive_tool_utf8_ok($st['tool']) && $stats['non_ascii']) {
                $ok   = false;
                $note = htmlspecialchars(basename($st['bin'])) . ' na Windows moze pomijac nazwy z polskimi znakami - wybierz ZIP albo 7Z (7-Zip)';
            }
        } else {
            $note = 'niedostepny: brak narzedzia (' . implode(' / ', $st['fmt']['tools']) . ')';
        }
        if ($ok && $default === '') {
            $default = $key;
        }
        $rows[$key] = array(
            'ok'    => $ok,
            'note'  => $note,
            'label' => $st['fmt']['label'] . ' (' . $st['fmt']['ext'] . ')',
        );
    }
    if (isset($rows['zip']) && $rows['zip']['ok']) {
        $default = 'zip';
    }

    echo '<h3 style="margin:4px 0 6px">Archiwum plikow serwisu</h3>';
    echo '<p style="margin:0 0 4px;color:#555">Katalog zrodlowy: <b>' . htmlspecialchars($root) . '</b> | katalog docelowy: <b>' . htmlspecialchars(DB_SYNC_ARCHIVE_DIR) . '</b></p>';
    echo '<p style="margin:0 0 4px;color:#555">Do archiwizacji: <b>' . ($stats['partial'] ? 'co najmniej ' : '') . $stats['files'] . '</b> plikow'
        . ' (' . db_sync_human_size($stats['bytes']) . ')'
        . ($stats['partial'] ? ' - szacowanie przerwane po 5 s' : '')
        . ($stats['skipped'] > 0 ? ', pominieto ' . $stats['skipped'] . ' pozycji' : '')
        . ($free !== false ? ' | wolne miejsce: ' . db_sync_human_size($free) : '')
        . '</p>';
    echo '<p style="margin:0 0 4px;color:#555">Bezwarunkowo pominiete: <b>'
        . (empty($hardDirs) ? 'brak' : htmlspecialchars(implode(', ', $hardDirs)))
        . '</b> (katalog archiwow - archiwa nie trafiaja do archiwum).</p>';
    // wzorce edytowalne: pokazujemy, co jest aktualnie wykluczone, a co dolaczone
    $exclManaged = array();
    $inclManaged = array();
    foreach (db_sync_archive_managed_exclude() as $managed) {
        if ($managed === '_dbsyncf/') {
            continue; // katalog archiwow jest pomijany bezwarunkowo
        }
        if (db_sync_archive_exclude_has($patterns, $managed)) {
            $exclManaged[] = $managed;
        } else {
            $inclManaged[] = $managed;
        }
    }
    if (!empty($exclManaged) || !empty($inclManaged)) {
        echo '<p style="margin:0 0 10px;color:#555">';
        if (!empty($exclManaged)) {
            echo 'Wykluczone wzorcami z listy ponizej: <b>' . htmlspecialchars(implode(', ', $exclManaged)) . '</b>'
                . ' - usun wzorzec, jesli chcesz dolaczyc go do archiwum.';
        }
        if (!empty($inclManaged)) {
            echo ($exclManaged ? '<br>' : '') . 'Brak wzorca na liscie (trafi do archiwum): <b>'
                . htmlspecialchars(implode(', ', $inclManaged)) . '</b>.';
        }
        echo '</p>';
    }

    echo '<script>function dbsyncArchiveConfirm(btn){var f=btn.form;var r=f.querySelector(\'input[name=format]:checked\');var l=r?r.getAttribute(\'data-label\'):\'?\';return confirm(\'Utworzyc archiwum plikow?\nFormat: \'+l+\'\nMoze to potrwac kilka minut.\');}</script>';

    echo '<form method="post" action="">'
        . '<input type="hidden" name="action" value="archive">';
    foreach (array('7Z', 'ZIP', 'TAR') as $param) {
        if (!empty($_GET[$param])) {
            echo '<input type="hidden" name="' . $param . '" value="' . htmlspecialchars($_GET[$param]) . '">';
        }
    }
    echo '<p style="margin:0 0 4px">Format:</p>';
    foreach ($rows as $key => $row) {
        $label = htmlspecialchars($row['label']);
        echo '<div style="margin:3px 0">'
            . '<label><input type="radio" name="format" value="' . htmlspecialchars($key) . '" data-label="' . $label . '"'
            . (($key === $default) ? ' checked' : '') . ($row['ok'] ? '' : ' disabled') . '> '
            . $label . '</label> '
            . '<small style="color:#888">' . htmlspecialchars($row['note']) . '</small>'
            . '</div>';
    }
    echo '<p style="margin:12px 0 4px">Wykluczenia (jeden wzorzec na linie; katalog konczy "/", np. <b>cache/</b>; wzorce z gwiazdka, np. <b>*.log</b>):</p>'
        . '<textarea name="exclude" rows="8" cols="60" style="font-family:Consolas,monospace;font-size:13px;padding:6px">'
        . htmlspecialchars(implode("\n", $patterns)) . '</textarea>';
    echo '<p style="margin:12px 0 0">';
    if ($default !== '') {
        echo '<button type="submit" onclick="return dbsyncArchiveConfirm(this);" style="font-family:Consolas,monospace;font-size:14px;padding:8px 14px;cursor:pointer;background:#0a7;color:#fff;border:0;border-radius:4px">Utworz archiwum</button>';
    } else {
        echo '<b style="color:#c00">Brak dostepnych narzedzi archiwizujacych (7-Zip / zip / tar). Zainstaluj 7-Zip albo podaj sciezke w URL, np. ?7Z=C:/sciezka/7z.exe</b>';
    }
    echo '</p></form>';
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

function db_sync_find_binary($names, $candidates = array(), $lookups = array('where', 'which'))
{
    // 1) szukanie w PATH przez where (Windows) / which (Linux)
    foreach ($names as $name) {
        foreach ($lookups as $lookup) {
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
    db_sync_htaccess_protect(DB_SYNC_DIR);

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

function db_sync_send_file($path, $mime, $filename = '')
{
    if ($filename === '') {
        $filename = basename($path);
    }
    $size = filesize($path);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: no-cache, must-revalidate');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    readfile($path);
    exit;
}

function db_sync_download($file)
{
    $path = db_sync_file_path($file);
    if ($path === false) {
        throw new Exception('Nieprawidlowy lub nieistniejacy plik dumpu: ' . $file);
    }
    db_sync_send_file($path, 'application/octet-stream');
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
/* Archiwa plikow (narzedzia systemowe: 7-Zip / zip / tar)             */
/* ------------------------------------------------------------------ */

function db_sync_archive_root()
{
    return rtrim(str_replace('\\', '/', __DIR__), '/');
}

function db_sync_archive_hard_dirs()
{
    // Bezwarunkowo (poza lista wykluczen, ktora jest edytowalna) pomijany jest
    // tylko katalog, do ktorego trafia samo archiwum - inaczej archiwum
    // pakowaloby poprzednie archiwa (i wlasny plik tymczasowy *.part).
    // Katalog _dbsync (dumpy bazy) jest zwyklym wzorcem w wykluczeniach.
    $root = db_sync_archive_root();
    $out  = array();
    $arc  = rtrim(str_replace('\\', '/', DB_SYNC_ARCHIVE_DIR), '/');
    if ($arc !== '' && $arc !== $root && strpos($arc . '/', $root . '/') === 0) {
        $rel = trim(substr($arc, strlen($root)), '/');
        if ($rel !== '') {
            $out[] = $rel;
        }
    }
    return $out;
}

function db_sync_archive_site_key($creds)
{
    $key = '';
    if (is_array($creds) && !empty($creds['DB_NAME'])) {
        $key = db_sync_safe_name($creds['DB_NAME']);
    }
    if ($key === '') {
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $host = preg_replace('/:\d+$/', '', (string) $host);
        $key  = db_sync_safe_name($host);
    }
    if ($key === '') {
        $key = db_sync_safe_name(basename(db_sync_archive_root()));
    }
    if ($key === '') {
        $key = 'site';
    }
    return $key;
}

function db_sync_archive_domain_key()
{
    // Domena z biezacego zadania HTTP -> nazwa pliku archiwum
    // (YYMMDD-HHMMSS-{domena}.{ext}). Kropki zostaja, bo domena ma byc czytelna.
    $host = '';
    foreach (array('HTTP_HOST', 'SERVER_NAME') as $key) {
        if (!empty($_SERVER[$key])) {
            $host = (string) $_SERVER[$key];
            break;
        }
    }
    $host = strtolower(preg_replace('/:\d+$/', '', trim($host)));
    $host = preg_replace('/[^a-z0-9.-]/', '_', $host);
    return trim($host, '.-_');
}

function db_sync_htaccess_protect($dir)
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    if ($dir === '' || !is_dir($dir)) {
        return false;
    }
    $path = $dir . '/.htaccess';
    if (is_file($path)) {
        return true;
    }
    $content = "# _dbsync - blokada dostepu z sieci (pliki zawieraja dane wrazliwe)\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    if (@file_put_contents($path, $content) === false) {
        db_sync_log('OCHRONA', 'UWAGA: nie moge utworzyc ' . $path . ' - zablokuj dostep do katalogu recznie (Apache/nginx)', true);
        return false;
    }
    db_sync_log('OCHRONA', 'utworzono ' . $path . ' (deny all)');
    return true;
}

function db_sync_archive_dir($create = false)
{
    $dir = DB_SYNC_ARCHIVE_DIR;
    if (!is_dir($dir)) {
        if (!$create) {
            return false;
        }
        if (!@mkdir($dir, 0755, true)) {
            throw new Exception('Nie moge utworzyc katalogu ' . $dir);
        }
        db_sync_log('KATALOG', 'utworzono ' . $dir);
    }
    if ($create) {
        if (!is_writable($dir)) {
            throw new Exception('Katalog ' . $dir . ' nie jest zapisywalny.');
        }
        db_sync_htaccess_protect($dir);
    }
    return $dir;
}

/* Opieka nad plikami tymczasowymi w katalogu archiwow:
   1) dokonczenie archiwow przerwanych po stronie PHP (np. ubity proces CGI
      przez timeout serwera) - jesli log i kod wyjscia narzedzia pakujacego
      potwierdzaja sukces, plik .part trafia na liste jako archiwum gotowe,
   2) usuniecie osieroconych plikow tymczasowych starszych niz 24h.
   Zwraca liste odtworzonych archiwow. */
function db_sync_archive_cleanup_parts($quietSeconds = 300)
{
    $dir = DB_SYNC_ARCHIVE_DIR;
    if (!is_dir($dir)) {
        return array();
    }
    $recovered = db_sync_archive_recover_parts($quietSeconds);
    clearstatcache();
    // osierocone pliki po nieudanym zadaniu (starsze niz 24h)
    foreach (array('/*.part', '/dbsync-*') as $mask) {
        foreach (glob($dir . $mask) ?: array() as $path) {
            if (is_file($path) && (time() - filemtime($path)) > 86400) {
                @unlink($path);
            }
        }
    }
    return $recovered;
}

/* Dla pliku <nazwa>.part zwraca nazwy plikow towarzyszacych (nazwa docelowa,
   lista plikow, log narzedzia, plik statusu). Nazwy te sa pochodna nazwy
   docelowej, a nie losowe - dzieki temu po przerwanym zadaniu wiadomo,
   do ktorego archiwum nalezy osierocony plik .part. */
function db_sync_archive_part_paths($part)
{
    $base = basename($part);
    if (substr($base, -5) !== '.part') {
        return false;
    }
    $stamp = substr($base, 0, -5);
    if ($stamp === '') {
        return false;
    }
    $dir = dirname($part);
    return array(
        'stamp'  => $stamp,
        'target' => $dir . '/' . $stamp,
        'list'   => $dir . '/dbsync-list-' . $stamp . '.txt',
        'log'    => $dir . '/dbsync-log-' . $stamp . '.txt',
        'status' => $dir . '/dbsync-status-' . $stamp . '.txt',
    );
}

/* Dopisek do komendy narzedzia: powloka zapisuje kod wyjscia do pliku
   statusu PO zakonczeniu narzedzia, czyli rowniez wtedy, gdy proces PHP
   zostanie ubity i nie wykona juz zadnego kodu. Bez tego nie da sie
   odroznic archiwum gotowego od przerwanego w polowie. */
function db_sync_archive_status_suffix($statusFile)
{
    $st = db_sync_bin_arg($statusFile);
    if (PHP_OS_FAMILY === 'Windows') {
        // uwaga: "%ERRORLEVEL%" rozwija sie PRZED uruchomieniem narzedzia,
        // dlatego wynik sprawdzamy konstrukcja "if errorlevel" (oceniana
        // w trakcie wykonywania); spacja przed ">>" jest konieczna, inaczej
        // cmd potraktowalby konczaca cyfre jako numer strumienia
        return ' & if errorlevel 2 (echo exit=2 >>' . $st . ')'
            . ' else if errorlevel 1 (echo exit=1 >>' . $st . ')'
            . ' else (echo exit=0 >>' . $st . ')';
    }
    // kod wyjscia zostawiamy tez dla exec(), zeby nie zmienic jego wyniku
    return '; ec=$?; echo exit=$ec >> ' . $st . '; exit $ec';
}

/* Kod wyjscia narzedzia zapisany przez powloke (null, gdy brak pliku). */
function db_sync_archive_status_code($statusFile)
{
    if (!is_file($statusFile)) {
        return null;
    }
    $text = @file_get_contents($statusFile);
    if ($text === false || !preg_match('/exit=(\d+)/', $text, $m)) {
        return null;
    }
    return (int) $m[1];
}

/* Weryfikacja osieroconego pliku .part: '' gdy archiwum jest kompletne,
   inaczej powod odrzucenia (tekst). */
function db_sync_archive_part_verdict($part, $info)
{
    $size = @filesize($part);
    if ($size === false || $size === 0) {
        return 'plik pusty';
    }
    if (!is_file($info['list'])) {
        return 'brak listy plikow narzedzia';
    }
    // log moze byc pusty - "zip -q" i "tar" nie wypisuja nic przy sukcesie
    $tail = is_file($info['log']) ? db_sync_archive_log_tail($info['log']) : '';
    foreach (db_sync_archive_output_errors($tail) as $line) {
        return 'log narzedzia zawiera blad: ' . $line;
    }
    $expected = 0;
    $fh = @fopen($info['list'], 'rb');
    if ($fh !== false) {
        while (($line = fgets($fh)) !== false) {
            if (trim($line) !== '') {
                $expected++;
            }
        }
        fclose($fh);
    }
    if ($expected === 0) {
        return 'pusta lista plikow';
    }
    if (preg_match('/Files read from disk:\s*(\d+)/', $tail, $m)) {
        // 7-Zip: log podaje liczbe przetworzonych plikow - najmocniejszy dowod
        if (stripos($tail, 'Everything is Ok') === false) {
            return 'brak potwierdzenia "Everything is Ok" w logu';
        }
        if ((int) $m[1] !== $expected) {
            return 'narzedzie przetworzylo ' . (int) $m[1] . ' z ' . $expected . ' plikow';
        }
        return '';
    }
    // brak podsumowania w logu (zip/tar) - rozstrzyga kod wyjscia zapisany
    // przez powloke po zakonczeniu narzedzia
    $code = db_sync_archive_status_code($info['status']);
    if ($code === null) {
        return 'brak pliku statusu (nie wiadomo, czy narzedzie skonczylo prace)';
    }
    if ($code > 1) {
        return 'narzedzie zakonczylo sie bledem (kod ' . $code . ')';
    }
    return '';
}

/* Ratowanie archiwow po zadaniach przerwanych przez serwer: gdy log i status
   narzedzia potwierdzaja sukces, dokoncza zmiane nazwy .part -> nazwa docelowa
   (PHP tego nie zrobil, bo zostal ubity). Pliki mlodsze niz $quietSeconds
   pomijamy - moze je wlasnie zapisywac narzedzie. */
function db_sync_archive_recover_parts($quietSeconds = 300)
{
    $dir = DB_SYNC_ARCHIVE_DIR;
    $done = array();
    if (!is_dir($dir)) {
        return $done;
    }
    $parts = glob($dir . '/*.part');
    if ($parts === false) {
        return $done;
    }
    clearstatcache();
    foreach ($parts as $part) {
        if (!is_file($part)) {
            continue;
        }
        $info = db_sync_archive_part_paths($part);
        if ($info === false) {
            continue;
        }
        if (time() - (int) @filemtime($part) < $quietSeconds) {
            continue;
        }
        $verdict = db_sync_archive_part_verdict($part, $info);
        if ($verdict !== '') {
            db_sync_log('PLIK .PART', basename($part) . ': ' . $verdict
                . ' (zadanie przerwane albo nadal trwa; usuniecie po 24h)', true);
            continue;
        }
        if (!@rename($part, $info['target'])) {
            db_sync_log('OSTRZEZENIE', 'nie moge odtworzyc archiwum ' . basename($info['target'])
                . ' z pliku ' . basename($part), true);
            continue;
        }
        db_sync_archive_unlink(array($info['list'], $info['log'], $info['status']));
        $size = @filesize($info['target']);
        if ($size === false) {
            $size = 0;
        }
        db_sync_log('ARCHIWUM ODTWORZONE', basename($info['target']) . ' (' . db_sync_human_size($size) . ')'
            . ' - narzedzie zakonczylo prace, ale PHP nie zmienilo nazwy pliku .part');
        $done[] = basename($info['target']);
    }
    return $done;
}

function db_sync_archive_unlink($paths)
{
    foreach ((array) $paths as $path) {
        if ($path !== '' && $path !== false && is_file($path)) {
            @unlink($path);
        }
    }
}

function db_sync_archive_formats()
{
    return array(
        'zip'    => array('ext' => '.zip',    'label' => 'ZIP',    'tools' => array('7z', 'zip')),
        '7z'     => array('ext' => '.7z',     'label' => '7Z',     'tools' => array('7z')),
        'tar.gz' => array('ext' => '.tar.gz', 'label' => 'TAR.GZ', 'tools' => array('tar')),
    );
}

function db_sync_archive_tool_names()
{
    return array(
        '7z'  => array('7z', '7za', '7zz', '7z.exe', '7za.exe', '7zz.exe'),
        'zip' => array('zip', 'zip.exe'),
        'tar' => array('tar', 'tar.exe'),
    );
}

function db_sync_archive_tool_arg($key)
{
    return strtoupper(str_replace('.', '', $key));
}

function db_sync_archive_candidates($name)
{
    $candidates = array();
    if (PHP_OS_FAMILY === 'Windows') {
        $roots = array(
            'C:/Program Files/7-Zip',
            'C:/Program Files (x86)/7-Zip',
            'C:/ProgramData/chocolatey/bin',
            'C:/Windows/System32',
        );
    } else {
        $roots = array('/usr/bin', '/bin', '/usr/local/bin', '/usr/sbin', '/sbin', '/snap/bin', '/opt/lampp/bin');
    }
    foreach ($roots as $root) {
        if (is_dir($root)) {
            $candidates[] = $root . '/' . $name;
        }
    }
    return $candidates;
}

function db_sync_archive_tools()
{
    static $tools = null;
    if ($tools !== null) {
        return $tools;
    }
    $lookups = (PHP_OS_FAMILY === 'Windows') ? array('where') : array('which');
    $tools   = array();
    foreach (db_sync_archive_tool_names() as $key => $names) {
        // reczne wskazanie sciezki w URL, np. ?7Z=/pelna/sciezka/7z
        $param = db_sync_get_param(db_sync_archive_tool_arg($key));
        if ($param !== '') {
            if (is_file($param)) {
                $tools[$key] = $param;
            }
            continue;
        }
        $candidates = array();
        foreach ($names as $name) {
            foreach (db_sync_archive_candidates($name) as $candidate) {
                $candidates[] = $candidate;
            }
        }
        $bin = db_sync_find_binary($names, $candidates, $lookups);
        if ($bin !== false) {
            $tools[$key] = $bin;
        }
    }
    return $tools;
}

function db_sync_archive_format_status()
{
    $tools = db_sync_archive_tools();
    $out   = array();
    foreach (db_sync_archive_formats() as $key => $fmt) {
        $tool = '';
        $bin  = '';
        foreach ($fmt['tools'] as $candidate) {
            if (isset($tools[$candidate])) {
                $tool = $candidate;
                $bin  = $tools[$candidate];
                break;
            }
        }
        $out[$key] = array(
            'fmt'  => $fmt,
            'ok'   => ($tool !== ''),
            'tool' => $tool,
            'bin'  => $bin,
        );
    }
    return $out;
}

function db_sync_archive_tool_utf8_ok($tool)
{
    // 7-Zip czyta liste plikow jako UTF-8 (na Windows tez); bsdtar/zip
    // na Windows oczekuja strony kodowej ANSI i cicho gubia polskie znaki.
    if (PHP_OS_FAMILY !== 'Windows') {
        return true;
    }
    return ($tool === '7z');
}

function db_sync_archive_default_exclude()
{
    return array(
        // dumpy bazy, archiwa i sam skrypt narzedzia - wzorce edytowalne:
        // usuniecie wzorca z listy oznacza dolaczenie do archiwum
        '_dbsync/',
        '_dbsyncf/',
        '_dbsync.php',
        '.git/',
        '.svn/',
        'node_modules/',
        'cache/',
        'var/cache/',
        'wp-content/cache/',
        '*.log',
        '*.tmp',
        '.DS_Store',
        'Thumbs.db',
    );
}

/* Wzorce "systemowe" - dopisywane jednorazowo do pliku wykluczen przy
   migracji (patrz db_sync_archive_exclude_load). */
function db_sync_archive_managed_exclude()
{
    return array('_dbsync/', '_dbsyncf/', '_dbsync.php');
}

function db_sync_archive_exclude_path($site)
{
    return DB_SYNC_ARCHIVE_DIR . '/archive-exclude-' . $site . '.txt';
}

/* Znacznik w pliku wykluczen: obecnosc znacznika danej wersji = plik
   obslugiwany przez wersje skryptu, ktora migracje ma juz za soba;
   brak znacznika (albo znacznik starszej wersji) = jednorazowe uzupelnienie
   brakujacych wzorcow systemowych. */
function db_sync_archive_exclude_marker()
{
    return '# dbsync-exclude-v3';
}

/* Wzorce dopisywane przy migracji - zaleznie od wersji pliku:
   brak znacznika  = plik z wersji <= 1.3.1 (_dbsync/ i _dbsyncf/ byly
                     wtedy wykluczone na sztywno),
   znacznik v2     = plik z 1.4.0 (dochodzi tylko wzorzec samego skryptu). */
function db_sync_archive_exclude_migration($text)
{
    if (strpos($text, '# dbsync-exclude-') === false) {
        return db_sync_archive_managed_exclude();
    }
    if (strpos($text, '# dbsync-exclude-v2') !== false) {
        return array('_dbsync.php');
    }
    return array();
}

function db_sync_archive_exclude_has($lines, $pattern)
{
    $pattern = trim(str_replace('\\', '/', (string) $pattern), '/');
    foreach ((array) $lines as $line) {
        if (rtrim(trim(str_replace('\\', '/', (string) $line)), '/') === $pattern) {
            return true;
        }
    }
    return false;
}

function db_sync_archive_exclude_clean($lines)
{
    $out = array();
    if (is_string($lines)) {
        $lines = preg_split('/\r\n|\r|\n/', $lines);
    }
    if (!is_array($lines)) {
        return $out;
    }
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line !== '' && substr($line, 0, 1) !== '#') {
            $out[] = $line;
        }
    }
    return array_values(array_unique($out));
}

function db_sync_archive_exclude_load($site)
{
    $path = db_sync_archive_exclude_path($site);
    if (!is_file($path)) {
        return db_sync_archive_default_exclude();
    }
    $text = @file_get_contents($path);
    if ($text === false) {
        return db_sync_archive_default_exclude();
    }
    $lines = db_sync_archive_exclude_clean($text);
    // Migracja plikow wykluczen z wczesniejszych wersji: dopisujemy wzorce
    // systemowe, ktorych wtedy nie bylo na liscie (raz, o czym informuje
    // znacznik). Potem decyduje juz tylko zawartosc pliku, wiec usuniecie
    // wzorca przez uzytkownika (np. _dbsync/ albo _dbsync.php, aby dolaczyc
    // dumpy albo sam skrypt do archiwum) jest respektowane.
    if (strpos($text, db_sync_archive_exclude_marker()) === false) {
        $added = array();
        foreach (db_sync_archive_exclude_migration($text) as $extra) {
            if (!db_sync_archive_exclude_has($lines, $extra)) {
                $lines[] = $extra;
                $added[] = $extra;
            }
        }
        try {
            db_sync_archive_exclude_save($site, $lines);
            if (!empty($added)) {
                db_sync_log('MIGRACJA WYKLUCZEN', 'dopisano wzorce: ' . implode(', ', $added));
            }
        } catch (Exception $e) {
            db_sync_log('OSTRZEZENIE', 'nie moge zapisac migracji wykluczen: ' . $e->getMessage(), true);
        }
    }
    return $lines;
}

function db_sync_archive_exclude_save($site, $lines)
{
    if (!is_dir(DB_SYNC_ARCHIVE_DIR)) {
        db_sync_archive_dir(true);
    }
    $clean = db_sync_archive_exclude_clean($lines);
    $body  = db_sync_archive_exclude_marker() . "\n";
    foreach ($clean as $line) {
        $body .= $line . "\n";
    }
    $path = db_sync_archive_exclude_path($site);
    if (@file_put_contents($path, $body) === false) {
        throw new Exception('Nie moge zapisac pliku wykluczen: ' . $path);
    }
    db_sync_log('WYKLUCZENIA ZAPISANE', basename($path) . ' (' . count($clean) . ' wzorcow)');
}

function db_sync_archive_excluded($rel, $patterns, $isDir = false)
{
    $rel  = str_replace('\\', '/', $rel);
    $base = basename($rel);
    foreach ($patterns as $pattern) {
        $pattern = str_replace('\\', '/', trim((string) $pattern));
        if ($pattern === '') {
            continue;
        }
        if (substr($pattern, -1) === '/') {
            // wzorzec katalogu, np. cache/ albo wp-content/cache/
            $dir = rtrim($pattern, '/');
            if ($rel === $dir || strpos($rel, $dir . '/') === 0 || strpos($rel, '/' . $dir . '/') !== false) {
                return true;
            }
            continue;
        }
        if (strpos($pattern, '*') !== false || strpos($pattern, '?') !== false) {
            if (fnmatch($pattern, $base)) {
                return true;
            }
            if (strpos($pattern, '/') !== false && fnmatch($pattern, $rel)) {
                return true;
            }
            continue;
        }
        if ($base === $pattern) {
            return true;
        }
        if ($isDir && strpos('/' . $rel . '/', '/' . $pattern . '/') !== false) {
            return true;
        }
    }
    return false;
}

function db_sync_archive_hard_excluded($rel, $hardDirs)
{
    $rel = trim(str_replace('\\', '/', $rel), '/');
    foreach ($hardDirs as $dir) {
        $dir = trim(str_replace('\\', '/', $dir), '/');
        if ($dir !== '' && ($rel === $dir || strpos($rel, $dir . '/') === 0)) {
            return true;
        }
    }
    return false;
}

function db_sync_archive_collect($root, $patterns, $hardDirs, $listFile = '', $maxSeconds = 0)
{
    $stats = array(
        'files'     => 0,
        'bytes'     => 0,
        'skipped'   => 0,
        'non_ascii' => false,
        'partial'   => false,
        'errors'    => array(),
    );
    $fh = false;
    if ($listFile !== '') {
        $fh = @fopen($listFile, 'wb');
        if ($fh === false) {
            throw new Exception('Nie moge zapisac listy plikow: ' . $listFile);
        }
    }
    $deadline = ($maxSeconds > 0) ? (microtime(true) + $maxSeconds) : 0;
    $stack    = array('');
    while (!empty($stack)) {
        $relDir  = array_pop($stack);
        $absDir  = ($relDir === '') ? $root : $root . '/' . $relDir;
        $entries = @scandir($absDir);
        if ($entries === false) {
            $stats['errors'][] = 'nie moge odczytac katalogu: ' . (($relDir === '') ? '.' : $relDir);
            continue;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $rel = ($relDir === '') ? $entry : $relDir . '/' . $entry;
            if (db_sync_archive_hard_excluded($rel, $hardDirs)) {
                continue;
            }
            $abs = $root . '/' . $rel;
            if (is_dir($abs)) {
                if (is_link($abs) || db_sync_archive_excluded($rel, $patterns, true)) {
                    $stats['skipped']++;
                    continue;
                }
                $stack[] = $rel;
                continue;
            }
            if (is_link($abs) || !is_file($abs)) {
                $stats['skipped']++;
                continue;
            }
            if (substr($entry, -5) === '.part'
                || strpos($entry, 'dbsync-list-') === 0
                || strpos($entry, 'dbsync-log-') === 0
                || strpos($entry, 'dbsync-status-') === 0
                || strpos($entry, "\n") !== false
                || strpos($entry, "\r") !== false) {
                $stats['skipped']++;
                continue;
            }
            if (db_sync_archive_excluded($rel, $patterns, false)) {
                $stats['skipped']++;
                continue;
            }
            $size = @filesize($abs);
            $stats['files']++;
            $stats['bytes'] += ($size === false) ? 0 : $size;
            if (!$stats['non_ascii'] && preg_match('/[^\x00-\x7F]/', $rel)) {
                $stats['non_ascii'] = true;
            }
            if ($fh !== false) {
                fwrite($fh, $rel . "\n");
            }
            if ($deadline > 0 && microtime(true) > $deadline) {
                $stats['partial'] = true;
                break 2;
            }
        }
    }
    if ($fh !== false) {
        fclose($fh);
    }
    return $stats;
}

function db_sync_archive_command($tool, $format, $bin, $listFile, $targetPart)
{
    $arc  = db_sync_bin_arg($bin);
    $part = db_sync_bin_arg($targetPart);
    $list = db_sync_bin_arg($listFile);
    if ($tool === '7z') {
        $type = ($format === '7z') ? '7z' : 'zip';
        return $arc . ' a -t' . $type . ' -mx=5 -bb0 -y ' . $part . ' @' . $list;
    }
    if ($tool === 'zip') {
        // Info-ZIP: nazwy plikow czytane ze stdin (-@)
        return $arc . ' -q -9 ' . $part . ' -@ < ' . $list;
    }
    if ($tool === 'tar') {
        return $arc . ' -czf ' . $part . ' -T ' . $list;
    }
    throw new Exception('Nieznane narzedzie archiwizujace: ' . $tool);
}

function db_sync_archive_log_tail($path, $maxBytes = 8192)
{
    if (!is_file($path)) {
        return '';
    }
    $size = @filesize($path);
    $fh   = @fopen($path, 'rb');
    if ($fh === false) {
        return '';
    }
    if ($size !== false && $size > $maxBytes) {
        fseek($fh, $size - $maxBytes);
    }
    $data = stream_get_contents($fh);
    fclose($fh);
    if ($size !== false && $size > $maxBytes) {
        $data = '[...] ' . $data;
    }
    return rtrim((string) $data);
}

function db_sync_archive_output_errors($text)
{
    $found = array();
    if ($text === '') {
        return $found;
    }
    $needles = array(
        "Couldn't visit",
        'cannot stat',
        'no such file or directory',
        'access is denied',
        'permission denied',
        'cannot open',
        "can't create",
        'cannot create',
        'name not matched',
        'warning:',
    );
    $lines = preg_split('/\r\n|\r|\n/', $text);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        foreach ($needles as $needle) {
            if (stripos($line, $needle) !== false) {
                $found[] = $line;
                break;
            }
        }
    }
    return array_slice(array_values(array_unique($found)), 0, 5);
}

function db_sync_archive_create($format, $patterns, $creds)
{
    $status = db_sync_archive_format_status();
    if (!isset($status[$format])) {
        throw new Exception('Nieznany format archiwum: ' . $format);
    }
    $st  = $status[$format];
    $fmt = $st['fmt'];
    if (!$st['ok']) {
        throw new Exception('Brak narzedzia do formatu ' . $fmt['label'] . ' (wymagane: ' . implode(' / ', $fmt['tools']) . ').'
            . ' Zainstaluj narzedzie albo podaj sciezke recznie, np. ?' . db_sync_archive_tool_arg($fmt['tools'][0]) . '=/pelna/sciezka.');
    }
    $tool = $st['tool'];
    $bin  = $st['bin'];

    $archiveDir = db_sync_archive_dir(true);
    db_sync_htaccess_protect(DB_SYNC_DIR);
    db_sync_archive_cleanup_parts();

    $root     = db_sync_archive_root();
    $hardDirs = db_sync_archive_hard_dirs();
    $domain   = db_sync_archive_domain_key();
    if ($domain === '') {
        // brak zadania HTTP (np. uruchomienie z CLI) - awaryjnie nazwa bazy
        $domain = db_sync_archive_site_key($creds);
    }
    db_sync_log('AKCJA', 'archiwum plikow (' . $fmt['label'] . ') serwisu: ' . $domain);

    // Nazwy plikow tymczasowych sa pochodna nazwy docelowej (a nie losowe),
    // dzieki temu osierocony plik .part da sie powiazac z logiem narzedzia
    // i dokonczyc po przerwanym zadaniu (db_sync_archive_recover_parts).
    $stamp      = date('ymd-His') . '-' . $domain . $fmt['ext'];
    $target     = $archiveDir . '/' . $stamp;
    $part       = $target . '.part';
    $listFile   = $archiveDir . '/dbsync-list-' . $stamp . '.txt';
    $logFile    = $archiveDir . '/dbsync-log-' . $stamp . '.txt';
    $statusFile = $archiveDir . '/dbsync-status-' . $stamp . '.txt';
    if (!is_writable($archiveDir)) {
        throw new Exception('Katalog ' . $archiveDir . ' nie jest zapisywalny.');
    }
    db_sync_archive_unlink(array($listFile, $logFile, $statusFile, $part));

    $stats = db_sync_archive_collect($root, $patterns, $hardDirs, $listFile);
    foreach ($stats['errors'] as $err) {
        db_sync_log('OSTRZEZENIE SKANU', $err, true);
    }
    if ($stats['files'] === 0) {
        db_sync_archive_unlink(array($listFile, $logFile, $statusFile));
        throw new Exception('Brak plikow do archiwizacji (katalog pusty albo wszystko pominiete wykluczeniami).');
    }
    db_sync_log('PLIKI', $stats['files'] . ' plikow, ' . db_sync_human_size($stats['bytes']) . ' danych zrodlowych'
        . ($stats['skipped'] > 0 ? ', pominieto ' . $stats['skipped'] . ' pozycji (wykluczenia/symlinki)' : ''));
    if (!db_sync_archive_tool_utf8_ok($tool) && $stats['non_ascii']) {
        db_sync_archive_unlink(array($listFile, $logFile, $statusFile));
        throw new Exception('Nazwy plikow zawieraja znaki nie-ASCII, a ' . basename($bin)
            . ' na Windows moze je pomijac bez ostrzezenia (cichy czesciowy backup).'
            . "\nUzyj formatu ZIP lub 7Z (obslugiwanych przez 7-Zip).");
    }

    $free = @disk_free_space($archiveDir);
    if ($free !== false && $free < ($stats['bytes'] + 52428800)) {
        db_sync_archive_unlink(array($listFile, $logFile, $statusFile));
        throw new Exception('Za malo miejsca na dysku: wolne ' . db_sync_human_size($free)
            . ', dane zrodlowe ' . db_sync_human_size($stats['bytes']) . ' (wymagany zapas 50 MB).');
    }

    $cmd = db_sync_archive_command($tool, $format, $bin, $listFile, $part);
    db_sync_log('NARZEDZIE', basename($bin) . ' (' . $bin . ')');
    db_sync_log('KOMENDA', $cmd . '   [cwd: ' . $root . ']');

    // cwd procesu potomnego = katalog serwisu (sciezki na liscie sa wzgledne),
    // wyjscie narzedzia -> plik logu (nie do pamieci PHP);
    // dopisek statusu zapisuje kod wyjscia narzedzia takze po ubiciu PHP
    $oldCwd = function_exists('getcwd') ? getcwd() : false;
    @chdir($root);
    db_sync_exec($cmd . ' > ' . db_sync_bin_arg($logFile) . ' 2>&1' . db_sync_archive_status_suffix($statusFile), $outIgnored, $execCode);
    if ($oldCwd !== false) {
        @chdir($oldCwd);
    }

    // kod wyjscia czytamy z pliku statusu - dopisane polecenia powloki
    // zmieniaja wartosc zwracana przez exec()
    $statusCode = db_sync_archive_status_code($statusFile);
    $code       = ($statusCode === null) ? (int) $execCode : $statusCode;

    $tail  = db_sync_archive_log_tail($logFile);
    $found = db_sync_archive_output_errors($tail);
    $fail  = '';

    if ($tool === '7z' && preg_match('/Files read from disk:\s*(\d+)/', $tail, $m)) {
        if ((int) $m[1] !== $stats['files']) {
            $fail = 'Narzedzie przetworzylo ' . (int) $m[1] . ' plikow, a na liscie bylo ' . $stats['files'] . ' - archiwum jest niepelne.';
        }
    }
    if ($fail === '') {
        if ($code >= 2) {
            $fail = 'Narzedzie zakonczylo sie bledem (kod ' . $code . ').';
        } elseif ($code === 1 && !empty($found)) {
            $fail = 'Narzedzie zglosilo problemy z plikami (kod 1).';
        }
    }
    if ($fail !== '') {
        if (!empty($found)) {
            $fail .= "\n" . implode("\n", $found);
        }
        db_sync_archive_unlink(array($listFile, $logFile, $statusFile, $part));
        throw new Exception($fail);
    }
    if ($code === 1) {
        db_sync_log('OSTRZEZENIE', 'narzedzie zwrocilo kod 1 - sprawdz zawartosc archiwum', true);
    }
    foreach ($found as $line) {
        db_sync_log('OSTRZEZENIE NARZEDZIA', $line, true);
    }

    $partSize = is_file($part) ? filesize($part) : 0;
    if ($partSize === 0) {
        db_sync_archive_unlink(array($listFile, $logFile, $statusFile, $part));
        throw new Exception('Archiwum nie zostalo utworzone (brak pliku ' . basename($part) . ' lub plik pusty).'
            . ($tail !== '' ? "\n" . $tail : ''));
    }
    if (!@rename($part, $target)) {
        db_sync_archive_unlink(array($listFile, $logFile, $statusFile, $part));
        throw new Exception('Nie moge zmienic nazwy ' . basename($part) . ' na ' . basename($target) . '.');
    }

    $percent = ($stats['bytes'] > 0) ? ' (' . round($partSize / $stats['bytes'] * 100) . '% danych zrodlowych)' : '';
    db_sync_log('ARCHIWUM GOTOWE', db_sync_human_size($partSize) . $percent . ' -> ' . $target);
    if ($tail !== '') {
        db_sync_log_raw($tail);
    }
    db_sync_archive_unlink(array($listFile, $logFile, $statusFile));
    return $target;
}

function db_sync_archive_ext_mime_map()
{
    return array(
        '.zip'     => 'application/zip',
        '.7z'      => 'application/x-7z-compressed',
        '.tar.gz'  => 'application/gzip',
        '.tar.bz2' => 'application/x-bzip2',
        '.rar'     => 'application/vnd.rar',
    );
}

function db_sync_archive_path($file)
{
    $file = basename(str_replace('\\', '/', (string) $file));
    if ($file === '' || $file === '.' || $file === '..') {
        return false;
    }
    $ok = false;
    foreach (array_keys(db_sync_archive_ext_mime_map()) as $ext) {
        if (strlen($file) > strlen($ext) && substr($file, -strlen($ext)) === $ext) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        return false;
    }
    $path = DB_SYNC_ARCHIVE_DIR . '/' . $file;
    return is_file($path) ? $path : false;
}

function db_sync_archive_list()
{
    $files = array();
    if (is_dir(DB_SYNC_ARCHIVE_DIR)) {
        foreach (glob(DB_SYNC_ARCHIVE_DIR . '/*') ?: array() as $path) {
            $name = basename($path);
            if (is_file($path) && db_sync_archive_path($name) !== false) {
                $files[] = $name;
            }
        }
    }
    rsort($files);
    return $files;
}

function db_sync_archive_mime($file)
{
    $name = strtolower(basename((string) $file));
    foreach (db_sync_archive_ext_mime_map() as $ext => $mime) {
        if (substr($name, -strlen($ext)) === $ext) {
            return $mime;
        }
    }
    return 'application/octet-stream';
}

function db_sync_download_archive($file)
{
    $path = db_sync_archive_path($file);
    if ($path === false) {
        throw new Exception('Nieprawidlowy lub nieistniejacy plik archiwum: ' . $file);
    }
    db_sync_send_file($path, db_sync_archive_mime($file));
}

function db_sync_delete_archives($files)
{
    if (empty($files) || !is_array($files)) {
        throw new Exception('Nie zaznaczono zadnych archiwow do usuniecia.');
    }
    $deleted = 0;
    foreach ($files as $file) {
        $path = db_sync_archive_path($file);
        if ($path === false) {
            db_sync_log('POMINIETO', 'nieprawidlowy plik archiwum: ' . $file, true);
            continue;
        }
        if (@unlink($path)) {
            $deleted++;
            db_sync_log('USUNIETO', basename($path));
        } else {
            db_sync_log('BLAD USUWANIA', 'nie moge usunac: ' . basename($path), true);
        }
    }
    db_sync_log('USUWANIE GOTOWE', $deleted . ' archiw(ow) usunieto');
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
$showArchiveForm = false;

// pobranie dumpu - czysty plik, bez interfejsu (przed jakimkolwiek HTML)
$pendingError = '';
if (isset($_GET['action']) && strtolower(trim($_GET['action'])) === 'download') {
    try {
        db_sync_download(isset($_GET['file']) ? $_GET['file'] : '');
    } catch (Exception $e) {
        $pendingError = $e->getMessage();
    }
}

// pobranie archiwum - czysty plik, bez interfejsu (przed jakimkolwiek HTML)
if (isset($_GET['action']) && strtolower(trim($_GET['action'])) === 'downloadarchive') {
    try {
        db_sync_download_archive(isset($_GET['file']) ? $_GET['file'] : '');
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
    // Akcje archiwum plikow tez nie wymagaja bazy.
    $needsDbConfig = ($action !== 'checkupdate' && $action !== 'update'
        && $action !== 'archive' && $action !== 'downloadarchive' && $action !== 'deletearchive');

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
    } elseif ($action === 'archive') {
        // archiwizacja nie wymaga bazy - konfiguracje czytamy tylko po to,
        // zeby nazwac plik archiwum (DB_NAME zamiast domeny); brak configu
        // nie jest bledem.
        try {
            $dbConfig = db_sync_creds_from_disk();
            $creds = $dbConfig['creds'];
        } catch (Exception $e) {
            // zostaja puste dane - nazwa z HTTP_HOST (db_sync_archive_site_key)
        }
    }

    if (($action === 'dump' || $action === 'sync') && ($creds['DB_NAME'] === '' || $creds['DB_USER'] === '')) {
        throw new Exception('Nie udalo sie odczytac danych bazy z konfiguracji (DB_NAME/DB_USER) - nie moge wykonac akcji.');
    }

    if ($action === 'archive') {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $format   = isset($_POST['format']) ? trim($_POST['format']) : '';
            $patterns = db_sync_archive_exclude_clean(isset($_POST['exclude']) ? $_POST['exclude'] : '');
            db_sync_archive_exclude_save(db_sync_archive_site_key($creds), $patterns);
            db_sync_archive_create($format, $patterns, $creds);
        } else {
            db_sync_archive_cleanup_parts();
            $showArchiveForm = true;
        }
    } elseif ($action === 'deletearchive') {
        db_sync_delete_archives(isset($_POST['archives']) && is_array($_POST['archives']) ? $_POST['archives'] : array());
    } elseif ($action === 'dump') {
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
} elseif ($showArchiveForm) {
    db_sync_render_archive_form($creds);
} else {
    db_sync_render_list();
}
