<?php

return [
    'version' => env('PARSER_VERSION', '0.1.0'),
    'user_agent' => env('PARSER_USER_AGENT', 'Mozilla/5.0 (compatible; CaseParser/0.1; +https://localhost)'),
    'verify_tls' => env('PARSER_VERIFY_TLS', false),
];
