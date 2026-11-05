<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->use(RefreshDatabase::class)->in('Feature');

function productionEnv(array $overrides = []): array
{
    return array_merge([
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:abc',
        'APP_URL' => 'https://wasla.example',
        'TAG_BASE_URL' => 'https://wasla.example/t',
        'DB_HOST' => 'db', 'DB_PORT' => '3306', 'DB_DATABASE' => 'wasla',
        'DB_USERNAME' => 'wasla', 'DB_PASSWORD' => 'secret',
        'OPENAI_API_KEY' => 'sk-test',
        'MODERATION_DAILY_CAP' => '1000',
        'PAYMENT_GATEWAY' => 'iyzico',
        'IYZICO_API_KEY' => 'k', 'IYZICO_SECRET_KEY' => 's',
        'AWS_ACCESS_KEY_ID' => 'a', 'AWS_SECRET_ACCESS_KEY' => 'b',
        'AWS_DEFAULT_REGION' => 'eu-central-1', 'AWS_BUCKET' => 'wasla',
        'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'smtp.example', 'MAIL_PORT' => '587',
        'MAIL_USERNAME' => 'u', 'MAIL_PASSWORD' => 'p', 'MAIL_FROM_ADDRESS' => 'no-reply@wasla.example',
    ], $overrides);
}

require_once __DIR__.'/Helpers/tags.php';
require_once __DIR__.'/Helpers/cars.php';
require_once __DIR__.'/Helpers/chat.php';
