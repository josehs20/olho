<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$s = app(App\Sources\Integrations\InstagramSettings::class);
$s->set(App\Sources\Integrations\InstagramSettings::KEY_SESSION_ID, '');
$s->set(App\Sources\Integrations\InstagramSettings::KEY_STATUS, App\Sources\Integrations\InstagramSettings::STATUS_EXPIRED);
echo 'saved status: ' . $s->status() . PHP_EOL;
