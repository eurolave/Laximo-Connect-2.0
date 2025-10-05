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
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
if ($path !== '/telegram/webhook' && ($login === '' || $pass === '')) {
    fail('Laximo credentials missing (LAXIMO_LOGIN/LAXIMO_PASSWORD)', 500);
}

/**
 * ─────────────────────────── App client ───────────────────────────
 */
$client = ($path === '/telegram/webhook' && ($login === '' || $pass === ''))
    ? null
    : new LaximoClient($login, $pass);

/**
 * ─────────────────── Existing JSON API routes ────────────────────
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
        }
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

    /**
     * ───────────────────────── Telegram webhook ─────────────────────────
     */
    } elseif ($path === '/telegram/webhook') {

        // ─── Telegram webhook: config ────────────────────────────────────
        $TG_TOKEN  = $_ENV['TG_TOKEN'] ?? getenv('TG_TOKEN') ?: '';
        $CACHE_DIR = $_ENV['TG_CACHE_DIR'] ?? getenv('TG_CACHE_DIR') ?: sys_get_temp_dir().'/tg_units_cache';

        // Разрешаем пустые Laximo креды в чисто телеграм-ветке? Нет – ответим 500.
        if ($login === '' || $pass === '') {
            http_response_code(500);
            echo 'LAXIMO creds missing';
            exit;
        }
        if ($TG_TOKEN === '') {
            http_response_code(500);
            echo 'TG_TOKEN missing';
            exit;
        }

        // ─── Telegram webhook: util ─────────────────────────────────────
        $tg_api = static function (string $method, array $data) use ($TG_TOKEN): array {
            $ch = curl_init("https://api.telegram.org/bot{$TG_TOKEN}/{$method}");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                error_log("Telegram API error: $err");
                return [];
            }
            $j = json_decode($resp, true) ?: [];
            if (!($j['ok'] ?? false)) {
                error_log("Telegram API response not ok: ".$resp);
            }
            return $j;
        };

        $ensure_dir = static function (string $d): void {
            if (!is_dir($d)) @mkdir($d, 0777, true);
        };
        $cache_key = static function (int|string $chatId, int|string $msgId) use ($CACHE_DIR): string {
            return rtrim($CACHE_DIR, '/')."/{$chatId}_{$msgId}.json";
        };
        $cache_put = static function (int|string $chatId, int|string $msgId, array $state) use ($CACHE_DIR, $ensure_dir, $cache_key): void {
            $ensure_dir($CACHE_DIR);
            file_put_contents($cache_key($chatId,$msgId), json_encode($state, JSON_UNESCAPED_UNICODE));
        };
        $cache_get = static function (int|string $chatId, int|string $msgId) use ($cache_key): ?array {
            $f = $cache_key($chatId,$msgId);
            if (!is_file($f)) return null;
            $j = json_decode(file_get_contents($f), true);
            return is_array($j) ? $j : null;
        };
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $img_source = static fn(string $imageUrl): string => str_replace('%size%', 'source', $imageUrl);

        $kb_units = static function (array $units): array {
            $rows = [];
            foreach ($units as $i => $u) {
                $title = ($u['code'] ?? '#').' · '.mb_strimwidth($u['name'] ?? '', 0, 40, '…', 'UTF-8');
                $rows[] = [[ 'text' => $title, 'callback_data' => "unit:$i" ]];
            }
            return ['inline_keyboard' => $rows];
        };
        $kb_card = static function (int $idx, int $count, string $imgUrl): array {
            $rows = [];
            $rows[] = [[ 'text' => '🖼 Открыть узел', 'url' => $imgUrl ]];
            $nav = [];
            if ($idx > 0)          $nav[] = [ 'text' => '⬅️ Пред', 'callback_data' => 'unit:'.($idx-1) ];
            if ($idx < $count - 1) $nav[] = [ 'text' => '➡️ След', 'callback_data' => 'unit:'.($idx+1) ];
            if ($nav) $rows[] = $nav;
            $rows[] = [[ 'text' => '↩️ К списку', 'callback_data' => 'units:list' ]];
            return ['inline_keyboard' => $rows];
        };

        $raw = file_get_contents('php://input');
        if (!$raw) {
            http_response_code(200);
            echo 'OK';
            exit;
        }
        $update = json_decode($raw, true) ?: [];

        // ─── /units команда ─────────────────────────────────────────────
        if (isset($update['message']['text'])) {
            $msg    = $update['message'];
            $chatId = (int)$msg['chat']['id'];
            $text   = (string)$msg['text'];

            if (preg_match('~^/units\b~ui', $text)) {
                preg_match_all('~(\w+)=([^\s]+)~', $text, $m, PREG_SET_ORDER);
                $args = [];
                foreach ($m as $p) $args[strtolower($p[1])] = $p[2];

                $catalog    = $args['catalog']   ?? '';
                $vehicleId  = $args['vehicleid'] ?? '0';
                $ssd        = $args['ssd']       ?? '';
                $categoryId = $args['categoryid']?? '';

                if ($catalog === '' || $ssd === '' || $categoryId === '') {
                    $tg_api('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "Нужно так:\n/units catalog=AU1587 vehicleId=0 ssd=... categoryId=1",
                    ]);
                    http_response_code(200);
                    echo 'OK';
                    exit;
                }

                // Вызываем родной клиент, без HTTP-обхода
                try {
                    /** @var LaximoClient $client */
                    $data  = $client->listUnits($catalog, $vehicleId, $ssd, (string)$categoryId);
                } catch (Throwable $e) {
                    $tg_api('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "Ошибка API: ".$e->getMessage(),
                    ]);
                    http_response_code(200);
                    echo 'OK';
                    exit;
                }

                $units = $data[0]['units'] ?? [];
                if (!$units) {
                    $tg_api('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "Узлы не найдены.",
                    ]);
                    http_response_code(200);
                    echo 'OK';
                    exit;
                }

                $sent = $tg_api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "🔧 Узлы",
                    'reply_markup' => $kb_units($units),
                ]);
                $messageId = $sent['result']['message_id'] ?? null;
                if ($messageId) {
                    $cache_put($chatId, $messageId, [
                        'units' => $units,
                    ]);
                }
                http_response_code(200);
                echo 'OK';
                exit;
            }

            http_response_code(200);
            echo 'OK';
            exit;
        }

        // ─── callback_query (навигация/открытие) ────────────────────────
        if (isset($update['callback_query'])) {
            $cb        = $update['callback_query'];
            $data      = (string)($cb['data'] ?? '');
            $msg       = $cb['message'] ?? [];
            $chatId    = (int)($msg['chat']['id'] ?? 0);
            $messageId = (int)($msg['message_id'] ?? 0);

            $state = $cache_get($chatId, $messageId);
            $units = $state['units'] ?? [];

            if (!$units) {
                $tg_api('answerCallbackQuery', [
                    'callback_query_id' => $cb['id'],
                    'text' => 'Сессия устарела — отправьте /units ещё раз.',
                    'show_alert' => false,
                ]);
                http_response_code(200);
                echo 'OK';
                exit;
            }

            if ($data === 'units:list') {
                $tg_api('editMessageText', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => "🔧 Узлы",
                    'reply_markup' => $kb_units($units),
                ]);
                $tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
                http_response_code(200);
                echo 'OK';
                exit;
            }

            if (preg_match('~^unit:(\d+)$~', $data, $m)) {
                $idx = (int)$m[1];
                if (!isset($units[$idx])) {
                    $tg_api('answerCallbackQuery', [
                        'callback_query_id' => $cb['id'],
                        'text' => 'Узел не найден',
                        'show_alert' => false,
                    ]);
                    http_response_code(200);
                    echo 'OK';
                    exit;
                }
                $u    = $units[$idx];
                $code = $esc($u['code'] ?? '');
                $name = $esc($u['name'] ?? '');
                $img  = $img_source((string)($u['imageUrl'] ?? ''));

                $tg_api('editMessageText', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => "📦 <b>{$code}</b>\n{$name}",
                    'parse_mode' => 'HTML',
                    'reply_markup' => $kb_card($idx, count($units), $img),
                ]);
                $tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
                http_response_code(200);
                echo 'OK';
                exit;
            }

            $tg_api('answerCallbackQuery', [
                'callback_query_id' => $cb['id'],
                'text' => 'Неизвестная команда',
                'show_alert' => false,
            ]);
            http_response_code(200);
            echo 'OK';
            exit;
        }

        http_response_code(200);
        echo 'OK';
        exit;

    } else {
        ok(['service' => 'laximo']);
    }
} catch (Throwable $e) {
    fail($e->getMessage(), 400);
}
