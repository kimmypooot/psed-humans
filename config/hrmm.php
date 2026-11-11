<?php

return [

    // Shown in the About dialog and the sidebar footer.
    'version' => env('HRMM_VERSION', '0.1.0'),

    // The largest HR mechanism file an agency may upload, in kilobytes.
    'max_upload_kb' => (int) env('HRMM_MAX_UPLOAD_KB', 20480),

    // The title printed before a signatory's name, by IMIS user id, for when IMIS's directory does not send
    // one. The two who sign transmittals (Director IV and Director III) are both lawyers.
    'honorifics' => [
        50 => 'Atty.', // Marilyn E. Taldo, Director IV
        66 => 'Atty.', // Gina A. Crucio, Director III
    ],

    // The HR checklist is signed by the PSED Chief with a DICT PNPKI certificate. The issuer of the signing
    // certificate must match this pattern (the name DICT's certificate authority puts on it).
    'pnpki_issuer' => env('HRMM_PNPKI_ISSUER', '/PNPKI|DICT|Philippine National/i'),

    // Optional: a PEM file of the PNPKI root and government CA certificates. When set, the certificate chain
    // is checked against it too; when not, the signature and the issuer's name are checked but the chain is not.
    'pnpki_ca_bundle' => env('HRMM_PNPKI_CA_BUNDLE'),

    // The Action Officer's turnaround, in working days, counted from acknowledging receipt.
    'turnaround_days' => (int) env('HRMM_TURNAROUND_DAYS', 20),

];
