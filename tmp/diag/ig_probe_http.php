<?php
$_ENV['REMOTE_ADDR'] = $_ENV['REMOTE_ADDR'] ?? 'n/a';
$_ENV['REMOTE_ADDR'] = $_ENV['REMOTE_ADDR'] ?? 'n/a';
$ua = 'OLHO/1.0 (debug)';
$ch = curl_init('https://www.instagram.com/api/v1/users/self/');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 20,
  CURLOPT_FOLLOWLOCATION => false,
  CURLOPT_CUSTOMREQUEST => 'GET',
  CURLOPT_HTTPHEADER => [
    'User-Agent: ' . $ua,
    'Accept: application/json',
  ],
]);
$r = curl_exec($ch); $hc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
printf("HTTP %d | Size=%d | Body(head)=%s", $hc, strlen($r), substr($r ?? '', 0, 160));
curl_close($ch);
echo PHP_EOL;
