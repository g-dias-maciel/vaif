<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Invoice approval
    |--------------------------------------------------------------------------
    |
    | Where the "nova fatura aguardando aprovação" digest goes when the
    | billing_settings.invoice_approval_email field is empty.
    |
    */
    'approval_email' => env('FINANCE_APPROVAL_EMAIL', env('VAIF_ADMIN_EMAIL')),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp delivery
    |--------------------------------------------------------------------------
    |
    | The WAHA session used to send invoices and dunning messages. The old n8n
    | workflow used the "default" session.
    |
    */
    'waha_session' => env('WAHA_FINANCE_SESSION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Invoice PDFs
    |--------------------------------------------------------------------------
    */
    'pdf_disk' => env('FINANCE_PDF_DISK', 'local'),
    'pdf_path' => 'invoices',

    /*
    |--------------------------------------------------------------------------
    | Signed links
    |--------------------------------------------------------------------------
    |
    | How long the public PDF link sent over WhatsApp stays valid.
    |
    */
    'pdf_link_days' => (int) env('FINANCE_PDF_LINK_DAYS', 30),
];
