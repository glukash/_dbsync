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
 *   _dbsync.php?action=archive (POST exclude_defaults=1) -> przywrocenie
 *                                 domyslnej listy wykluczen (bez archiwum)
 *   _dbsync.php?action=downloadarchive&file=NAZWA -> pobranie archiwum
 *   _dbsync.php?action=deletearchive (POST archives[]) -> usuniecie archiwow
 *
 * Nazwa archiwum: YYMMDD-HHMMSS-{domena}.{ext}, np. 260923-103115-example.com.zip
 * (domena z zadania HTTP; przy braku - awaryjnie nazwa bazy).
 *   _dbsync.php?action=checkupdate    -> sprawdzenie nowszej wersji na GitHubie
 *                                        (branch main, archiwum tar.gz z codeload)
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
 * Gdy funkcje uruchamiania procesow (exec/proc_open/system/passthru/
 * shell_exec/popen) sa wylaczone albo nie ma binarek, skrypt korzysta
 * z wbudowanych mechanizmow PHP:
 *   - dump i import bazy: mysqli (albo PDO MySQL),
 *   - archiwum plikow: ZIP przez ZipArchive albo wlasny writer ZIP.
 * Tryb PHP jest automatyczny i nie zmienia zachowania, gdy CLI dziala.
 *
 * Archiwa plikow tworzone sa narzedziami systemowymi: ZIP przez Info-ZIP
 * 'zip' (na Linux - natywny narzedziem serwera; zapasowo 7-Zip) albo przez
 * 7-Zip (na Windows, gdzie poprawnie obsluguje polskie znaki), 7Z przez
 * 7-Zip, TAR.GZ przez tar. Gdy CLI jest niedostepne, ZIP tworzy wbudowany
 * mechanizm PHP (ZipArchive albo wlasny writer ZIP). Narzedzia sa wykrywane
 * automatycznie (PATH + znane katalogi), a archiwa zapisywane w ./_dbsyncf/ -
 * katalog ten mozna wskazac poza docroot zmieniajac stala
 * DB_SYNC_ARCHIVE_DIR.
 *
 * Wykluczenia archiwum sa edytowalne w formularzu (plik
 * _dbsyncf/archive-exclude-{site}.txt). Domyslnie wykluczone sa m.in.
 * _dbsync/ (dumpy bazy) i _dbsync.php (sam skrypt narzedzia) - usuniecie
 * wzorca z listy oznacza dolaczenie do archiwum. Katalog archiwow
 * (DB_SYNC_ARCHIVE_DIR, domyslnie _dbsyncf/) jest pomijany bezwarunkowo
 * i nie ma go na liscie wzorcow, bo nie da sie go dolaczyc do archiwum.
 * Przycisk "Ustaw domyslne" obok listy przywraca pelny zestaw wzorcow
 * domyslnych (nadpisuje plik wykluczen, archiwum nie powstaje).
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

/* Hostingi z open_basedir/site sandbox zglaszaja ostrzezenia o katalogach
   systemowych (/usr/bin itd.) i wylaczonym exec() - sa one oczekiwane przy
   wykrywaniu narzedzi CLI. Tlumimy tylko te dwa komunikaty; bledy oraz
   pozostale ostrzezenia nadal sa wyswietlane. */
set_error_handler(function ($errno, $errstr) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    if (($errno === E_WARNING || $errno === E_NOTICE)
        && (stripos($errstr, 'open_basedir restriction') !== false
            || stripos($errstr, 'has been disabled for security reasons') !== false)) {
        return true;
    }
    return false;
});

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
/* z ktorego sprawdzane sa i pobierane aktualizacje (branch main). */
define('DBSYNC_DATE', '2026-11-09');
define('DBSYNC_VERSION', '1.11.1');
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

/* Czy funkcja jest dostepna i nie zostala zablokowana przez disable_functions?
   Na hostingach (np. Hostido) exec() istnieje, ale jego wywolanie konczy sie
   warningiem sandboxa - dzieki temu sprawdzeniu w ogole go nie dotykamy. */
function db_sync_function_enabled($name)
{
    if (!function_exists($name)) {
        return false;
    }
    $disabled = (string) ini_get('disable_functions');
    if ($disabled === '') {
        return true;
    }
    foreach (explode(',', $disabled) as $item) {
        if (strcasecmp(trim($item), $name) === 0) {
            return false;
        }
    }
    return true;
}

/* Czy sciezka lezy w open_basedir? Pozwala nie wywolywac is_file()/is_dir()
   dla katalogow systemowych (/usr/bin itd.), ktore na hostingu i tak sa
   poza dozwolonymi sciezkami (bez tego PHP sypie warningami). */
function db_sync_path_allowed($path)
{
    $open = (string) ini_get('open_basedir');
    if (trim($open) === '') {
        return true;
    }
    $path = str_replace('\\', '/', (string) $path);
    $real = @realpath($path);
    if ($real !== false) {
        $path = str_replace('\\', '/', $real);
    }
    $separator = (DIRECTORY_SEPARATOR === '\\') ? ';' : ':';
    foreach (explode($separator, $open) as $dir) {
        $dir = rtrim(str_replace('\\', '/', trim($dir)), '/');
        if ($dir === '') {
            continue;
        }
        $realDir = @realpath($dir);
        if ($realDir !== false) {
            $dir = rtrim(str_replace('\\', '/', $realDir), '/');
        }
        if ($path === $dir || strpos($path, $dir . '/') === 0) {
            return true;
        }
    }
    return false;
}

/* Zwraca nazwe pierwszej dostepnej funkcji uruchamiania procesow albo ''.
   Kolejnosc: exec (zachowanie historyczne), potem bezpieczne zamienniki.
   Dzieki temu serwer z disable_functions=exec, ale z proc_open/system,
   nadal obsluguje CLI bez zmiany sciezek native. */
function db_sync_process_runner()
{
    static $runner = null;
    if ($runner !== null) {
        return $runner;
    }
    $names = array('exec', 'proc_open', 'system', 'passthru', 'shell_exec', 'popen');
    foreach ($names as $name) {
        if ($name === 'proc_open'
            && (!function_exists('proc_get_status') || !function_exists('proc_close')
                || !function_exists('fclose') || !function_exists('stream_get_contents'))) {
            continue;
        }
        if ($name === 'popen' && !function_exists('pclose')) {
            continue;
        }
        if (db_sync_function_enabled($name)) {
            $runner = $name;
            return $runner;
        }
    }
    $runner = '';
    return $runner;
}

function db_sync_process_available()
{
    return db_sync_process_runner() !== '';
}

/* Zamienia tekst wyjsciowy na format tablicy zwracany przez exec():
   linie bez koncowych znakow nowej linii, bez pustej linii na koncu. */
function db_sync_exec_output_lines($text)
{
    $text = (string) $text;
    if ($text === '') {
        return array();
    }
    $lines = preg_split('/\r\n|\r|\n/', $text);
    if (!empty($lines) && end($lines) === '') {
        array_pop($lines);
    }
    foreach ($lines as $i => $line) {
        $lines[$i] = rtrim($line);
    }
    return $lines;
}

/* proc_open: czyta stdout i stderr bez blokowania (dziala tez na Windows,
   gdzie stream_select bywa zawodny dla potokow). Wszystkie komendy skryptu
   i tak przekierowuja stderr do stdout lub do pliku logu. */
function db_sync_exec_proc_open($cmd, &$output, &$code)
{
    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        throw new Exception('Nie moge uruchomic procesu (proc_open).');
    }
    fclose($pipes[0]);
    @stream_set_blocking($pipes[1], false);
    @stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $exitCode = null;
    $deadline = microtime(true) + 86400;
    while (true) {
        $chunk = @stream_get_contents($pipes[1]);
        if ($chunk !== false && $chunk !== '') {
            $stdout .= $chunk;
        }
        $chunk = @stream_get_contents($pipes[2]);
        if ($chunk !== false && $chunk !== '') {
            $stderr .= $chunk;
        }
        $status = @proc_get_status($proc);
        if (!is_array($status)) {
            break;
        }
        if (!$status['running']) {
            $exitCode = isset($status['exitcode']) ? (int) $status['exitcode'] : -1;
            break;
        }
        if (microtime(true) > $deadline) {
            if (function_exists('proc_terminate')) {
                @proc_terminate($proc);
            }
            break;
        }
        if (function_exists('usleep')) {
            usleep(10000);
        }
    }
    $chunk = @stream_get_contents($pipes[1]);
    if ($chunk !== false && $chunk !== '') {
        $stdout .= $chunk;
    }
    $chunk = @stream_get_contents($pipes[2]);
    if ($chunk !== false && $chunk !== '') {
        $stderr .= $chunk;
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = @proc_close($proc);
    if ($exitCode === null || $exitCode === -1) {
        $exitCode = $closeCode;
    }
    $code   = (int) $exitCode;
    $output = db_sync_exec_output_lines($stdout . $stderr);
}

/* system()/passthru() z buforem wyjscia - zwracaja kod wyjscia. */
function db_sync_exec_system($cmd, &$output, &$code)
{
    $code = -1;
    ob_start();
    if (function_exists('system')) {
        @system($cmd, $code);
    } elseif (function_exists('passthru')) {
        @passthru($cmd, $code);
    }
    $text = ob_get_clean();
    $output = db_sync_exec_output_lines($text);
}

/* shell_exec nie zwraca kodu wyjscia - kod zapisuje powloka do pliku
   statusu (ten sam mechanizm co przy archiwach, odporny na ubicie PHP). */
function db_sync_exec_shell($cmd, &$output, &$code)
{
    $statusFile = false;
    if (function_exists('tempnam')) {
        $statusFile = @tempnam(function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '.', 'dbsync');
    }
    if ($statusFile === false) {
        if (function_exists('popen')) {
            db_sync_exec_popen($cmd, $output, $code);
            return;
        }
        $text = @shell_exec($cmd);
        $code = -1;
        $output = db_sync_exec_output_lines($text);
        return;
    }
    @unlink($statusFile);
    $text = @shell_exec($cmd . db_sync_archive_status_suffix($statusFile));
    $status = db_sync_archive_status_code($statusFile);
    @unlink($statusFile);
    $code   = ($status === null) ? -1 : (int) $status;
    $output = db_sync_exec_output_lines($text);
}

function db_sync_exec_popen($cmd, &$output, &$code)
{
    $h = @popen($cmd, 'r');
    if (!is_resource($h)) {
        throw new Exception('Nie moge uruchomic procesu (popen).');
    }
    $text = stream_get_contents($h);
    $status = (int) @pclose($h);
    if (PHP_OS_FAMILY !== 'Windows' && $status > 255) {
        // POSIX: pclose() zwraca status wait(), a nie sam kod wyjscia
        $status = ($status >> 8) & 0xFF;
    }
    $code = $status;
    $output = db_sync_exec_output_lines($text);
}

/* Uruchamia komende i wypelnia $output (linie) oraz $code (kod wyjscia).
   Gdy exec() jest wylaczony, probuje kolejno proc_open/system/passthru/
   shell_exec/popen. Gdy zaden nie jest dostepny, rzuca wyjatek - ale akcje
   bazy/archiwum w takiej sytuacji w ogole nie wywoluja CLI (patrz nizej). */
