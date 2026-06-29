<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'mtarget' => [
        'url' => env('MTARGET_URL', 'https://api-public-2.mtarget.fr/messages'),
        'username' => env('MTARGET_USERNAME', 'bwantech'),
        'password' => env('MTARGET_PASSWORD', 'x7jyKG0IJRNH'),
        'sender' => env('MTARGET_SENDER', 'TOO AUTO'),
    ],

    'fineopay' => [
        'base_url' => env('FINEOPAY_BASE_URL', 'https://dev.fineopay.com/api/v1/business/dev'),
        'checkout_path' => env('FINEOPAY_CHECKOUT_PATH', '/checkout-link'),
        'business_code' => env('FINEOPAY_BUSINESS_CODE'),
        'api_key' => env('FINEOPAY_API_KEY'),
        'callback_token' => env('FINEOPAY_CALLBACK_TOKEN'),
    ],

];
