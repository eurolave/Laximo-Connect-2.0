<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use App\LaximoClient;

require __DIR__.'/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
if (file_exists(dirname(__DIR__).'/.env')) {
    $dotenv->load();
}

header('Content-Type: application/json; charset=utf-8');

$login = $_ENV['LAXIMO_LOGIN'] ?? '';
$pass  = $_ENV['LAXIMO_PASSWORD'] ?? '';

if (!$login || !$pass) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Laximo credentials missing (LAXIMO_LOGIN/LAXIMO_PASSWORD)']);
    exit;
}

$client = new LaximoClient($login, $pass);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

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
    } else {
        echo json_encode(['ok'=>true, 'service'=>'laximo']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
