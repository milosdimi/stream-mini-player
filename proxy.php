<?php
// proxy.php - simple HLS-friendly proxy (personal/dev use)
// - Proxies any http/https URL
// - Rewrites .m3u8 so segments/keys also go through this proxy
// - Adds CORS headers so browser + hls.js can fetch

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Expose-Headers: Content-Type, Content-Length, Accept-Ranges, Content-Range');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function starts_with(string $haystack, string $needle): bool {
  if ($needle === '') return true;
  return substr($haystack, 0, strlen($needle)) === $needle;
}

$url = $_GET['url'] ?? '';
$url = trim($url);

if (!$url) {
  http_response_code(400);
  echo "Missing ?url=";
  exit;
}

if (!preg_match('~^https?://~i', $url)) {
  http_response_code(400);
  echo "Only http/https allowed";
  exit;
}

// Basic SSRF guard: block localhost/private ranges
$parts = parse_url($url);
$host = $parts['host'] ?? '';
if (!$host) {
  http_response_code(400);
  echo "Invalid URL";
  exit;
}

// If host is an IP, block private ranges
if (filter_var($host, FILTER_VALIDATE_IP)) {
  if (
    preg_match('~^(10\.|127\.|169\.254\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)~', $host)
  ) {
    http_response_code(403);
    echo "Blocked host";
    exit;
  }
}

function curl_fetch(string $url): array {
  if (!function_exists('curl_init')) {
    return ['ok' => false, 'status' => 500, 'headers' => [], 'body' => 'cURL extension is not enabled on this server'];
  }

  $ch = curl_init($url);

  $headers = [
    'User-Agent: Mozilla/5.0',
    'Accept: */*',
    'Connection: keep-alive',
  ];

  // Forward Range for TS segments (helps seeking / smoother playback)
  if (!empty($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
  }

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_HEADER => true,
    CURLOPT_SSL_VERIFYPEER => false, // some IPTV hosts have broken TLS
    CURLOPT_SSL_VERIFYHOST => 0,
  ]);

  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => false, 'status' => 502, 'headers' => [], 'body' => $err];
  }

  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  $rawHeaders = substr($resp, 0, $headerSize);
  $body = substr($resp, $headerSize);

  $outHeaders = [];
  foreach (explode("\r\n", $rawHeaders) as $line) {
    $pos = strpos($line, ':');
    if ($pos !== false) {
      $k = strtolower(trim(substr($line, 0, $pos)));
      $v = trim(substr($line, $pos + 1));
      if ($k) $outHeaders[$k] = $v;
    }
  }

  return ['ok' => true, 'status' => $status, 'headers' => $outHeaders, 'body' => $body];
}

function is_m3u8(string $url, array $headers): bool {
  if (preg_match('~\.m3u8(\?|$)~i', $url)) return true;
  $ct = strtolower($headers['content-type'] ?? '');
  return (strpos($ct, 'application/vnd.apple.mpegurl') !== false) ||
         (strpos($ct, 'application/x-mpegurl') !== false) ||
         (strpos($ct, 'audio/mpegurl') !== false);
}

function proxy_url(string $absUrl): string {
  $self = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/proxy.php';
  return $self . '://' . $host . $scriptPath . '?url=' . rawurlencode($absUrl);
}

function absolutize(string $line, string $baseUrl): string {
  // Already absolute
  if (preg_match('~^https?://~i', $line)) return $line;

  $b = parse_url($baseUrl);
  $scheme = $b['scheme'] ?? 'http';
  $host = $b['host'] ?? '';
  $port = isset($b['port']) ? ':' . $b['port'] : '';

  // Root-relative
  if (starts_with($line, '/')) {
    return $scheme . '://' . $host . $port . $line;
  }

  // Relative
  $dir = preg_replace('~[^/]+$~', '', $baseUrl); // strip file part
  return $dir . $line;
}

$resp = curl_fetch($url);
http_response_code($resp['status']);

if (!$resp['ok']) {
  header('Content-Type: text/plain; charset=utf-8');
  echo $resp['body'];
  exit;
}

$headers = $resp['headers'];
$body = $resp['body'];

if (is_m3u8($url, $headers)) {
  // Rewrite playlist so every segment/key goes through proxy
  $lines = preg_split("~\r?\n~", $body);
  $out = [];

  foreach ($lines as $line) {
    $trim = trim($line);

    // Rewrite EXT-X-KEY URI="..."
    if (stripos($trim, '#EXT-X-KEY:') === 0 && preg_match('~URI="([^"]+)"~i', $trim, $m)) {
      $keyUrlAbs = absolutize($m[1], $url);
      $proxied = proxy_url($keyUrlAbs);
      $trim = preg_replace('~URI="[^"]+"~i', 'URI="' . $proxied . '"', $trim);
      $out[] = $trim;
      continue;
    }

    // Normal comments / empty lines
    if ($trim === '' || starts_with($trim, '#')) {
      $out[] = $trim;
      continue;
    }

    // Segment/variant URI
    $abs = absolutize($trim, $url);
    $out[] = proxy_url($abs);
  }

  header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
  echo implode("\n", $out);
  exit;
}

// Non-m3u8: passthrough (ts, mp4, images, etc.)
$ct = $headers['content-type'] ?? 'application/octet-stream';
header('Content-Type: ' . $ct);

// Forward helpful headers if present
if (isset($headers['accept-ranges'])) header('Accept-Ranges: ' . $headers['accept-ranges']);
if (isset($headers['content-range'])) header('Content-Range: ' . $headers['content-range']);
if (isset($headers['content-length'])) header('Content-Length: ' . $headers['content-length']);

echo $body;
