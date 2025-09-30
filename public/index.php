<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use App\LaximoClient;

require __DIR__.'/../vendor/autoload.php';

ini_set('log_errors', '1');
ini_set('error_log', 'php://stdout'); // всё улетит в Railway Runtime Logs

// (опционально CORS, убери если не нужно фронту)
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Methods: GET, OPTIONS');
// if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
if (file_exists(dirname(__DIR__).'/.env')) {
    $dotenv->load();
}

header('Content-Type: application/json; charset=utf-8');

// 🔧 СНАЧАЛА определяем $path, чтобы использовать его ниже
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Читаем креды (и через $_ENV, и через getenv)
$login = $_ENV['LAXIMO_LOGIN']    ?? getenv('LAXIMO_LOGIN')    ?: '';
$pass  = $_ENV['LAXIMO_PASSWORD'] ?? getenv('LAXIMO_PASSWORD') ?: '';

// ─── Диагностика до создания клиента ───────────────────────────────────────────
if ($path === '/_diag/creds') {
  echo json_encode([
    'login_len' => strlen($login),
    'pass_len'  => strlen($pass),
    'login_mask'=> substr($login,0,2).'***'.substr($login,-2),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($path === '/_diag/login') {
    try {
        $oem = new \GuayaquilLib\ServiceOem($login, $pass);
        $cats = $oem->listCatalogs(); // простой ping
        echo json_encode([
          'ok'=>true,
          'catalogs_count'=>is_array($cats)?count($cats):0,
          'data'=>$cats
        ], JSON_UNESCAPED_UNICODE);
    } catch (\GuayaquilLib\exceptions\AccessDeniedException $e) {
        http_response_code(401);
        echo json_encode(['ok'=>false, 'code'=>'E_ACCESSDENIED', 'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (\GuayaquilLib\exceptions\TooManyRequestsException $e) {
        http_response_code(429);
        echo json_encode(['ok'=>false, 'code'=>'E_TOO_MANY_REQUESTS', 'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'code'=>'UNKNOWN', 'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($path === '/_echo') {
    echo json_encode(['path' => $path, 'query' => $_GET], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/_diag/ping-ws') {
    $host = 'ws.laximo.ru';
    $ip = gethostbyname($host);

    $tcp_ok = false;
    $err = null;
    $t0 = microtime(true);
    $fp = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT);
    if ($fp) {
        $tcp_ok = true;
        fclose($fp);
    } else {
        $err = "{$errno}: {$errstr}";
    }
    $ms = (int)((microtime(true) - $t0) * 1000);

    echo json_encode([
        'host' => $host,
        'resolved_ip' => $ip,
        'tcp_443' => $tcp_ok,
        'latency_ms' => $ms,
        'error' => $err,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// health (удобно для мониторинга)
if ($path === '/health') {
    echo json_encode(['ok' => true, 'php' => PHP_VERSION]);
    exit;
}

// Если кредов нет — сразу ошибка
if (!$login || !$pass) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Laximo credentials missing (LAXIMO_LOGIN/LAXIMO_PASSWORD)']);
    exit;
}

// Создаём клиент
$client = new LaximoClient($login, $pass);

// ─── Бизнес-маршруты ───────────────────────────────────────────────────────────
try {
    if ($path === '/vin') {
        $vin = trim($_GET['vin'] ?? '');
        if ($vin === '') throw new \RuntimeException('vin required');
        $data = $client->findByVin($vin);
        echo json_encode(['ok'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE);

    } elseif ($path === '/oem') {
        $article = trim($_GET['article'] ?? '');
        $brand   = trim($_GET['brand'] ?? '');
        if ($article === '') throw new \RuntimeException('article required');
        $data = $client->findOem($article, $brand ?: null);
        echo json_encode(['ok'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE);

    } elseif ($path === '/diag') {
        // Диагностика OEM-доступа и окружения
        $oem = new \GuayaquilLib\ServiceOem($login, $pass);
        $cats = $oem->listCatalogs();
        $count = is_array($cats) ? count($cats) : 0;

        error_log('diag: listCatalogs count=' . $count);

        echo json_encode([
            'ok' => true,
            'service' => 'laximo',
            'php' => PHP_VERSION,
            'soap' => extension_loaded('soap'),
            'login_set' => (bool)$login,
            'catalogs_count' => $count,
            'catalogs' => $cats,
        ], JSON_UNESCAPED_UNICODE);

    } else {
        echo json_encode(['ok'=>true, 'service'=>'laximo']);
    }
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
