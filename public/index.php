<?php
declare(strict_types=1);

use App\LaximoClient;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

/**
 * ────────────────────────────── Boot ──────────────────────────────
 */
ini_set('log_errors', '1');
ini_set('error_log', 'php://stdout');
header('Content-Type: application/json; charset=utf-8');

const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

/** tiny helpers */
function respond(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_FLAGS);
    exit;
}
function ok(array $payload = []): never {
    respond(['ok' => true] + $payload, 200);
}
function fail(string $message, int $code = 400, array $extra = []): never {
    respond(['ok' => false, 'error' => $message] + $extra, $code);
}
function get_path(): string {
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return is_string($p) ? $p : '/';
}
function q(string $key, ?string $default = null): ?string {
    $val = $_GET[$key] ?? $default;
    if ($val === null) return null;
    return trim((string)$val);
}

/**
 * ──────────────────────── Optional CORS (env flag) ────────────────────────
 */
if (($_ENV['ENABLE_CORS'] ?? getenv('ENABLE_CORS') ?: '') === '1') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * ─────────────────────── Load .env if present ───────────────────────
 */
try {
    $root = dirname(__DIR__);
    if (is_file($root . '/.env')) {
        Dotenv::createImmutable($root)->load();
    }
} catch (Throwable $e) {
    error_log('dotenv load warning: ' . $e->getMessage());
}

/**
 * ──────────────────────── Read credentials ────────────────────────
 */
$login = $_ENV['LAXIMO_LOGIN']    ?? getenv('LAXIMO_LOGIN')    ?: '';
$pass  = $_ENV['LAXIMO_PASSWORD'] ?? getenv('LAXIMO_PASSWORD') ?: '';

$path = get_path();

/**
 * ─────────────────────── Diagnostic routes ───────────────────────
 */
if ($path === '/_diag/creds') {
    $mask = static function (string $s): string {
        $len = strlen($s);
        return $len <= 4 ? str_repeat('*', $len) : (substr($s, 0, 2) . '***' . substr($s, -2));
    };
    ok([
        'login_len'  => strlen($login),
        'pass_len'   => strlen($pass),
        'login_mask' => $mask($login),
    ]);
}

if ($path === '/_diag/login') {
    try {
        $oem  = new \GuayaquilLib\ServiceOem($login, $pass);
        $cats = $oem->listCatalogs(); // ping
        ok([
            'catalogs_count' => is_array($cats) ? count($cats) : 0,
            'data'           => $cats,
        ]);
    } catch (\GuayaquilLib\exceptions\AccessDeniedException $e) {
        fail($e->getMessage(), 401, ['code' => 'E_ACCESSDENIED']);
    } catch (\GuayaquilLib\exceptions\TooManyRequestsException $e) {
        fail($e->getMessage(), 429, ['code' => 'E_TOO_MANY_REQUESTS']);
    } catch (Throwable $e) {
        fail($e->getMessage(), 500, ['code' => 'UNKNOWN']);
    }
}

if ($path === '/_echo') {
    ok(['path' => $path, 'query' => $_GET]);
}

if ($path === '/_diag/ping-ws') {
    $host = 'ws.laximo.ru';
    $ip   = gethostbyname($host);

    $tcpOk = false;
    $err   = null;
    $t0    = microtime(true);
    $fp    = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT);
    if ($fp) {
        $tcpOk = true;
        fclose($fp);
    } else {
        $err = "{$errno}: {$errstr}";
    }
    $ms = (int) ((microtime(true) - $t0) * 1000);

    respond([
        'host'        => $host,
        'resolved_ip' => $ip,
        'tcp_443'     => $tcpOk,
        'latency_ms'  => $ms,
        'error'       => $err,
    ], 200);
}

if ($path === '/health') {
    ok(['php' => PHP_VERSION]);
}

/**
 * ─────────────────── Guard: credentials present ───────────────────
 */
if ($login === '' || $pass === '') {
    fail('Laximo credentials missing (LAXIMO_LOGIN/LAXIMO_PASSWORD)', 500);
}

/**
 * ─────────────────────────── App client ───────────────────────────
 */
$client = new LaximoClient($login, $pass);

