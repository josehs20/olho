<?php
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
    'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
    'X-Csrftoken: missing',
  ],
]);
$r = curl_exec($ch); $hc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
printf("HTTP %d | Size=%d\nHEADERS:\n%s", $hc, strlen($r), implode("\n", array_slice(explode("\n", $r), 0, 12)));
curl_close($ch);
