<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Microservicio Java de firma digital PAdES (carpeta /firmador del proyecto).
    | Compila legajos, emite certificados de la AC de prueba y firma/verifica PDFs.
    */
    'firmador' => [
        'url' => env('FIRMADOR_URL', 'http://127.0.0.1:8081/api'),
        'api_key' => env('FIRMADOR_API_KEY', 'clave-local-firmador-inf513'),
        'timeout' => (int) env('FIRMADOR_TIMEOUT', 120),
    ],

    /*
    | Firma con Jacobitus (la llave privada queda en el token/softoken del firmante):
    | el backend verifica cada PDF firmado antes de registrarlo. "verificador" elige quién lo verifica:
    | firmador (microservicio local) o agetic (Validador Web). exigir_confianza rechaza firmas cuya AC
    | no esté en el almacén de confianza del verificador.
    */
    'firma' => [
        'verificador' => env('FIRMA_VERIFICADOR', 'firmador'),
        'exigir_confianza' => (bool) env('FIRMA_EXIGIR_CONFIANZA', false),
        'max_pdf_kb' => (int) env('FIRMA_MAX_PDF_KB', 15360),
    ],

    /*
    | Validador Web de firmas de AGETIC (API REST). El cuerpo y la respuesta se adaptan en ValidadorAgeticService.
    */
    'agetic_validador' => [
        'url' => env('AGETIC_VALIDADOR_URL'),
        'campo_archivo' => env('AGETIC_VALIDADOR_CAMPO', 'archivo'),
        'timeout' => (int) env('AGETIC_VALIDADOR_TIMEOUT', 60),
    ],

    /*
    | Stripe (pagos en línea al contado). En desarrollo use las llaves de prueba (sk_test_ / pk_test_).
    | La moneda debe estar habilitada en la cuenta; el importe se convierte con tipo_cambio si no es BOB.
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'moneda' => strtolower(env('STRIPE_MONEDA', 'bob')),
        'tipo_cambio' => (float) env('STRIPE_TIPO_CAMBIO', 1),
        'timeout' => (int) env('STRIPE_TIMEOUT', 30),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
