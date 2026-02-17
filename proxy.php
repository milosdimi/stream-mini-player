<?php
// proxy.php - low-memory streaming proxy (cPanel/PHP5+ compatible)

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Expose-Headers: Content-Type, Content-Length, Accept-Ranges, Content-Range, X-Proxy-Error');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  send_status(204);
  exit;
}

function send_status($code) {
  $code = (int)$code;
  if (function_exists('http_response_code')) {
    http_response_code($code);
    return;
  }
  header('X-PHP-Response-Code: ' . $code, true, $code);
}

function starts_with($haystack, $needle) {
  $haystack = (string)$haystack;
  $needle = (string)$needle;
  if ($needle === '') return true;
  return substr($haystack, 0, strlen($needle)) === $needle;
}

function arr_get($arr, $key, $default) {
  return (is_array($arr) && array_key_exists($key, $arr)) ? $arr[$key] : $default;
}

function deny($status, $msg) {
  $safe = preg_replace('~[\r\n]+~', ' ', (string)$msg);
  if ($safe !== '') {
    header('X-Proxy-Error: ' . substr($safe, 0, 220));
  }
  send_status($status);
  header('Content-Type: text/plain; charset=utf-8');
  echo $msg;
  exit;
}

function proxy_url($absUrl) {
  $https = isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : '';
  $self = ($https && $https !== 'off') ? 'https' : 'http';
  $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
  $scriptPath = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/proxy.php';
  return $self . '://' . $host . $scriptPath . '?url=' . rawurlencode($absUrl);
}

function absolutize($line, $baseUrl) {
  if (preg_match('~^https?://~i', $line)) return $line;

  $b = parse_url($baseUrl);
  $scheme = arr_get($b, 'scheme', 'http');
  $host = arr_get($b, 'host', '');
  $port = isset($b['port']) ? ':' . $b['port'] : '';

  if (starts_with($line, '/')) {
    return $scheme . '://' . $host . $port . $line;
  }

  $dir = preg_replace('~[^/]+$~', '', $baseUrl);
  return $dir . $line;
}

function should_rewrite_manifest($url) {
  return preg_match('~\.m3u8(\?|$)~i', $url) === 1;
}

function make_curl($url) {
  if (!function_exists('curl_init')) {
    return null;
  }

  $ch = curl_init($url);
  if (!$ch) return null;

  $headers = array(
    'User-Agent: Mozilla/5.0',
    'Accept: */*',
    'Connection: keep-alive',
  );
  if (!empty($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
  }

  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_TIMEOUT, 60);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  curl_setopt($ch, CURLOPT_BUFFERSIZE, 65536);
  if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
  }
  if (defined('CURLOPT_HTTP_VERSION') && defined('CURL_HTTP_VERSION_1_1')) {
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
  }

  return $ch;
}

function fetch_manifest_buffered($url) {
  $ch = make_curl($url);
  if (!$ch) {
    return array('ok' => false, 'status' => 500, 'headers' => array(), 'body' => 'cURL extension is not enabled on this server');
  }

  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, true);

  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return array('ok' => false, 'status' => 502, 'headers' => array(), 'body' => $err);
  }

  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  $rawHeaders = substr($resp, 0, $headerSize);
  $body = substr($resp, $headerSize);

  $outHeaders = array();
  $lines = explode("\r\n", $rawHeaders);
  foreach ($lines as $line) {
    $pos = strpos($line, ':');
    if ($pos !== false) {
      $k = strtolower(trim(substr($line, 0, $pos)));
      $v = trim(substr($line, $pos + 1));
      if ($k !== '') $outHeaders[$k] = $v;
    }
  }

  return array('ok' => true, 'status' => $status, 'headers' => $outHeaders, 'body' => $body);
}

function rewrite_manifest_and_send($url) {
  $resp = fetch_manifest_buffered($url);
  send_status(arr_get($resp, 'status', 500));

  if (!arr_get($resp, 'ok', false)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo arr_get($resp, 'body', 'Proxy request failed');
    exit;
  }

  $headers = arr_get($resp, 'headers', array());
  $body = (string)arr_get($resp, 'body', '');

  $lines = preg_split("~\r?\n~", $body);
  $out = array();

  foreach ($lines as $line) {
    $trim = trim($line);

    if (stripos($trim, '#EXT-X-KEY:') === 0 && preg_match('~URI="([^"]+)"~i', $trim, $m)) {
      $keyUrlAbs = absolutize($m[1], $url);
      $proxied = proxy_url($keyUrlAbs);
      $trim = preg_replace('~URI="[^"]+"~i', 'URI="' . $proxied . '"', $trim);
      $out[] = $trim;
      continue;
    }

    if ($trim === '' || starts_with($trim, '#')) {
      $out[] = $trim;
      continue;
    }

    $abs = absolutize($trim, $url);
    $out[] = proxy_url($abs);
  }

  header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
  if (isset($headers['cache-control'])) header('Cache-Control: ' . $headers['cache-control']);
  echo implode("\n", $out);
  exit;
}

function passthrough_stream($url) {
  $ch = make_curl($url);
  if (!$ch) {
    deny(500, 'cURL extension is not enabled on this server');
  }

  $state = array(
    'status' => 200,
    'headers' => array(),
    'headers_sent' => false,
  );

  curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $line) use (&$state) {
    $trim = trim($line);

    // New response block (redirect or final) => reset header map
    if (starts_with($trim, 'HTTP/')) {
      $state['headers'] = array();
      $parts = explode(' ', $trim);
      $state['status'] = isset($parts[1]) ? (int)$parts[1] : 200;
      return strlen($line);
    }

    $pos = strpos($line, ':');
    if ($pos !== false) {
      $k = strtolower(trim(substr($line, 0, $pos)));
      $v = trim(substr($line, $pos + 1));
      if ($k !== '') $state['headers'][$k] = $v;
    }
    return strlen($line);
  });

  curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$state) {
    if (!$state['headers_sent']) {
      send_status($state['status']);

      $h = $state['headers'];
      if (isset($h['content-type'])) header('Content-Type: ' . $h['content-type']);
      if (isset($h['accept-ranges'])) header('Accept-Ranges: ' . $h['accept-ranges']);
      if (isset($h['content-range'])) header('Content-Range: ' . $h['content-range']);
      if (isset($h['content-length'])) header('Content-Length: ' . $h['content-length']);
      if (isset($h['cache-control'])) header('Cache-Control: ' . $h['cache-control']);
      $state['headers_sent'] = true;
    }

    echo $chunk;
    if (function_exists('flush')) flush();
    return strlen($chunk);
  });

  curl_exec($ch);
  if (curl_errno($ch)) {
    $err = curl_error($ch);
    if (!$state['headers_sent']) {
      deny(502, $err);
    }
  }

  curl_close($ch);
}

$url = isset($_GET['url']) ? trim($_GET['url']) : '';
if ($url === '') deny(400, 'Missing ?url=');
if (!preg_match('~^https?://~i', $url)) deny(400, 'Only http/https allowed');

$parts = parse_url($url);
$host = arr_get($parts, 'host', '');
if ($host === '') deny(400, 'Invalid URL');

// Block only local/private IP literals.
if (filter_var($host, FILTER_VALIDATE_IP)) {
  if (preg_match('~^(10\.|127\.|169\.254\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)~', $host)) {
    deny(403, 'Blocked host');
  }
}

if (should_rewrite_manifest($url)) {
  rewrite_manifest_and_send($url);
}

passthrough_stream($url);
