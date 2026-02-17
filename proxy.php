<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/plain; charset=utf-8");

if (!isset($_GET['url'])) {
  http_response_code(400);
  echo "Missing url";
  exit;
}

$url = $_GET['url'];

$opts = [
  "http" => [
    "method" => "GET",
    "header" => "User-Agent: Mozilla/5.0\r\n"
  ]
];

$context = stream_context_create($opts);
$data = @file_get_contents($url, false, $context);

if ($data === false) {
  http_response_code(500);
  echo "Failed to fetch M3U";
  exit;
}

echo $data;
