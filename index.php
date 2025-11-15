<?php
/*******************************************
 * HLS Proxy with Token Security - Render
 * Author: ChatGPT
 *******************************************/

// ========== إعداداتك الخاصة ==========
$VALID_TOKEN = "MY_SECURE_TOKEN";   // ضع توكن خاص بك هنا
// ======================================

// CORS
function send_cors_headers() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Range, X-Requested-With, Content-Type, Authorization');
    header('Access-Control-Expose-Headers: Content-Length, Content-Range');
}
send_cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// ======= حماية التوكن =======
if (!isset($_GET['token']) || $_GET['token'] !== $VALID_TOKEN) {
    http_response_code(403);
    die("Forbidden: invalid token");
}

// ======= التحقق من رابط البث =======
if (!isset($_GET['url'])) {
    http_response_code(400);
    die("Bad Request: missing url");
}
$target = trim($_GET['url']);

// منع روابط غير HTTP
if (!preg_match('#^https?://#', $target)) {
    http_response_code(400);
    die("Invalid URL");
}

// وظيفة مساعدة لحل الروابط النسبية
function resolve_url($base, $rel) {
    if (parse_url($rel, PHP_URL_SCHEME)) return $rel;
    if (substr($rel, 0, 2) === '//') {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return "$scheme:$rel";
    }
    $parts = parse_url($base);
    $scheme = $parts['scheme'];
    $host = $parts['host'];
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = isset($parts['path']) ? $parts['path'] : '/';
    if ($rel[0] === '/') return "$scheme://$host$port$rel";
    $path = preg_replace('#/[^/]*$#', '/', $path);
    return "$scheme://$host$port$path$rel";
}

// جلب الملف عبر cURL
function curl_fetch($url, $forwardRange = true) {
    $headers = [];
    if ($forwardRange && isset($_SERVER['HTTP_RANGE'])) {
        $headers[] = "Range: " . $_SERVER['HTTP_RANGE'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    return [$resp, $info];
}

// هل الملف Playlist؟
$isPlaylist = (str_ends_with(strtolower($target), ".m3u8"));

list($raw, $info) = curl_fetch($target);
$headerSize = $info["header_size"];
$headers = substr($raw, 0, $headerSize);
$body = substr($raw, $headerSize);

// إذا قائمة تشغيل → أعد كتابة كل الروابط
if ($isPlaylist) {
    header("Content-Type: application/vnd.apple.mpegurl");

    $lines = explode("\n", $body);
    $output = [];

    foreach ($lines as $line) {
        $trim = trim($line);

        if ($trim === "" || str_starts_with($trim, "#")) {
            $output[] = $line;
            continue;
        }

        // حل الروابط النسبية
        $abs = resolve_url($target, $trim);

        // إعادة كتابة الرابط لكي يعود للبروكسي
        $self = "https://".$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'];
        $proxy = $self . "?token=" . $VALID_TOKEN . "&url=" . urlencode($abs);

        $output[] = $proxy;
    }

    echo implode("\n", $output);
    exit;
}

// ملفات TS – أرسلها كما هي مع headers مناسبة
foreach (explode("\n", $headers) as $h) {
    if (stripos($h, ":") !== false) header($h);
}
echo $body;
exit;
