<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WAHA (WhatsApp transport) connection
    |--------------------------------------------------------------------------
    |
    | The self-hosted WAHA instance the SDR agent sends/receives through.
    | Used by the CRM to show the Artist's WhatsApp connection state, serve
    | the QR code, and (re)start the session when it is broken.
    |
    */

    'waha_url' => env('WAHA_API_URL', 'https://waha.vaif.com.br'),

    'waha_api_key' => env('WAHA_API_KEY'),

    'waha_webhook_url' => env('WAHA_WEBHOOK_URL', 'https://n8n.vaif.com.br/webhook/waha-webhook'),

    // Shared secret for internal automation (n8n → CRM reference uploads).
    'crm_internal_token' => env('CRM_INTERNAL_TOKEN'),

];