function db_sync_exec($cmd, &$output, &$code)
{
    $output = array();
    $code   = -1;
    $runner = db_sync_process_runner();
    if ($runner === 'exec') {
        // @ - sandbox hostingu potrafi zwrocic warning przy kazdym wywolaniu
        @exec($cmd, $output, $code);
        return;
    }
    if ($runner === 'proc_open') {
        db_sync_exec_proc_open($cmd, $output, $code);
        return;
    }
    if ($runner === 'system' || $runner === 'passthru') {
        db_sync_exec_system($cmd, $output, $code);
        return;
    }
    if ($runner === 'shell_exec') {
        db_sync_exec_shell($cmd, $output, $code);
        return;
    }
    if ($runner === 'popen') {
        db_sync_exec_popen($cmd, $output, $code);
        return;
    }
    throw new Exception('Funkcja exec() jest wylaczona na serwerze i brak dzialajacego zamiennika (proc_open/system/passthru/shell_exec/popen) - nie moge uruchomic narzedzi CLI (mysql/mysqldump). Uzywam trybu PHP (wbudowanego) tam, gdzie jest dostepny.');
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

/* ------------------------------------------------------------------ */
/* Warstwa PHP do bazy (fallback bez mysql/mysqldump)                  */
/* ------------------------------------------------------------------ */

function db_sync_native_open($creds, $host, $port)
{
    $err = '';
    $mysqliOk = function_exists('mysqli_connect')
        && function_exists('mysqli_query')
        && function_exists('mysqli_fetch_row')
        && function_exists('mysqli_fetch_assoc')
        && function_exists('mysqli_real_escape_string')
        && function_exists('mysqli_error');
    if ($mysqliOk) {
        if (function_exists('mysqli_report') && defined('MYSQLI_REPORT_OFF')) {
            @mysqli_report(MYSQLI_REPORT_OFF);
        }
        $link = false;
        try {
            if (strpos($host, '/') === 0) {
                $link = @mysqli_connect('localhost', $creds['DB_USER'], $creds['DB_PASSWORD'], $creds['DB_NAME'], 0, $host);
            } else {
                $link = @mysqli_connect($host, $creds['DB_USER'], $creds['DB_PASSWORD'], $creds['DB_NAME'], ($port === '' ? 0 : (int) $port));
            }
        } catch (Exception $e) {
            $err = $e->getMessage();
            $link = false;
        }
        if ($link) {
            if (function_exists('mysqli_set_charset')) {
                if (!@mysqli_set_charset($link, 'utf8')) {
                    @mysqli_set_charset($link, 'utf8mb4');
                }
            }
            return array('type' => 'mysqli', 'link' => $link);
        }
        $err = function_exists('mysqli_connect_error') ? mysqli_connect_error() : 'blad polaczenia mysqli';
    }
    if (class_exists('PDO')) {
        $dsn = '';
        if (strpos($host, '/') === 0) {
            $dsn = 'mysql:unix_socket=' . $host . ';dbname=' . $creds['DB_NAME'] . ';charset=utf8';
        } else {
            $dsn = 'mysql:host=' . $host . ';dbname=' . $creds['DB_NAME'] . ';charset=utf8';
            if ($port !== '') {
                $dsn .= ';port=' . $port;
            }
        }
        try {
            $pdo = new PDO($dsn, $creds['DB_USER'], $creds['DB_PASSWORD'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => true,
            ));
            return array('type' => 'pdo', 'link' => $pdo);
        } catch (Exception $e) {
            $err .= ($err !== '' ? '; ' : '') . 'PDO: ' . $e->getMessage();
        }
    }
    throw new Exception('Brak dzialajacego rozszerzenia PHP do MySQL (mysqli/PDO MySQL) - nie moge wykonac operacji bez CLI.'
        . ($err !== '' ? ' Szczegoly: ' . $err : ''));
}

function db_sync_native_close($conn)
{
    if (!is_array($conn) || !isset($conn['type'])) {
        return;
    }
    if ($conn['type'] === 'mysqli') {
        if (function_exists('mysqli_close')) {
            @mysqli_close($conn['link']);
        }
    }
}

function db_sync_native_query($conn, $sql)
{
    if ($conn['type'] === 'mysqli') {
        $res = @mysqli_query($conn['link'], $sql);
        if ($res === false) {
            throw new Exception('MySQL: ' . mysqli_error($conn['link']));
        }
        return $res;
    }
    try {
        $res = $conn['link']->query($sql);
        if ($res === false) {
            $info = $conn['link']->errorInfo();
            throw new Exception('MySQL: ' . (isset($info[2]) ? $info[2] : 'blad zapytania'));
        }
        return $res;
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }
}

function db_sync_native_try($conn, $sql)
{
    try {
        $res = db_sync_native_query($conn, $sql);
        db_sync_native_free($conn, $res);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function db_sync_native_free($conn, $res)
{
    if (!is_array($conn) || !isset($conn['type'])) {
        return;
    }
    if ($conn['type'] === 'mysqli') {
        if (is_object($res) && $res !== true && function_exists('mysqli_free_result')) {
            @mysqli_free_result($res);
        }
        return;
    }
    if (is_object($res) && method_exists($res, 'closeCursor')) {
        try {
            @$res->closeCursor();
        } catch (Exception $e) {
            // ignorujemy blad zamykania kursora
        }
    }
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        try {
            @$conn['link']->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        } catch (Exception $e) {
            // sterownik nie obsluguje tej opcji - ignorujemy
        }
    }
}

function db_sync_native_fetch_row($conn, $res)
{
    if ($conn['type'] === 'mysqli') {
        $row = @mysqli_fetch_row($res);
        return ($row === false) ? null : $row;
    }
    $row = $res->fetch(PDO::FETCH_NUM);
    return ($row === false) ? null : $row;
}

function db_sync_native_fetch_assoc($conn, $res)
{
    if ($conn['type'] === 'mysqli') {
        $row = @mysqli_fetch_assoc($res);
        return ($row === false) ? null : $row;
    }
    $row = $res->fetch(PDO::FETCH_ASSOC);
    return ($row === false) ? null : $row;
}

function db_sync_native_fetch_all($conn, $sql)
{
    $res  = db_sync_native_query($conn, $sql);
    $rows = array();
    while (($row = db_sync_native_fetch_assoc($conn, $res)) !== null) {
        $rows[] = $row;
    }
    db_sync_native_free($conn, $res);
    return $rows;
}

function db_sync_native_quote($conn, $value)
{
    if ($conn['type'] === 'mysqli') {
        return "'" . mysqli_real_escape_string($conn['link'], (string) $value) . "'";
    }
    $quoted = $conn['link']->quote((string) $value);
    return ($quoted === false) ? "''" : $quoted;
}

function db_sync_native_escape($conn, $value)
{
    if ($conn['type'] === 'mysqli') {
        return mysqli_real_escape_string($conn['link'], (string) $value);
    }
    $quoted = $conn['link']->quote((string) $value);
    return ($quoted === false) ? '' : substr($quoted, 1, -1);
}

function db_sync_native_row_get($row, $key, $default = '')
{
    if (isset($row[$key])) {
        return $row[$key];
    }
    foreach ($row as $k => $v) {
        if (strcasecmp($k, $key) === 0) {
            return $v;
        }
    }
    return $default;
}

function db_sync_native_test_connection($creds, $host, $port)
{
    try {
        $conn = db_sync_native_open($creds, $host, $port);
        $res  = db_sync_native_query($conn, 'SELECT 1');
        db_sync_native_free($conn, $res);
        db_sync_native_close($conn);
        return true;
    } catch (Exception $e) {
        return 'BLAD: ' . $e->getMessage();
    }
}

function db_sync_native_tables_meta($conn, $dbName)
{
    $sql = "SELECT table_name AS name, IFNULL(data_length + index_length, 0) AS size, table_type AS type "
        . "FROM information_schema.tables WHERE table_schema = " . db_sync_native_quote($conn, $dbName)
        . " ORDER BY table_name";
    $rows = db_sync_native_fetch_all($conn, $sql);
    $out  = array();
    foreach ($rows as $row) {
        $name = '';
        if (isset($row['name'])) {
            $name = $row['name'];
        } elseif (isset($row['TABLE_NAME'])) {
            $name = $row['TABLE_NAME'];
        }
        if ($name === '') {
            continue;
        }
        $out[$name] = array(
            'size' => isset($row['size']) ? $row['size'] : 0,
            'type' => isset($row['type']) ? $row['type'] : '',
        );
    }
    return $out;
}

function db_sync_native_list_tables($creds, $host, $port)
{
    $conn = db_sync_native_open($creds, $host, $port);
    $meta = db_sync_native_tables_meta($conn, $creds['DB_NAME']);
    db_sync_native_close($conn);
    $tables = array();
    foreach ($meta as $name => $m) {
        $tables[$name] = (float) $m['size'];
    }
    return $tables;
}

function db_sync_native_identifier($name)
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function db_sync_native_columns($conn, $dbName, $table)
{
    $sql = "SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.columns "
        . "WHERE TABLE_SCHEMA = " . db_sync_native_quote($conn, $dbName)
        . " AND TABLE_NAME = " . db_sync_native_quote($conn, $table)
        . " ORDER BY ORDINAL_POSITION";
    $rows = db_sync_native_fetch_all($conn, $sql);
    $out  = array();
    foreach ($rows as $row) {
        $extra = isset($row['EXTRA']) ? strtoupper($row['EXTRA']) : '';
        // Pomijamy tylko kolumny generowane (VIRTUAL/STORED/PERSISTENT).
        // "DEFAULT_GENERATED" to zwykla kolumna z domyslna wyrazeniem -
        // jej wartosci musza trafic do INSERT.
        if (strpos($extra, 'VIRTUAL GENERATED') !== false
            || strpos($extra, 'STORED GENERATED') !== false
            || strpos($extra, 'PERSISTENT GENERATED') !== false) {
            continue;
        }
        $colName  = isset($row['COLUMN_NAME']) ? $row['COLUMN_NAME'] : '';
        $dataType = isset($row['DATA_TYPE']) ? $row['DATA_TYPE'] : '';
        if ($colName === '' || $dataType === '') {
            continue;
        }
        $out[] = array(
            'name' => $colName,
            'type' => strtolower($dataType),
        );
    }
    return $out;
}

function db_sync_native_value($conn, $value, $type)
{
    if ($value === null) {
        return 'NULL';
    }
    $binary = array(
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint',
        'multilinestring', 'multipolygon', 'geometrycollection',
    );
    if (in_array($type, $binary)) {
        // pusty BLOB/BINARY: literal 0x jest niepoprawny (MySQL czyta go
        // jako nazwe kolumny), dlatego pusty string w cudzyslowie
        return ($value === '') ? "''" : '0x' . bin2hex((string) $value);
    }
    if ($type === 'bit') {
        // MySQL 5.7 zwraca BIT jako lancuch dziesietny; dla pewnosci
        // obslugujemy tez binarna reprezentacje
        if ($value === '') {
            return '0';
        }
        return preg_match('/^\d+$/', (string) $value) ? (string) $value : '0x' . bin2hex((string) $value);
    }
    $numeric = array(
        'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
        'decimal', 'numeric', 'float', 'double', 'real', 'year',
    );
    if (in_array($type, $numeric) && preg_match('/^-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/', (string) $value)) {
        return (string) $value;
    }
    return db_sync_native_quote($conn, $value);
}

function db_sync_write_all($fh, $data)
{
    $len  = strlen($data);
    $done = 0;
    while ($done < $len) {
        $n = @fwrite($fh, substr($data, $done));
        if ($n === false || $n === 0) {
            return false;
        }
        $done += $n;
    }
    return true;
}

function db_sync_native_dump_header($fh, $dbName, $tzUtc)
{
    $head = "-- _dbsync native PHP dump (fallback bez mysqldump)\n"
        . "-- Baza: " . $dbName . "\n"
        . "-- Data: " . date('Y-m-d H:i:s') . "\n"
        . "--\n\n"
        . "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n"
        . "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n"
        . "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n"
        . "/*!40101 SET NAMES utf8 */;\n"
        . "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n"
        . "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n"
        . "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n";
    if ($tzUtc) {
        $head .= "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n"
            . "/*!40103 SET TIME_ZONE='+00:00' */;\n";
    }
    $head .= "\n";
    return db_sync_write_all($fh, $head);
}

function db_sync_native_dump_footer($fh, $tzUtc)
{
    $foot = "\n"
        . "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n"
        . "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
        . "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n"
        . "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n"
        . "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n"
        . "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
    if ($tzUtc) {
        $foot .= "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n";
    }
    return db_sync_write_all($fh, $foot . "\n-- Koniec dumpu\n");
}

function db_sync_native_dump_insert($fh, $qTable, $colNames, $batch)
{
    return db_sync_write_all($fh, 'INSERT INTO ' . $qTable . ' (' . implode(', ', $colNames) . ') VALUES '
        . implode(',', $batch) . ";\n");
}

function db_sync_native_query_unbuffered($conn, $sql)
{
    if ($conn['type'] === 'mysqli') {
        $flags = defined('MYSQLI_USE_RESULT') ? MYSQLI_USE_RESULT : 0;
        $res = @mysqli_query($conn['link'], $sql, $flags);
        if ($res === false) {
            throw new Exception('MySQL: ' . mysqli_error($conn['link']));
        }
        return $res;
    }
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        try {
            @$conn['link']->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        } catch (Exception $e) {
            // sterownik nie obsluguje tej opcji - zapytanie bedzie buforowane
        }
    }
    try {
        $res = $conn['link']->query($sql);
        if ($res === false) {
            $info = $conn['link']->errorInfo();
            throw new Exception('MySQL: ' . (isset($info[2]) ? $info[2] : 'blad zapytania'));
        }
        return $res;
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }
}

/* Odpowiednik mysqldump --no-tablespaces: usuwa klauzule TABLESPACE z
   opcji tabeli (tylko po ostatnim nawiasie, zeby nie ruszyc komentarzy
   kolumn wewnatrz definicji). */
function db_sync_native_strip_tablespace($create)
{
    // Klauzula TABLESPACE w SHOW CREATE TABLE wystepuje na koncu opcji
    // tabeli (opcjonalnie przed PARTITION BY); lookahead chroni przed
    // przypadkowym dopasowaniem tekstu w komentarzu kolumny.
    $out = preg_replace(
        '/\s+TABLESPACE\s*=?\s*(?:`[^`]*`|[A-Za-z0-9_$]+)(?=\s*(?:PARTITION\b|$))/i',
        '',
        $create
    );
    return ($out === null) ? $create : $out;
}

function db_sync_native_dump_table($conn, $fh, $dbName, $table, $idx, $total)
{
    $qTable = db_sync_native_identifier($table);
    $rows   = db_sync_native_fetch_all($conn, 'SHOW CREATE TABLE ' . $qTable);
    if (empty($rows)) {
        throw new Exception('Nie moge odczytac struktury tabeli ' . $table);
    }
    $create = '';
    foreach ($rows[0] as $key => $value) {
        if (stripos($key, 'create') !== false) {
            $create = $value;
            break;
        }
    }
    if ($create === '' && isset($rows[0][1])) {
        $create = $rows[0][1];
    }
    if ($create === '') {
        throw new Exception('SHOW CREATE TABLE nie zwrocilo definicji tabeli ' . $table);
    }
    $create = db_sync_native_strip_tablespace($create);

    if (!db_sync_write_all($fh, "\n--\n-- Table structure for table " . $qTable . "\n--\n\n")) {
        throw new Exception('Nie moge zapisac dumpu (struktura ' . $table . ').');
    }
    if (!db_sync_write_all($fh, 'DROP TABLE IF EXISTS ' . $qTable . ";\n" . $create . ";\n")) {
        throw new Exception('Nie moge zapisac dumpu (struktura ' . $table . ').');
    }

    $columns = db_sync_native_columns($conn, $dbName, $table);
    if (empty($columns)) {
        db_sync_log('DUMP', $idx . '/' . $total . ' ' . $table . ' (0 kolumn - pominiety)');
        return;
    }
    $colNames = array();
    foreach ($columns as $column) {
        $colNames[] = db_sync_native_identifier($column['name']);
    }
    $select = 'SELECT ' . implode(', ', $colNames) . ' FROM ' . $qTable;

    if (!db_sync_write_all($fh, "\n--\n-- Dumping data for table " . $qTable . "\n--\n\n"
        . '/*!40000 ALTER TABLE ' . $qTable . " DISABLE KEYS */;\n")) {
        throw new Exception('Nie moge zapisac dumpu (dane ' . $table . ').');
    }

    $res        = db_sync_native_query_unbuffered($conn, $select);
    $batch      = array();
    $batchBytes = 0;
    $rowCount   = 0;
    while (($row = db_sync_native_fetch_row($conn, $res)) !== null) {
        $values = array();
        foreach ($columns as $i => $column) {
            $values[] = db_sync_native_value($conn, isset($row[$i]) ? $row[$i] : null, $column['type']);
        }
        $tuple       = '(' . implode(',', $values) . ')';
        $batch[]     = $tuple;
        $batchBytes += strlen($tuple);
        $rowCount++;
        if (count($batch) >= 500 || $batchBytes >= 1048576) {
            if (!db_sync_native_dump_insert($fh, $qTable, $colNames, $batch)) {
                db_sync_native_free($conn, $res);
                throw new Exception('Nie moge zapisac dumpu (dane ' . $table . ').');
            }
            $batch      = array();
            $batchBytes = 0;
        }
    }
    if (!empty($batch)) {
        if (!db_sync_native_dump_insert($fh, $qTable, $colNames, $batch)) {
            db_sync_native_free($conn, $res);
            throw new Exception('Nie moge zapisac dumpu (dane ' . $table . ').');
        }
    }
    db_sync_native_free($conn, $res);

    if (!db_sync_write_all($fh, '/*!40000 ALTER TABLE ' . $qTable . " ENABLE KEYS */;\n")) {
        throw new Exception('Nie moge zapisac dumpu (dane ' . $table . ').');
    }
    db_sync_log('DUMP', $idx . '/' . $total . ' ' . $table . ' (' . $rowCount . ' rekordow)');
}

/* Placeholder widokow (jak w mysqldump): najpierw wszystkie widoki powstaja
   jako SELECT 1 AS kolumna, dzieki czemu widoki zalezne od siebie moga byc
   tworzone w dowolnej kolejnosci, a potem sa podmieniane na wlasciwe. */
function db_sync_native_dump_view_placeholders($conn, $fh, $dbName, $views)
{
    if (empty($views)) {
        return;
    }
    if (!db_sync_write_all($fh, "\n--\n-- Temporary view structures (dependent views)\n--\n\n")) {
        throw new Exception('Nie moge zapisac zastepczych struktur widokow.');
    }
    foreach ($views as $view) {
        $qView   = db_sync_native_identifier($view);
        $columns = db_sync_native_columns($conn, $dbName, $view);
        if (empty($columns)) {
            $rows = db_sync_native_fetch_all($conn, 'SHOW COLUMNS FROM ' . $qView);
            foreach ($rows as $row) {
                $name = db_sync_native_row_get($row, 'Field', '');
                if ($name !== '') {
                    $columns[] = array('name' => $name, 'type' => '');
                }
            }
        }
        $select = array();
        foreach ($columns as $column) {
            if ($column['name'] !== '') {
                $select[] = '1 AS ' . db_sync_native_identifier($column['name']);
            }
        }
        if (empty($select)) {
            continue;
        }
        $sql = 'DROP TABLE IF EXISTS ' . $qView . ";\n"
            . 'DROP VIEW IF EXISTS ' . $qView . ";\n"
            . 'CREATE VIEW ' . $qView . ' AS SELECT ' . implode(', ', $select) . ";\n";
        if (!db_sync_write_all($fh, $sql)) {
            throw new Exception('Nie moge zapisac zastepczego widoku ' . $view . '.');
        }
    }
}

function db_sync_native_dump_view($conn, $fh, $dbName, $view, $idx, $total)
{
    $qView = db_sync_native_identifier($view);
    $rows  = db_sync_native_fetch_all($conn, 'SHOW CREATE VIEW ' . $qView);
    if (empty($rows)) {
        throw new Exception('Nie moge odczytac definicji widoku ' . $view);
    }
    $create = '';
    foreach ($rows[0] as $key => $value) {
        if (stripos($key, 'create') !== false) {
            $create = $value;
            break;
        }
    }
    if ($create === '' && isset($rows[0][1])) {
        $create = $rows[0][1];
    }
    if ($create === '') {
        throw new Exception('SHOW CREATE VIEW nie zwrocilo definicji widoku ' . $view);
    }
    $charset = db_sync_native_row_get($rows[0], 'character_set_client', 'utf8');
    $collation = db_sync_native_row_get($rows[0], 'collation_connection', 'utf8_general_ci');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
        $charset = 'utf8';
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
        $collation = 'utf8_general_ci';
    }
    $set = "SET @dbsync_saved_cs_client = @@character_set_client;\n"
        . "SET @dbsync_saved_cs_results = @@character_set_results;\n"
        . "SET @dbsync_saved_col_connection = @@collation_connection;\n"
        . "SET character_set_client = " . $charset . ";\n"
        . "SET character_set_results = " . $charset . ";\n"
        . "SET collation_connection = " . $collation . ";\n";
    $restore = "SET character_set_client = @dbsync_saved_cs_client;\n"
        . "SET character_set_results = @dbsync_saved_cs_results;\n"
        . "SET collation_connection = @dbsync_saved_col_connection;\n";
    if (!db_sync_write_all($fh, "\n--\n-- View " . $qView . "\n--\n\n"
        . $set
        . 'DROP TABLE IF EXISTS ' . $qView . ";\n"
        . 'DROP VIEW IF EXISTS ' . $qView . ";\n"
        . $create . ";\n" . $restore)) {
        throw new Exception('Nie moge zapisac dumpu (widok ' . $view . ').');
    }
    db_sync_log('DUMP', $idx . '/' . $total . ' widok ' . $view);
}

