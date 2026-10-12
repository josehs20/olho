<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$svc = app(App\Services\SettingsService::class);
$svc->set(App\Sources\Integrations\InstagramSettings::KEY_SESSION_ID, '');
$svc->set(App\Sources\Integrations\InstagramSettings::KEY_STATUS, App\Sources\Integrations\InstagramSettings::STATUS_EXPIRED);
echo 'persisted: ' . $svc->get(App\Sources\Integrations\InstagramSettings::KEY_STATUS) . PHP_EOL;
