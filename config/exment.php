<?php

return [

    'password_rule' => [
      'rule' => '^[ -~]+$',
      'min' => '8',
      'max' => '32',
    ],

    'organization_deeps' => env('EXMENT_ORGANIZATION_DEEPS', 4),

    'dashboard_rows' => env('EXMENT_DASHBOARD_ROWS', 4),

    'manual_url' => env('EXMENT_MANUAL_URL', 'https://exment.net/docs/#/'),

    'template_search_url' => env('EXMENT_TEMPLATE_SEARCH_URL', 'https://exment-manage.exment.net/api/template'),

    'show_default_login_provider' => env('EXMENT_SHOW_DEFAULT_LOGIN_PROVIDER', true),
    
    'login_providers' => env('EXMENT_LOGIN_PROVIDERS', []),
    
    'revision_count_default' => env('EXMENT_REVISION_COUNT', 100),
    
    'api' => env('EXMENT_API', false),

    'backup_info' => [
      'mysql_dir' => env('EXMENT_MYSQL_BIN_DIR'),
      'def_file' => 'table_definition.sql',
      'copy_dir' => [
      ],
    ],

    'notify_saved_skip_minutes' => env('EXMENT_NOTIFY_SAVED_SKIP_MINUTES', 5),

    'chart_backgroundColor' => [
      "#FF6384",
      "#36A2EB",
      "#FFCE56",
      "#339900",
      "#ff6633",
      "#cc0099"
  ],

  'driver' => [
    'default' => env('EXMENT_DRIVER_DEFAULT', 'local'),
    'backup' => env('EXMENT_DRIVER_BACKUP', 'local'),
    'tmp' => env('EXMENT_DISK_TMP', 'local'),
  ],
  
  'disabled_outside_api' => env('EXMENT_DISABLED_OUTSIDE_API', false),
];