function db_sync_native_dump_triggers($conn, $fh, $dbName, $exclude)
{
    try {
        $rows = db_sync_native_fetch_all($conn, 'SHOW TRIGGERS');
    } catch (Exception $e) {
        db_sync_log('OSTRZEZENIE', 'nie moge odczytac listy triggerow: ' . $e->getMessage(), true);
        return;
    }
    foreach ($rows as $row) {
        $name  = db_sync_native_row_get($row, 'Trigger', '');
        $table = db_sync_native_row_get($row, 'Table', '');
        if ($name === '' || in_array($table, $exclude, true)) {
            continue;
        }
        try {
            $qName = db_sync_native_identifier($name);
            $defs  = db_sync_native_fetch_all($conn, 'SHOW CREATE TRIGGER ' . $qName);
            if (empty($defs)) {
                continue;
            }
            $create = '';
            foreach ($defs[0] as $key => $value) {
                if (stripos($key, 'SQL Original Statement') !== false) {
                    $create = $value;
                    break;
                }
            }
            if ($create === '' && isset($defs[0][2])) {
                $create = $defs[0][2];
            }
            if ($create === '') {
                continue;
            }
            $create = rtrim($create);
            if (substr($create, -1) === ';') {
                $create = rtrim(substr($create, 0, -1));
            }
            $sqlMode   = db_sync_native_row_get($defs[0], 'sql_mode', '');
            $charset   = db_sync_native_row_get($defs[0], 'character_set_client', 'utf8');
            $collation = db_sync_native_row_get($defs[0], 'collation_connection', 'utf8_general_ci');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
                $charset = 'utf8';
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
                $collation = 'utf8_general_ci';
            }
            $set = "SET @dbsync_saved_cs_client = @@character_set_client;\n"
                . "SET @dbsync_saved_cs_results = @@character_set_results;\n"
                . "SET @dbsync_saved_col_connection = @@collation_connection;\n"
                . "SET @dbsync_saved_sql_mode = @@sql_mode;\n"
                . "SET character_set_client = " . $charset . ";\n"
                . "SET character_set_results = " . $charset . ";\n"
                . "SET collation_connection = " . $collation . ";\n";
            if ($sqlMode !== '') {
                $set .= "SET sql_mode = " . db_sync_native_quote($conn, $sqlMode) . ";\n";
            }
            $restore = "SET sql_mode = @dbsync_saved_sql_mode;\n"
                . "SET character_set_client = @dbsync_saved_cs_client;\n"
                . "SET character_set_results = @dbsync_saved_cs_results;\n"
                . "SET collation_connection = @dbsync_saved_col_connection;\n";
            // DELIMITER obsluguje zarowno mysql CLI, jak i parser PHP.
            if (!db_sync_write_all($fh, "\n--\n-- Trigger " . $qName . "\n--\n\n"
                . $set
                . 'DROP TRIGGER IF EXISTS ' . $qName . ";\n"
                . "DELIMITER ;;\n" . $create . ";;\nDELIMITER ;\n"
                . $restore)) {
                throw new Exception('Nie moge zapisac triggera ' . $name);
            }
        } catch (Exception $e) {
            db_sync_log('OSTRZEZENIE', 'pomijam trigger ' . $name . ': ' . $e->getMessage(), true);
        }
    }
}