/**
 * ─────────────────────────── Routes ───────────────────────────
 */
try {
    if ($path === '/vin') {
        $vin    = q('vin', '');
        $locale = q('locale', 'ru_RU') ?? 'ru_RU';
        if ($vin === '') {
            fail('vin required', 400);
        }
        $data = $client->findVehicleByVin($vin, $locale);
        ok(['data' => $data, 'vin' => $vin, 'locale' => $locale]);

    } elseif ($path === '/applicable') {
        $catalog = q('catalog', '');
        $oem     = q('oem', '');
        $locale  = q('locale', 'ru_RU') ?? 'ru_RU';

        if ($catalog === '' || $oem === '') {
            fail('catalog and oem are required', 400);
        }

        $data = $client->findApplicableVehicles($catalog, $oem, $locale);
        ok([
            'catalog' => $catalog,
            'oem'     => $oem,
            'locale'  => $locale,
            'data'    => $data,
        ]);

    } elseif ($path === '/categories') {
        $catalog   = q('catalog', '');
        $vehicleId = q('vehicleId', '0') ?? '0';
        $ssd       = q('ssd', '');
        $all       = q('all'); // ?all=1 -> полный список

        if ($catalog === '' || $ssd === '') {
            fail('catalog and ssd are required', 400);
        }

        if ($all === '1') {
            $data = $client->listCategoriesAll($catalog, $vehicleId, $ssd);
        } else {
            $data = $client->listCategories($catalog, $vehicleId, $ssd);
        }

        ok([
            'catalog'   => $catalog,
            'vehicleId' => $vehicleId,
            'data'      => $data,
        ]);

    } elseif ($path === '/units') {
        $catalog    = q('catalog', '');
        $vehicleId  = q('vehicleId', '0') ?? '0';
        $ssd        = q('ssd', '');
        $categoryId = q('categoryId', null);

        if ($catalog === '' || $ssd === '') {
            fail('catalog and ssd are required', 400);
        } elseif ($path === '/unit') {
        $catalog   = q('catalog', '');
        $vehicleId = q('vehicleId', '0') ?? '0';
        $ssd       = q('ssd', '');
        $locale    = q('locale', 'ru_RU') ?? 'ru_RU';

        if ($catalog === '' || $ssd === '') {
            fail('catalog and ssd are required', 400);
        }

        // Получаем ИНФО по узлу и СПИСОК деталей по ssd узла
        $data = $client->getUnitBySsd($catalog, $vehicleId, $ssd, $locale);

        ok([
            'catalog'   => $catalog,
            'vehicleId' => $vehicleId,
            'ssd'       => $ssd,
            'locale'    => $locale,
            'data'      => $data,
        ]);



        
        // Требуем непустую строку; без приведения к int!
        if ($categoryId === null || $categoryId === '') {
            fail('categoryId is required (string)', 400);
        }

        $categoryId = (string)$categoryId;
        $data = $client->listUnits($catalog, $vehicleId, $ssd, $categoryId);

        ok([
            'catalog'    => $catalog,
            'vehicleId'  => $vehicleId,
            'categoryId' => $categoryId,
            'data'       => $data,
        ]);

    } elseif ($path === '/oem') {
        $article = q('article', '');
        $brand   = q('brand'); // may be null/empty

        if ($article === '') {
            fail('article required', 400);
        }

        $data = $client->findOem($article, $brand ?: null);
        ok([
            'data'    => $data,
            'article' => $article,
            'brand'   => $brand,
        ]);

    } elseif ($path === '/diag') {
        $oem   = new \GuayaquilLib\ServiceOem($login, $pass);
        $cats  = $oem->listCatalogs();
        $count = is_array($cats) ? count($cats) : 0;

        error_log('diag: listCatalogs count=' . $count);

        ok([
            'service'        => 'laximo',
            'php'            => PHP_VERSION,
            'soap'           => extension_loaded('soap'),
            'login_set'      => (bool) $login,
            'catalogs_count' => $count,
            'catalogs'       => $cats,
        ]);

    } else {
        ok(['service' => 'laximo']);
    }
} catch (Throwable $e) {
    fail($e->getMessage(), 400);
}
