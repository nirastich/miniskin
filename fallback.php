<?php
// proxy for vzge.me

$path = $_GET['url'] ?? '';
if ($path === '') { http_response_code(400); exit; }

// block external usage
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';

$allowed = false;
if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === $host) $allowed = true;
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) === $host) $allowed = true;
if (!$allowed) { http_response_code(403); exit; }

// only proxy known vzge paths
$ok = false;
foreach (['processedskin/', 'frontfull/', 'head/'] as $p) {
	if (strpos($path, $p) === 0) { $ok = true; break; }
}
if (!$ok) { http_response_code(403); exit; }

// basic rate limit per IP (10 req/s via temp files)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$rlFile = sys_get_temp_dir() . '/msrl_' . md5($ip);
$now = time();
$hits = @file_get_contents($rlFile);
if ($hits !== false) {
	$parts = explode('|', $hits);
	if (count($parts) === 2 && (int)$parts[1] === $now && (int)$parts[0] >= 10) {
		http_response_code(429);
		exit;
	}
	$count = ((int)$parts[1] === $now) ? (int)$parts[0] + 1 : 1;
} else {
	$count = 1;
}
@file_put_contents($rlFile, $count . '|' . $now);

// build target url
$target = 'https://vzge.me/' . $path;
$params = $_GET;
unset($params['url']);
if (!empty($params)) $target .= '?' . http_build_query($params);

$ch = curl_init($target);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Miniskin/fallback (+https://miniskin.73.nu; <c@73.nu>)');

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

if ($resp === false) { http_response_code(502); exit; }

$hdrs = substr($resp, 0, $hdrSize);
$body = substr($resp, $hdrSize);

// pass through content-type
if (preg_match('/^content-type:\s*(.+)$/mi', $hdrs, $m)) {
	header('Content-Type: ' . trim($m[1]));
} else {
	header('Content-Type: image/png');
}

header('Cache-Control: public, max-age=300');
http_response_code($code);
echo $body;