function db_sync_native_dump($creds, $host, $port, $exclude, $sqlFile)
{
    if (!function_exists('fopen') || !function_exists('fwrite') || !function_exists('fclose') || !function_exists('fflush')) {
        throw new Exception('Dump PHP wymaga funkcji fopen/fwrite/fclose/fflush, ktore sa wylaczone na serwerze.');
    }
    $conn = db_sync_native_open($creds, $host, $port);
    $part = $sqlFile . '.part';
    $fh   = @fopen($part, 'wb');
    if ($fh === false) {
        db_sync_native_close($conn);
        throw new Exception('Nie moge utworzyc pliku dumpu: ' . $part);
    }

    $snapshot = false;
    try {
        @db_sync_native_try($conn, "SET NAMES utf8");
        @db_sync_native_try($conn, "SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
        $tzUtc = db_sync_native_try($conn, "SET SESSION time_zone='+00:00'");
        // Odpowiednik --single-transaction dla InnoDB (spojny snapshot).
        $snapshot = db_sync_native_try($conn, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $snapshot = $snapshot && db_sync_native_try($conn, 'START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $meta  = db_sync_native_tables_meta($conn, $creds['DB_NAME']);
        $base  = array();
        $views = array();
        foreach ($meta as $name => $m) {
            if (in_array($name, $exclude, true)) {
                continue;
            }
            if (strtoupper($m['type']) === 'VIEW') {
                $views[] = $name;
            } else {
                $base[] = $name;
            }
        }
        db_sync_log('TRYB DUMPU', 'PHP (wbudowany) - mysqli/PDO, bez mysqldump');
        db_sync_log('TABELE', count($base) . ' tabel, ' . count($views) . ' widokow');

        if (!db_sync_native_dump_header($fh, $creds['DB_NAME'], $tzUtc)) {
            throw new Exception('Nie moge zapisac naglowka dumpu.');
        }
        $total = count($base);
        foreach ($base as $i => $table) {
            db_sync_native_dump_table($conn, $fh, $creds['DB_NAME'], $table, $i + 1, $total);
        }
        db_sync_native_dump_view_placeholders($conn, $fh, $creds['DB_NAME'], $views);
        $totalViews = count($views);
        foreach ($views as $i => $view) {
            db_sync_native_dump_view($conn, $fh, $creds['DB_NAME'], $view, $i + 1, $totalViews);
        }
        db_sync_native_dump_triggers($conn, $fh, $creds['DB_NAME'], $exclude);
        if (!db_sync_native_dump_footer($fh, $tzUtc)) {
            throw new Exception('Nie moge zapisac stopki dumpu.');
        }
        if ($snapshot) {
            db_sync_native_try($conn, 'COMMIT');
        }
        fflush($fh);
        if (!fclose($fh)) {
            $fh = false;
            throw new Exception('Nie moge zakonczyc zapisu pliku dumpu (dysk pelny?).');
        }
        $fh = false;

        if (is_file($sqlFile) && !@unlink($sqlFile)) {
            throw new Exception('Nie moge nadpisac istniejacego pliku: ' . $sqlFile);
        }
        if (!@rename($part, $sqlFile)) {
            throw new Exception('Nie moge zmienic nazwy pliku dumpu: ' . basename($part));
        }
    } catch (Exception $e) {
        if (is_resource($fh)) {
            fclose($fh);
        }
        if ($snapshot) {
            db_sync_native_try($conn, 'ROLLBACK');
        }
        @unlink($part);
        db_sync_native_close($conn);
        throw $e;
    }
    db_sync_native_close($conn);
    return $sqlFile;
}

/* Parser pliku SQL: strumieniowo dzieli go na instrukcje, uwzgledniajac
   cudzyslowy, backticki, komentarze, komentarze warunkowe (wykrzyknik
   po nawiasie) oraz DELIMITER (triggery). Kazda instrukcje przekazuje
   do $callback. */
function db_sync_sql_parse_file($sqlFile, $callback, $mariaDb = true)
{
    if (!function_exists('fopen') || !function_exists('fgets') || !function_exists('fclose')) {
        throw new Exception('Import PHP wymaga funkcji fopen/fgets/fclose, ktore sa wylaczone na serwerze.');
    }
    $fh = @fopen($sqlFile, 'rb');
    if ($fh === false) {
        throw new Exception('Nie moge odczytac pliku SQL: ' . $sqlFile);
    }
    $delimiter   = ';';
    $statement   = '';
    $inString    = false;
    $inBacktick  = false;
    $inBlock     = false;
    $inCondition = false;
    $inLine      = false;
    $escape      = false;
    $quote       = '';
    $lineNo      = 0;
    $firstLine   = true;

    try {
    while (($line = fgets($fh)) !== false) {
        $lineNo++;
        if ($firstLine) {
            if (substr($line, 0, 3) === "\xEF\xBB\xBF") {
                $line = substr($line, 3);
            }
            $firstLine = false;
        }
        if (!$inString && !$inBacktick && !$inBlock && !$inCondition && !$inLine
            && trim($statement) === ''
            && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
            $delimiter = $m[1];
            $statement = '';
            continue;
        }
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch    = $line[$i];
            $next  = ($i + 1 < $len) ? $line[$i + 1] : '';
            $next2 = ($i + 2 < $len) ? $line[$i + 2] : '';

            if ($inLine) {
                if ($ch === "\n") {
                    $inLine = false;
                }
                continue;
            }
            if ($inBlock) {
                if ($ch === '*' && $next === '/') {
                    $inBlock = false;
                    $i++;
                }
                continue;
            }
            if ($inString) {
                $statement .= $ch;
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === $quote) {
                    $inString = false;
                }
                continue;
            }
            if ($inBacktick) {
                $statement .= $ch;
                if ($ch === '`') {
                    $inBacktick = false;
                }
                continue;
            }
            if ($inCondition) {
                // wewnatrz komentarza warunkowego obowiazuje normalna skladnia
                // (cudzyslowy/komentarze), ale srednik nie konczy instrukcji
                // przed zamknieciem "*/"
                if ($ch === '*' && $next === '/') {
                    $inCondition = false;
                    $i++;
                    continue;
                }
                if ($ch === "'" || $ch === '"') {
                    $inString = true;
                    $quote    = $ch;
                    $statement .= $ch;
                    continue;
                }
                if ($ch === '`') {
                    $inBacktick = true;
                    $statement .= $ch;
                    continue;
                }
                if ($ch === '#') {
                    $statement .= ' ';
                    $inLine = true;
                    continue;
                }
                if ($ch === '-' && $next === '-'
                    && ($next2 === '' || $next2 === ' ' || $next2 === "\t" || $next2 === "\r" || $next2 === "\n")) {
                    $statement .= ' ';
                    $inLine = true;
                    $i++;
                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $statement .= ' ';
                    $inBlock = true;
                    $i++;
                    continue;
                }
                $statement .= $ch;
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $quote    = $ch;
                $statement .= $ch;
                continue;
            }
            if ($ch === '`') {
                $inBacktick = true;
                $statement .= $ch;
                continue;
            }
            if ($ch === '#') {
                $statement .= ' ';
                $inLine = true;
                continue;
            }
            if ($ch === '-' && $next === '-'
                && ($next2 === '' || $next2 === ' ' || $next2 === "\t" || $next2 === "\r" || $next2 === "\n")) {
                $statement .= ' ';
                $inLine = true;
                $i++;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                if ($next2 === '!') {
                    $statement .= ' ';
                    $inCondition = true;
                    $i += 2;
                    while ($i + 1 < $len && preg_match('/[0-9]/', $line[$i + 1])) {
                        $i++;
                    }
                    continue;
                }
                if ($next2 === 'M' && $i + 3 < $len && $line[$i + 3] === '!') {
                    $statement .= ' ';
                    if ($mariaDb) {
                        // MariaDB wykonuje /*M!...*/; MySQL traktuje to jako
                        // zwykly komentarz i pomija cala tresc.
                        $inCondition = true;
                        $i += 3;
                        while ($i + 1 < $len && preg_match('/[0-9]/', $line[$i + 1])) {
                            $i++;
                        }
                    } else {
                        $inBlock = true;
                        $i += 3;
                    }
                    continue;
                }
                $statement .= ' ';
                $inBlock = true;
                $i++;
                continue;
            }
            $isDelimiter = ($delimiter === ';')
                ? ($ch === ';')
                : (substr($line, $i, strlen($delimiter)) === $delimiter);
            if ($isDelimiter) {
                $stmt = trim($statement);
                $statement = '';
                if ($stmt !== '') {
                    call_user_func($callback, $stmt, $lineNo);
                }
                $i += strlen($delimiter) - 1;
                continue;
            }
            $statement .= $ch;
        }
    }
    } catch (Exception $e) {
        fclose($fh);
        throw $e;
    }
    fclose($fh);

    if ($inString || $inBacktick || $inBlock || $inCondition) {
        throw new Exception('Plik SQL jest niekompletny (niezamkniety cudzyslow, backtick albo komentarz) w linii ' . $lineNo . '.');
    }
    $stmt = trim($statement);
    if ($stmt !== '') {
        call_user_func($callback, $stmt, $lineNo);
    }
}

function db_sync_native_is_maria($conn)
{
    try {
        $rows = db_sync_native_fetch_all($conn, 'SELECT VERSION() AS version');
        if (!empty($rows)) {
            $version = db_sync_native_row_get($rows[0], 'version', '');
            return (stripos($version, 'mariadb') !== false);
        }
    } catch (Exception $e) {
        // brak odpowiedzi - zostaje zachowanie zgodne z MariaDB
    }
    return true;
}

function db_sync_native_import($creds, $host, $port, $sqlFile)
{
    $conn = db_sync_native_open($creds, $host, $port);
    @db_sync_native_try($conn, 'SET NAMES utf8');
    @db_sync_native_try($conn, 'SET SESSION max_allowed_packet=1073741824');
    $mariaDb = db_sync_native_is_maria($conn);

    $executed = 0;
    try {
        db_sync_sql_parse_file($sqlFile, function ($stmt, $line) use ($conn, &$executed) {
            try {
                $res = db_sync_native_query($conn, $stmt);
                db_sync_native_free($conn, $res);
            } catch (Exception $e) {
                throw new Exception('linia ' . $line . ': ' . $e->getMessage()
                    . ' [SQL: ' . db_sync_shorten_text($stmt, 220) . ']');
            }
            $executed++;
            if ($executed % 1000 === 0) {
                db_sync_log('IMPORT', 'wykonano ' . $executed . ' instrukcji (linia ' . $line . ')');
            }
        }, $mariaDb);
    } catch (Exception $e) {
        db_sync_native_close($conn);
        throw new Exception('Import PHP zatrzymal sie: ' . $e->getMessage());
    }
    db_sync_native_close($conn);
    db_sync_log('SYNC GOTOWY', 'baza ' . $creds['DB_NAME'] . ' zostala zaladowana z ' . basename($sqlFile)
        . ' (' . $executed . ' instrukcji, tryb PHP)');
}

function db_sync_test_connection($creds, $host, $port)
{
    $mysql = db_sync_get_param('MYSQL');
    if ($mysql !== '' && !db_sync_process_available()) {
        db_sync_log('POLACZENIE Z BAZA', 'PHP (wbudowane) - MYSQL wskazany, ale brak funkcji uruchamiania procesow', true);
        $mysql = '';
    }
    if ($mysql === '' && db_sync_process_available()) {
        $mysql = db_sync_find_binary(array('mysql', 'mysql.exe'), db_sync_binary_candidates('mysql'));
    }
    if ($mysql === false || !db_sync_process_available()) {
        return db_sync_native_test_connection($creds, $host, $port);
    }

    $cmd = db_sync_bin_arg($mysql)
        . db_sync_conn_args($host, $port)
        . ' --user=' . escapeshellarg($creds['DB_USER'])
        . ' --password=' . escapeshellarg($creds['DB_PASSWORD'])
        . ' --connect-timeout=5 --skip-column-names --execute=' . escapeshellarg('SELECT 1')
        . ' 2>&1';

    db_sync_exec($cmd, $out, $code);
    if ($code !== 0) {
        $err = trim(implode("\n", $out));
        db_sync_log('OSTRZEZENIE', 'mysql CLI: ' . $err . ' - testuje polaczenie przez PHP', true);
        return db_sync_native_test_connection($creds, $host, $port);
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
            if (!db_sync_process_available()) {
                $note = 'niedostepny: funkcje uruchamiania procesow sa wylaczone (uzyj ZIP)';
            } else {
                $note = 'niedostepny: brak narzedzia (' . implode(' / ', $st['fmt']['tools']) . ')';
            }
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
        . '</b> (katalog archiwow - archiwa nie trafiaja do archiwum i nie ma go na liscie wzorcow ponizej, bo nie da sie go dolaczyc).</p>';
    // wzorce edytowalne: pokazujemy, co jest aktualnie wykluczone, a co dolaczone
    $exclManaged = array();
    $inclManaged = array();
    foreach (db_sync_archive_managed_exclude() as $managed) {
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
        // field-sizing:content (Chrome 123+) rozciaga pole do zawartosci;
        // rows/cols zostaja jako rozmiar zastepczy dla starszych przegladarek
        . '<textarea name="exclude" rows="8" cols="60" spellcheck="false" style="font-family:Consolas,monospace;font-size:13px;padding:6px;box-sizing:border-box;min-width:32em;max-width:100%;min-height:7em;max-height:60vh;resize:vertical;field-sizing:content">'
        . htmlspecialchars(implode("\n", $patterns)) . '</textarea>';
    echo '<p style="margin:8px 0 0">'
        . '<button type="submit" name="exclude_defaults" value="1" onclick="return confirm(\'Przywrocic domyslna liste wykluczen?\nWzorce zapisza sie od razu (archiwum nie powstanie).\');" style="font-family:Consolas,monospace;font-size:13px;padding:6px 12px;cursor:pointer;background:#eee;border:1px solid #ccc;border-radius:4px">Ustaw domyslne</button>'
        . ' <small style="color:#888">przywraca pelny zestaw wzorcow domyslnych</small>'
        . '</p>';
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
            if (!db_sync_path_allowed($root) || !@is_dir($root)) {
                continue;
            }
            $candidates[] = $root . '/' . $exe;
            foreach (@glob($root . '/*/bin/' . $exe) ?: array() as $p) {
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
    // Bez funkcji uruchamiania procesow pomijamy ten krok - nie mozna ani
    // odpalic where/which, ani pozniej uzyc znalezionej binarki.
    if (db_sync_process_available()) {
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
    }
    // 2) sprawdzenie znanych katalogow
    foreach ($candidates as $candidate) {
        if (!db_sync_path_allowed($candidate)) {
            continue;
        }
        if (@is_file($candidate) && (PHP_OS_FAMILY === 'Windows' || @is_executable($candidate))) {
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
        if (db_sync_process_available()) {
            db_sync_exec('where ' . escapeshellarg($binary) . ' 2>&1', $diag, $diagCode);
        }
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
    if ($mysql !== '' && !db_sync_process_available()) {
        $mysql = '';
    }
    if ($mysql === '' && db_sync_process_available()) {
        $mysql = db_sync_find_binary(array('mysql', 'mysql.exe'), db_sync_binary_candidates('mysql'));
    }
    if ($mysql === false || !db_sync_process_available()) {
        db_sync_log('LISTA TABEL', 'PHP (wbudowane) - mysqli/PDO, bez mysql CLI');
        return db_sync_native_list_tables($creds, $host, $port);
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
        db_sync_log('OSTRZEZENIE', 'mysql CLI nie pobral listy tabel: ' . trim(implode("\n", $out))
            . ' - uzywam listy przez PHP', true);
        return db_sync_native_list_tables($creds, $host, $port);
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

    $mysqldump = false;
    if (db_sync_process_available()) {
        $candidate = db_sync_get_param('MYSQLDUMP');
        if ($candidate === '') {
            $candidate = db_sync_find_binary(array('mysqldump', 'mysqldump.exe'), db_sync_binary_candidates('mysqldump'));
        }
        if ($candidate !== false) {
            try {
                $mysqldump = db_sync_probe('mysqldump', 'MYSQLDUMP');
            } catch (Exception $e) {
                db_sync_log('OSTRZEZENIE', 'mysqldump niedostepny: ' . $e->getMessage() . ' - uzywam dumpu PHP', true);
            }
        } else {
            db_sync_log('OSTRZEZENIE', 'Nie znaleziono mysqldump - uzywam dumpu PHP (mysqli/PDO).', true);
        }
    } else {
        db_sync_log('TRYB DUMPU', 'PHP (wbudowany) - funkcje uruchamiania procesow sa wylaczone');
    }

    if ($mysqldump === false) {
        db_sync_native_dump($creds, $host, $port, $exclude, $sqlFile);
    } else {
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
    }

    if (!is_file($sqlFile) || filesize($sqlFile) === 0) {
        throw new Exception('Dump nie zostal utworzony (brak pliku albo plik jest pusty): ' . $sqlFile);
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

    $mysql = false;
    if (db_sync_process_available()) {
        $candidate = db_sync_get_param('MYSQL');
        if ($candidate === '') {
            $candidate = db_sync_find_binary(array('mysql', 'mysql.exe'), db_sync_binary_candidates('mysql'));
        }
        if ($candidate !== false) {
            try {
                $mysql = db_sync_probe('mysql', 'MYSQL');
            } catch (Exception $e) {
                db_sync_log('OSTRZEZENIE', 'mysql niedostepny: ' . $e->getMessage() . ' - uzywam importu PHP', true);
            }
        } else {
            db_sync_log('OSTRZEZENIE', 'Nie znaleziono mysql - uzywam importu PHP (mysqli/PDO).', true);
        }
    } else {
        db_sync_log('TRYB IMPORTU', 'PHP (wbudowany) - funkcje uruchamiania procesow sa wylaczone');
    }

    if ($mysql === false) {
        db_sync_native_import($creds, $host, $port, $sqlFile);
        return;
    }

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
    $root = rtrim(str_replace('\\', '/', __DIR__), '/');
    if ($root === '') {
        // Magiczna stala katalogu bywa pusta, gdy skrypt lezy w korzeniu
        // systemu plikow (docroot = '/', np. konto chrootowane) albo jest
        // ladowany przez wrapper bez sciezki. scandir('') na PHP 8 rzuca
        // ValueError (Fatal error - poza zasiegiem @ i try/catch), dlatego
        // root nigdy nie moze byc pusty: awaryjnie katalog z __FILE__
        // (realpath), potem cwd procesu php-cgi (CGI SAPI ustawia cwd =
        // katalog skryptu).
        $real = function_exists('realpath') ? @realpath(str_replace('\\', '/', __FILE__)) : false;
        if (is_string($real) && $real !== '') {
            $root = rtrim(str_replace('\\', '/', dirname($real)), '/');
        }
        if ($root === '') {
            $cwd  = function_exists('getcwd') ? getcwd() : false;
            $cwd  = is_string($cwd) ? rtrim(str_replace('\\', '/', $cwd), '/') : '';
            $root = ($cwd !== '' && $cwd !== '/') ? $cwd : '.';
        }
    }
    // Sprowadzenie do formy absolutnej: z docroot='/' root bywa wzgledny
    // ('.'), a stala DB_SYNC_ARCHIVE_DIR liczona jest z surowej, absolutnej
    // sciezki katalogu - bez wspolnej podstawy db_sync_archive_hard_dirs
    // nie wykrylby katalogu archiwow pod rootem (trafial do archiwum!).
    return db_sync_archive_abs($root);
}

/* Sprowadza sciezke do formy absolutnej (do porownan prefiksu):
   - zamienia \ na /,
   - rozpoznaje litere dysku (Windows),
   - sciezki wzgledne dopina biezacym katalogiem procesu (getcwd); dla
     php-cgi jest to zwykle katalog skryptu, wiec '.' sprowadza sie do
     tej samej podstawy, z ktorej liczona jest stala DB_SYNC_ARCHIVE_DIR,
   - porzadkuje '//' i '/.'.
   Nie rozwiazuje symlinkow (porownanie tekstowe, jak we wczesniejszych
   wersjach). Sciezka pusta daje korzen '/'. */
function db_sync_archive_abs($path)
{
    $path = rtrim(str_replace('\\', '/', (string) $path), '/');
    if ($path === '') {
        return '/';
    }
    $drive = '';
    if (preg_match('/^([A-Za-z]:)(.*)$/', $path, $m)) {
        $drive = strtoupper($m[1]);
        $path  = rtrim($m[2], '/');
    }
    if (substr($path, 0, 1) !== '/') {
        $cwd = function_exists('getcwd') ? getcwd() : false;
        $cwd = is_string($cwd) ? str_replace('\\', '/', $cwd) : '';
        if ($cwd !== '' && $cwd !== '/') {
            $cd = '';
            if (preg_match('/^([A-Za-z]:)(.*)$/', $cwd, $m2)) {
                $cd = strtoupper($m2[1]);
                $cwd = rtrim($m2[2], '/');
            }
            if ($drive === '') {
                $drive = $cd;
            }
            $path = $cwd . '/' . $path;
        } elseif ($cwd === '/') {
            // korzen systemu plikow (docroot='/', chroot): '.' znaczy '/'
            $path = '/' . $path;
        }
        // brak getcwd (i korzenia) -> zostaje sciezka wzgledna (fallback)
    }
    $path = preg_replace('/\/+/', '/', $path);
    $path = preg_replace('/\/\.(?=\/|$)/', '/', $path);
    $out  = $drive . rtrim($path, '/');
    return ($out === '') ? (($drive !== '') ? $drive . '/' : '/') : $out;
}

function db_sync_archive_hard_dirs()
{
    // Bezwarunkowo (poza lista wykluczen, ktora jest edytowalna) pomijany jest
    // tylko katalog, do ktorego trafia samo archiwum - inaczej archiwum
    // pakowaloby poprzednie archiwa (i wlasny plik tymczasowy *.part).
    // Katalog _dbsync (dumpy bazy) jest zwyklym wzorcem w wykluczeniach,
    // a katalogu archiwow nie ma na liscie wzorcow domyslnych (patrz
    // db_sync_archive_default_exclude) - i tak nie da sie go dolaczyc.
    // Oba argumenty przez db_sync_archive_abs: przy docroot='/' root bywa
    // wzgledny ('.') i bez wspolnej podstawy prefiks nie zostalby wykryty.
    // Root '/' (docroot = korzen, np. chroot) obejmuje wtedy wszystko.
    $root = db_sync_archive_abs(db_sync_archive_root());
    $out  = array();
    $arc  = db_sync_archive_abs(DB_SYNC_ARCHIVE_DIR);
    if ($arc !== '' && $arc !== $root
        && ($root === '/' || strpos($arc . '/', $root . '/') === 0)) {
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
    /* Kolejnosc pozycji na liscie 'tools' wyznacza preferencje narzedzia dla
       formatu (db_sync_archive_format_status bierze pierwsze dostepne).
       ZIP: na Linux najpierw systemowy Info-ZIP 'zip', a 7-Zip tylko jako
       zapas; na Windows odwrotnie - 7-Zip pierwszy, bo Info-ZIP gubi tam
       polskie znaki (db_sync_archive_tool_utf8_ok). */
    $zipTools = (PHP_OS_FAMILY === 'Windows') ? array('7z', 'zip') : array('zip', '7z');
    // 'php' to zawsze dostepny zapas: ZipArchive albo wlasny writer ZIP.
    $zipTools[] = 'php';
    return array(
        'zip'    => array('ext' => '.zip',    'label' => 'ZIP',    'tools' => $zipTools),
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
        if (db_sync_path_allowed($root) && @is_dir($root)) {
            $candidates[] = $root . '/' . $name;
        }
    }
    return $candidates;
}

/* ------------------------------------------------------------------ */
/* Archiwum ZIP bez zewnetrznych narzedzi (fallback PHP)               */
/* ------------------------------------------------------------------ */

function db_sync_archive_zip_native_available()
{
    return class_exists('ZipArchive')
        || (function_exists('fopen') && function_exists('fwrite') && function_exists('pack'));
}

function db_sync_archive_zip_native_label()
{
    return class_exists('ZipArchive') ? 'PHP ZipArchive' : 'PHP (wbudowany ZIP)';
}

function db_sync_archive_zip_dos_time($timestamp)
{
    $t = getdate((int) $timestamp);
    $year = (int) $t['year'];
    if ($year < 1980) {
        $year = 1980;
    }
    if ($year > 2107) {
        $year = 2107;
    }
    $time = (($t['hours'] & 0x1F) << 11) | (($t['minutes'] & 0x3F) << 5) | (($t['seconds'] >> 1) & 0x1F);
    $date = (($year - 1980) << 9) | (($t['mon'] & 0x0F) << 5) | ($t['mday'] & 0x1F);
    return array('time' => $time, 'date' => $date);
}

function db_sync_archive_zip_crc_bytes($hex)
{
    // ZIP przechowuje CRC32 w little-endian; hash('crc32b') daje big-endian hex.
    $raw = pack('H*', $hex);
    return strrev($raw);
}

function db_sync_archive_zip_create_ziparchive($root, $listFile, $part, $stats)
{
    $flags = defined('ZipArchive::CREATE') ? ZipArchive::CREATE : 0;
    if (defined('ZipArchive::OVERWRITE')) {
        $flags |= ZipArchive::OVERWRITE;
    }
    $zip = new ZipArchive();
    $res = $zip->open($part, $flags);
    if ($res !== true) {
        throw new Exception('ZipArchive::open nie powiodlo sie (kod ' . $res . ').');
    }
    if (!function_exists('file')) {
        $zip->close();
        throw new Exception('ZipArchive wymaga funkcji file(), ktora jest wylaczona na serwerze.');
    }
    $lines = @file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        $zip->close();
        throw new Exception('Nie moge odczytac listy plikow: ' . $listFile);
    }
    $count = 0;
    foreach ($lines as $rel) {
        $rel = rtrim($rel, "\r\n");
        if ($rel === '') {
            continue;
        }
        $abs = $root . '/' . $rel;
        if (!is_file($abs)) {
            $zip->close();
            throw new Exception('Brak pliku do archiwum: ' . $rel);
        }
        if (!$zip->addFile($abs, $rel)) {
            $zip->close();
            throw new Exception('Nie moge dodac pliku do archiwum: ' . $rel);
        }
        $count++;
    }
    if (!$zip->close()) {
        throw new Exception('ZipArchive::close nie powiodlo sie.');
    }
    if ($count !== (int) $stats['files']) {
        throw new Exception('ZipArchive dodal ' . $count . ' z ' . $stats['files'] . ' plikow.');
    }
    return $count;
}

function db_sync_archive_zip_create_manual($root, $listFile, $part, $stats)
{
    if (!function_exists('fopen') || !function_exists('fwrite') || !function_exists('fgets')
        || !function_exists('fread') || !function_exists('feof') || !function_exists('fclose')
        || !function_exists('hash') || !function_exists('hash_init')
        || !function_exists('hash_update') || !function_exists('hash_final')
        || !function_exists('pack')) {
        throw new Exception('Wbudowany writer ZIP wymaga funkcji fopen/fwrite/fgets/fread/hash_init, ktore sa wylaczone na serwerze.');
    }
    $fh = @fopen($part, 'wb');
    if ($fh === false) {
        throw new Exception('Nie moge utworzyc archiwum: ' . $part);
    }
    $central = array();
    $offset  = 0;
    $count   = 0;
    $fhList  = @fopen($listFile, 'rb');
    if ($fhList === false) {
        fclose($fh);
        @unlink($part);
        throw new Exception('Nie moge odczytac listy plikow: ' . $listFile);
    }

    try {
        while (($line = fgets($fhList)) !== false) {
            $rel = rtrim($line, "\r\n");
            if ($rel === '') {
                continue;
            }
            $abs = $root . '/' . $rel;
            if (!is_file($abs)) {
                throw new Exception('Brak pliku do archiwum: ' . $rel);
            }
            $size = @filesize($abs);
            if ($size === false) {
                throw new Exception('Nie moge odczytac rozmiaru pliku: ' . $rel);
            }
            $mtime = @filemtime($abs);
            if ($mtime === false) {
                $mtime = time();
            }
            $perms = @fileperms($abs);
            if ($perms === false) {
                $perms = 0644;
            }
            $dos = db_sync_archive_zip_dos_time($mtime);

            $method     = 0;
            $compData   = null;
            $crcHex     = '';
            $compSize   = 0;
            $uncompSize = 0;

            // male pliki: kompresja deflate w pamieci (dobry kompromis)
            if (function_exists('gzdeflate') && $size <= 33554432) {
                $data = @file_get_contents($abs);
                if ($data !== false) {
                    $crcHex = hash('crc32b', $data);
                    $deflated = @gzdeflate($data, 6);
                    if ($deflated !== false && strlen($deflated) < strlen($data)) {
                        $method     = 8;
                        $compData   = $deflated;
                        $compSize   = strlen($deflated);
                        $uncompSize = strlen($data);
                    } else {
                        $compData   = $data;
                        $compSize   = strlen($data);
                        $uncompSize = strlen($data);
                    }
                }
            }

            $name    = str_replace('\\', '/', $rel);
            $nameLen = strlen($name);
            if ($nameLen > 65535) {
                throw new Exception('Zbyt dluga nazwa pliku w ZIP: ' . $rel);
            }
            $flags = 0x0800 | 0x0008; // UTF-8 + data descriptor
            $localHeader = pack('V', 0x04034b50)
                . pack('v', 20)
                . pack('v', $flags)
                . pack('v', $method)
                . pack('v', $dos['time'])
                . pack('v', $dos['date'])
                . pack('V', 0) . pack('V', 0) . pack('V', 0)
                . pack('v', $nameLen)
                . pack('v', 0)
                . $name;
            if (!db_sync_write_all($fh, $localHeader)) {
                throw new Exception('Nie moge zapisac naglowka ZIP: ' . $rel);
            }
            $offset += strlen($localHeader);
            $localOffset = $offset - strlen($localHeader);

            if ($compData !== null) {
                if (!db_sync_write_all($fh, $compData)) {
                    throw new Exception('Nie moge zapisac danych ZIP: ' . $rel);
                }
                $offset += $compSize;
            } else {
                // duze pliki: metoda store, strumieniowo (bez ladowania do RAM)
                $in = @fopen($abs, 'rb');
                if ($in === false) {
                    throw new Exception('Nie moge odczytac pliku: ' . $rel);
                }
                $crcCtx = hash_init('crc32b');
                while (!feof($in)) {
                    $chunk = fread($in, 1048576);
                    if ($chunk === false) {
                        fclose($in);
                        throw new Exception('Blad odczytu pliku: ' . $rel);
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    hash_update($crcCtx, $chunk);
                    if (!db_sync_write_all($fh, $chunk)) {
                        fclose($in);
                        throw new Exception('Nie moge zapisac danych ZIP: ' . $rel);
                    }
                    $uncompSize += strlen($chunk);
                    $offset += strlen($chunk);
                }
                fclose($in);
                $crcHex  = hash_final($crcCtx);
                $compSize = $uncompSize;
                $method   = 0;
            }

            if ($compSize > 0xFFFFFFFF || $uncompSize > 0xFFFFFFFF || $offset > 0xFFFFFFFF) {
                throw new Exception('Wbudowany ZIP nie obsluguje plikow/archiwum powyzej 4 GB - uzyj ZipArchive albo narzedzia CLI.');
            }
            $crcBin = db_sync_archive_zip_crc_bytes($crcHex);
            $desc = pack('V', 0x08074b50) . $crcBin
                . pack('V', $compSize) . pack('V', $uncompSize);
            if (!db_sync_write_all($fh, $desc)) {
                throw new Exception('Nie moge zapisac deskryptora ZIP: ' . $rel);
            }
            $offset += strlen($desc);

            $central[] = array(
                'name'   => $name,
                'flags'  => $flags,
                'method' => $method,
                'time'   => $dos['time'],
                'date'   => $dos['date'],
                'crc'    => $crcBin,
                'comp'   => $compSize,
                'uncomp' => $uncompSize,
                'offset' => $localOffset,
                'perms'  => $perms,
            );
            $count++;
            if ($count > 0xFFFF) {
                throw new Exception('Wbudowany ZIP obsluguje do 65535 plikow - uzyj ZipArchive albo narzedzia CLI.');
            }
        }
        fclose($fhList);
        $fhList = false;

        if ($count > 0xFFFF) {
            throw new Exception('Wbudowany ZIP obsluguje do 65535 plikow - uzyj ZipArchive albo narzedzia CLI.');
        }
        $cdStart = $offset;
        $cdSize  = 0;
        $versionMadeBy = (PHP_OS_FAMILY === 'Windows') ? 0x0014 : 0x031E;
        foreach ($central as $entry) {
            $nameLen = strlen($entry['name']);
            $external = (PHP_OS_FAMILY === 'Windows') ? 0 : (($entry['perms'] & 0xFFFF) << 16);
            if ($entry['offset'] > 0xFFFFFFFF || $entry['comp'] > 0xFFFFFFFF || $entry['uncomp'] > 0xFFFFFFFF) {
                throw new Exception('Wbudowany ZIP nie obsluguje plikow/archiwum powyzej 4 GB - uzyj ZipArchive albo narzedzia CLI.');
            }
            $cd = pack('V', 0x02014b50)
                . pack('v', $versionMadeBy)
                . pack('v', 20)
                . pack('v', $entry['flags'])
                . pack('v', $entry['method'])
                . pack('v', $entry['time'])
                . pack('v', $entry['date'])
                . $entry['crc']
                . pack('V', $entry['comp'])
                . pack('V', $entry['uncomp'])
                . pack('v', $nameLen)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('V', $external)
                . pack('V', $entry['offset'])
                . $entry['name'];
            if (!db_sync_write_all($fh, $cd)) {
                throw new Exception('Nie moge zapisac katalogu centralnego ZIP.');
            }
            $cdSize += strlen($cd);
        }
        if ($cdStart > 0xFFFFFFFF || $cdSize > 0xFFFFFFFF) {
            throw new Exception('Wbudowany ZIP nie obsluguje archiwum powyzej 4 GB - uzyj ZipArchive albo narzedzia CLI.');
        }
        $eocd = pack('V', 0x06054b50)
            . pack('v', 0) . pack('v', 0)
            . pack('v', $count) . pack('v', $count)
            . pack('V', $cdSize) . pack('V', $cdStart)
            . pack('v', 0);
        if (!db_sync_write_all($fh, $eocd)) {
            throw new Exception('Nie moge zapisac stopki ZIP.');
        }
        if (!fclose($fh)) {
            $fh = false;
            throw new Exception('Nie moge zakonczyc zapisu archiwum ZIP (dysk pelny?).');
        }
        $fh = false;
        if ($count !== (int) $stats['files']) {
            throw new Exception('Wbudowany ZIP zapisal ' . $count . ' z ' . $stats['files'] . ' plikow.');
        }
    } catch (Exception $e) {
        if (is_resource($fhList)) {
            fclose($fhList);
        }
        if (is_resource($fh)) {
            fclose($fh);
        }
        @unlink($part);
        throw $e;
    }
    return $count;
}

function db_sync_archive_zip_create_native($root, $listFile, $part, $logFile, $statusFile, $stats, $forceManual = false)
{
    $engine = 'manual';
    if (!$forceManual && class_exists('ZipArchive')) {
        try {
            db_sync_archive_zip_create_ziparchive($root, $listFile, $part, $stats);
            $engine = 'ZipArchive';
        } catch (Exception $e) {
            db_sync_log('OSTRZEZENIE', 'ZipArchive: ' . $e->getMessage() . ' - probuje wbudowanego writera ZIP', true);
            @unlink($part);
        }
    }
    if ($engine !== 'ZipArchive') {
        db_sync_archive_zip_create_manual($root, $listFile, $part, $stats);
    }
    $msg = 'PHP ZIP (' . $engine . '): ' . (int) $stats['files'] . ' plikow, '
        . db_sync_human_size($stats['bytes']) . " danych zrodlowych\n";
    if (@file_put_contents($logFile, $msg) === false) {
        @unlink($part);
        throw new Exception('Nie moge zapisac logu archiwum: ' . $logFile);
    }
    if (@file_put_contents($statusFile, "exit=0\n") === false) {
        @unlink($part);
        throw new Exception('Nie moge zapisac statusu archiwum: ' . $statusFile);
    }
    return true;
}

function db_sync_archive_tools()
{
    static $tools = null;
    if ($tools !== null) {
        return $tools;
    }
    $process = db_sync_process_available();
    $lookups = (PHP_OS_FAMILY === 'Windows') ? array('where') : array('which');
    $tools   = array();
    foreach (db_sync_archive_tool_names() as $key => $names) {
        // reczne wskazanie sciezki w URL, np. ?7Z=/pelna/sciezka/7z
        $param = db_sync_get_param(db_sync_archive_tool_arg($key));
        if ($param !== '') {
            if ($process && @is_file($param)) {
                $tools[$key] = $param;
            }
            continue;
        }
        if (!$process) {
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
    if (db_sync_archive_zip_native_available()) {
        $tools['php'] = db_sync_archive_zip_native_label();
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
    // 'php' zapisuje nazwy jako UTF-8 z flaga jezykowa ZIP - jest bezpieczny.
    return ($tool === '7z' || $tool === 'php');
}

function db_sync_archive_default_exclude()
{
    return array(
        // dumpy bazy i sam skrypt narzedzia - wzorce edytowalne: usuniecie
        // wzorca z listy oznacza dolaczenie do archiwum. Katalogu archiwow
        // (_dbsyncf/) nie ma na liscie, bo i tak jest pomijany bezwarunkowo
        // (patrz db_sync_archive_hard_dirs).
        '_dbsync/',
        '_dbsync.php',
        '_sar3/',
        '_sar4/',
        '.git/',
        '.svn/',
        'node_modules/',
        'cgi/',
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
    return array('_dbsync/', '_dbsync.php');
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
    return '# dbsync-exclude-v4';
}

/* Wzorce dopisywane przy migracji - zaleznie od wersji pliku:
   brak znacznika  = plik z wersji <= 1.3.1 (_dbsync/ i _dbsyncf/ byly
                     wtedy wykluczone na sztywno),
   znacznik v2     = plik z 1.4.0 (dochodzi tylko wzorzec samego skryptu),
   znacznik v3     = plik z 1.5.0-1.8.2 (nic nie dopisujemy). */
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

/* Wzorce usuwane przy migracji: pliki z wersji <= 1.8.2 mialy na liscie
   wzorzec _dbsyncf/, choc katalog archiwow jest pomijany bezwarunkowo
   (patrz db_sync_archive_hard_dirs) - wpis byl wiec zbedny i sugerowal, ze
   mozna go usunac, aby dolaczyc archiwa do archiwum. Usuwamy go tylko wtedy,
   gdy rzeczywiscie wskazuje katalog archiwow; gdy archiwa leza gdzie indziej
   (zmieniona stala DB_SYNC_ARCHIVE_DIR), wzorzec zostaje bez zmian. */
function db_sync_archive_exclude_migration_remove()
{
    if (db_sync_archive_exclude_has(db_sync_archive_hard_dirs(), '_dbsyncf/')) {
        return array('_dbsyncf/');
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
        $added   = array();
        $removed = array();
        foreach (db_sync_archive_exclude_migration($text) as $extra) {
            if (!db_sync_archive_exclude_has($lines, $extra)) {
                $lines[] = $extra;
                $added[] = $extra;
            }
        }
        $drop = db_sync_archive_exclude_migration_remove();
        if (!empty($drop)) {
            foreach ($lines as $i => $line) {
                if (db_sync_archive_exclude_has($drop, $line)) {
                    unset($lines[$i]);
                    $removed[] = $line;
                }
            }
            $lines = array_values($lines);
        }
        try {
            db_sync_archive_exclude_save($site, $lines);
            if (!empty($added)) {
                db_sync_log('MIGRACJA WYKLUCZEN', 'dopisano wzorce: ' . implode(', ', $added));
            }
            if (!empty($removed)) {
                db_sync_log('MIGRACJA WYKLUCZEN', 'usunieto zbedne wzorce: ' . implode(', ', $removed)
                    . ' (katalog archiwow jest pomijany bezwarunkowo)');
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

/* Przywrocenie domyslnej listy wykluczen (przycisk "Ustaw domyslne"):
   nadpisuje plik wykluczen wzorcami z db_sync_archive_default_exclude()
   i raportuje roznice wzgledem stanu poprzedniego. */
function db_sync_archive_exclude_reset($site)
{
    $before = db_sync_archive_exclude_load($site);
    $after  = db_sync_archive_default_exclude();
    $added   = array();
    $removed = array();
    foreach ($after as $pattern) {
        if (!db_sync_archive_exclude_has($before, $pattern)) {
            $added[] = $pattern;
        }
    }
    foreach ($before as $pattern) {
        if (!db_sync_archive_exclude_has($after, $pattern)) {
            $removed[] = $pattern;
        }
    }
    db_sync_archive_exclude_save($site, $after);
    db_sync_log('WYKLUCZENIA DOMYSLNE', 'przywrocono zestaw domyslny (' . count($after) . ' wzorcow)');
    if (!empty($added)) {
        db_sync_log('WYKLUCZENIA DOMYSLNE', 'dopisano wzorce: ' . implode(', ', $added));
    }
    if (!empty($removed)) {
        db_sync_log('WYKLUCZENIA DOMYSLNE', 'usunieto wzorce: ' . implode(', ', $removed), true);
    }
    return $after;
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
        if ($absDir === '') {
            $stats['errors'][] = 'nie moge ustalic katalogu zrodlowego (puste stale sciezki - skrypt w korzeniu systemu plikow?)';
            break;
        }
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
        if (!db_sync_process_available()) {
            throw new Exception('Format ' . $fmt['label'] . ' wymaga zewnetrznego narzedzia, a na serwerze sa wylaczone funkcje uruchamiania procesow.'
                . ' Wybierz format ZIP - jest obslugiwany przez wbudowany mechanizm PHP.');
        }
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

    if ($tool === 'php') {
        // Wbudowany writer ZIP: dziala nawet przy calkowicie wylaczonych
        // funkcjach uruchamiania procesow. Plik .part i plik statusu sa
        // zgodne z mechanizmem odtwarzania przerwanych archiwow.
        db_sync_log('NARZEDZIE', db_sync_archive_zip_native_label());
        db_sync_log('KOMENDA', 'PHP ZIP: ' . $stats['files'] . ' plikow   [cwd: ' . $root . ']');
        try {
            db_sync_archive_zip_create_native($root, $listFile, $part, $logFile, $statusFile, $stats);
        } catch (Exception $e) {
            db_sync_archive_unlink(array($listFile, $logFile, $statusFile, $part));
            throw $e;
        }
        $execCode   = 0;
        $statusCode = 0;
    } else {
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
    }
    $code = ($statusCode === null) ? (int) $execCode : $statusCode;

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

/* Sprawdzanie wersji i pobieranie pliku korzystaja z tego samego zrodla:
   swiezego archiwum brancha main z codeload.github.com. Nie uzywamy
   api.github.com (limit 60 zapytan/h per IP - na hostingu wspoldzielonym,
   np. s124.cyber-folks.pl, konczyl sie bledem HTTP 403 przy checkupdate)
   ani raw.githubusercontent.com jako zrodla glownego - jego CDN potrafi
   zwracac plik sprzed kilku minut (Cache-Control: max-age=300, parametr
   w URL NIE omija cache). Raw zostaje jako awaryjny fallback. */
define('DBSYNC_GITHUB_TARBALL_URL', 'https://codeload.github.com/' . DBSYNC_GITHUB_REPO . '/tar.gz/refs/heads/' . DBSYNC_GITHUB_BRANCH);
define('DBSYNC_GITHUB_RAW_URL', 'https://raw.githubusercontent.com/' . DBSYNC_GITHUB_REPO . '/' . DBSYNC_GITHUB_BRANCH . '/_dbsync.php');

function db_sync_http_get($url, $timeout = 10)
{
    // 1) curl (najczesciej dostepny na hostingach)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $headers = array();
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => '_dbsync/' . DBSYNC_VERSION,
            CURLOPT_HTTPHEADER => array('Accept: text/plain'),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ));
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            // od PHP 8.0 uchwyt curl jest obiektem i zwalnia sie sam, a PHP 8.5
            // oznacza wywolanie curl_close() jako deprecated (bez efektu)
            curl_close($ch);
        }
        if ($body !== false && $httpCode === 200) {
            return $body;
        }
        throw new Exception('curl: ' . db_sync_http_error_detail($err, $httpCode, $body, $headers));
    }
    // 2) file_get_contents (wymaga allow_url_fopen=On)
    if ((bool) ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => 'User-Agent: _dbsync/' . DBSYNC_VERSION
                    . "\r\nAccept: text/plain\r\n",
            ),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $body     = @file_get_contents($url, false, $ctx);
        $httpCode = 0;
        $headers  = array();
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $httpCode = (int) $m[1];
                    $headers  = array(); // przy przekierowaniu przychodzi nowy blok naglowkow
                } elseif (($pos = strpos($line, ':')) !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
            }
        }
        if ($body !== false && ($httpCode === 0 || $httpCode === 200)) {
            return $body;
        }
        if ($body === false && $httpCode === 0) {
            throw new Exception('Brak mozliwosci pobrania danych z sieci (curl wylaczony, a file_get_contents nie zwrocil danych).');
        }
        throw new Exception('file_get_contents: ' . db_sync_http_error_detail('', $httpCode, $body, $headers));
    }
    throw new Exception('Brak mozliwosci pobrania danych z sieci (curl wylaczony i allow_url_fopen=Off).');
}

/* Czytelny opis bledu HTTP: kod, wybrane naglowki (limity GitHub) i skrot tresci. */
function db_sync_http_error_detail($err, $httpCode, $body, $headers)
{
    $msg = ($err !== '' ? $err : 'HTTP ' . $httpCode);
    foreach (array('x-ratelimit-remaining', 'x-ratelimit-reset', 'retry-after') as $h) {
        if (isset($headers[$h]) && $headers[$h] !== '') {
            $msg .= '; ' . $h . ': ' . $headers[$h];
        }
    }
    if (is_string($body) && trim($body) !== '') {
        $msg .= '; tresc: ' . db_sync_shorten_text($body, 300);
    }
    return $msg;
}

function db_sync_shorten_text($text, $len = 300)
{
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') > $len) {
            return mb_substr($text, 0, $len, 'UTF-8') . '...';
        }
        return $text;
    }
    if (strlen($text) > $len) {
        return substr($text, 0, $len) . '...';
    }
    return $text;
}

function db_sync_remote_script()
{
    // 1) archiwum tar.gz z codeload.github.com - zawsze swieze, bez limitow API
    $body = db_sync_remote_script_from_tarball();
    if ($body === false) {
        // 2) awaryjnie raw.githubusercontent.com (CDN moze zwrocic plik
        //    sprzed kilku minut - zmienny parametr w URL tego nie omija)
        $body = db_sync_http_get(DBSYNC_GITHUB_RAW_URL);
    }
    if (stripos(ltrim($body), '<?php') !== 0) {
        throw new Exception('Pobrany plik nie zaczyna sie od <?php - to nie jest skrypt _dbsync.php.');
    }
    return $body;
}

function db_sync_remote_script_from_tarball()
{
    try {
        // ?t= nie omija propagacji archiwum po stronie GitHuba (ta trwa
        // kilkanascie(?) sekund po pushu), ale chroni przed ewentualnym
        // posrednim cache (proxy/CDN) po drodze; sprawdzone - 200 OK.
        $gz = db_sync_http_get(DBSYNC_GITHUB_TARBALL_URL . '?t=' . time(), 20);
    } catch (Exception $e) {
        return false;
    }
    $tar = false;
    if (function_exists('gzdecode')) {
        $tar = @gzdecode($gz);
    }
    if ($tar === false && strlen($gz) > 18) {
        // reczne rozpakowanie gzip: naglowek 10 B + trailer 8 B
        $tar = @gzinflate(substr($gz, 10, -8));
    }
    if (!is_string($tar) || $tar === '') {
        return false;
    }
    return db_sync_tar_extract($tar, '_dbsync.php');
}

/* Minimalny czytnik tar (ustar/pax) - zwraca zawartosc pierwszego pliku,
   ktorego nazwa konczy sie na $suffix, albo false. */
function db_sync_tar_extract($tar, $suffix)
{
    $pos  = 0;
    $size = strlen($tar);
    while ($pos + 512 <= $size) {
        $header = substr($tar, $pos, 512);
        $name   = rtrim(substr($header, 0, 100), "\0");
        if ($name === '') {
            break; // blok zerowy = koniec archiwum
        }
        $sizeField = trim(substr($header, 124, 12), "\0 ");
        $fileSize  = $sizeField === '' ? 0 : (int) octdec($sizeField);
        $typeFlag  = substr($header, 156, 1);
        $prefix    = rtrim(substr($header, 345, 155), "\0");
        if ($prefix !== '') {
            $name = $prefix . '/' . $name;
        }
        $pos += 512;
        if (($typeFlag === '0' || $typeFlag === "\0" || $typeFlag === '')
            && substr($name, -strlen($suffix)) === $suffix
        ) {
            return substr($tar, $pos, $fileSize);
        }
        $pos += (int) (ceil($fileSize / 512) * 512);
    }
    return false;
}

/* Czy pobrany skrypt jest identyczny z lokalnym plikiem (CRLF/LF bez znaczenia).
   Sluzy do wykrycia zmian na GitHub bez podbicia DBSYNC_VERSION. */
function db_sync_body_same_as_local($body)
{
    $local = @file_get_contents(__FILE__);
    if ($local === false) {
        return true; // brak mozliwosci porownania - nie strasz uzytkownika
    }
    $eol = array("\r\n", "\r");
    return md5(str_replace($eol, "\n", $local)) === md5(str_replace($eol, "\n", $body));
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
            $site = db_sync_archive_site_key($creds);
            if (isset($_POST['exclude_defaults'])) {
                // przycisk "Ustaw domyslne": zapis listy domyslnej bez archiwum
                db_sync_archive_exclude_reset($site);
                $showArchiveForm = true;
            } else {
                $format   = isset($_POST['format']) ? trim($_POST['format']) : '';
                $patterns = db_sync_archive_exclude_clean(isset($_POST['exclude']) ? $_POST['exclude'] : '');
                db_sync_archive_exclude_save($site, $patterns);
                db_sync_archive_create($format, $patterns, $creds);
            }
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
        $body   = db_sync_remote_script();
        $latest = db_sync_remote_version($body);
        if ($latest === '') {
            throw new Exception('Pobrany z GitHub (main) plik nie zawiera stalej DBSYNC_VERSION.');
        }
        $updUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?action=update';
        $updBtn = '<a href="' . $updUrl . '" onclick="return confirm(\'Pobrac i zainstalowac wersje ' . htmlspecialchars($latest) . '?\\nObecny plik zostanie ZASTAPIONY bez kopii zapasowej.\\n\')" '
            . 'style="font-family:Consolas,monospace;font-size:13px;padding:6px 14px;background:#080;color:#fff;border:0;border-radius:4px;text-decoration:none;cursor:pointer">[UPDATE]</a>';
        if (version_compare($latest, DBSYNC_VERSION, '>')) {
            db_sync_log('AKTUALIZACJA', 'Dostepna nowsza wersja: ' . $latest . ' (obecna: ' . DBSYNC_VERSION . ', zrodlo: GitHub main)');
            echo '<div style="font-family:Consolas,monospace;font-size:14px;padding:10px 14px;margin:8px 0;border:1px solid #0a0;background:#eaffea;border-radius:4px">'
                . '<b>Dostepna aktualizacja do wersji ' . htmlspecialchars($latest) . '</b> ' . $updBtn
                . '</div>' . "\n";
        } elseif (version_compare($latest, DBSYNC_VERSION, '<')) {
            db_sync_log('AKTUALIZACJA', 'GitHub main ma starsza wersje (' . $latest . ') niz lokalna (' . DBSYNC_VERSION . ') - lokalny plik jest nowszy (wydanie nie wypchniete?).'
                . ' Jezeli wydanie wlasnie zostalo wypchniete, GitHub moze jeszcze przez chwile zwracac starsze archiwum - sprawdz za minute.');
        } elseif (db_sync_body_same_as_local($body)) {
            db_sync_log('AKTUALIZACJA', 'Brak nowszej wersji (GitHub main: ' . $latest . ', obecna: ' . DBSYNC_VERSION . ')');
        } else {
            db_sync_log('AKTUALIZACJA', 'Wersja na GitHub main (' . $latest . ') = lokalna, ale plik rozni sie od lokalnego (zmiany bez podbicia DBSYNC_VERSION?).');
            echo '<div style="font-family:Consolas,monospace;font-size:14px;padding:10px 14px;margin:8px 0;border:1px solid #e90;background:#fff8e1;border-radius:4px">'
                . '<b>Uwaga:</b> na GitHub (main) jest ten sam numer wersji (' . htmlspecialchars($latest) . '), ale inna zawartosc pliku. ' . $updBtn
                . '</div>' . "\n";
        }
    } elseif ($action === 'update') {
        $body = db_sync_remote_script();
        $newVersion = db_sync_remote_version($body);
        if ($newVersion === '') {
            throw new Exception('Pobrany plik nie zawiera stalej DBSYNC_VERSION - nie podmieniam.');
        }
        if (version_compare($newVersion, DBSYNC_VERSION, '<')) {
            throw new Exception('Pobrana wersja ' . $newVersion . ' jest starsza niz zainstalowana ' . DBSYNC_VERSION . ' - nie podmieniam.'
                . ' Jezeli wydanie wlasnie zostalo wypchniete, GitHub moze jeszcze przez chwile zwracac starsze archiwum - sprobuj za minute.');
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
