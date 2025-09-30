<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use App\LaximoClient;

require __DIR__.'/../vendor/autoload.php';

ini_set('log_errors', '1');
ini_set('error_log', 'php://stdout'); // всё улетит в Railway Runtime Logs


$dotenv = Dotenv::createImmutable(dirname(__DIR__));
if (file_exists(dirname(__DIR__).'/.env')) {
    $dotenv->load();
}

header('Content-Type: application/json; charset=utf-8');

$login = $_ENV['LAXIMO_LOGIN']    ?? getenv('LAXIMO_LOGIN')    ?: '';
$pass  = $_ENV['LAXIMO_PASSWORD'] ?? getenv('LAXIMO_PASSWORD') ?: '';


if (!$login || !$pass) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Laximo credentials missing (LAXIMO_LOGIN/LAXIMO_PASSWORD)']);
    exit;
}

$client = new LaximoClient($login, $pass);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

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


try {
    if ($path === '/vin') {
        $vin = trim($_GET['vin'] ?? '');
        if ($vin === '') throw new RuntimeException('vin required');
        $data = $client->findByVin($vin);
        echo json_encode(['ok'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE);

    } elseif ($path === '/oem') {
        $article = trim($_GET['article'] ?? '');
        $brand   = trim($_GET['brand'] ?? '');
        if ($article === '') throw new RuntimeException('article required');
        $data = $client->findOem($article, $brand ?: null);
        echo json_encode(['ok'=>true, 'data'=>$data], JSON_UNESCAPED_UNICODE);

    } elseif ($path === '/diag') {
        // Диагностика OEM-доступа и окружения
        $oem = new \GuayaquilLib\ServiceOem($login, $pass);
        $cats = $oem->listCatalogs();
        $count = is_array($cats) ? count($cats) : 0;

        // Запишем в логи Railway для наглядности
        error_log('diag: listCatalogs count=' . $count);

        echo json_encode([
            'ok' => true,
            'service' => 'laximo',
            'php' => PHP_VERSION,
            'soap' => extension_loaded('soap'),
            'login_set' => (bool)$login,
            'catalogs_count' => $count,
            'catalogs' => $cats, // список доступных OEM-каталогов
        ], JSON_UNESCAPED_UNICODE);

    } else {
        echo json_encode(['ok'=>true, 'service'=>'laximo']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
