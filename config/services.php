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

    'andy_core' => [
        'url' => env('ANDY_CORE_URL', ''),
        'connection_key' => env('ANDY_CORE_CONNECTION_KEY', 'beyourlover.com'),
        'key_id' => env('ANDY_CORE_KEY_ID', ''),
        'shared_secret' => env('ANDY_CORE_SHARED_SECRET', ''),
        'verify_tls' => (bool) env('ANDY_CORE_VERIFY_TLS', true),
        'timeout' => (int) env('ANDY_CORE_TIMEOUT_SECONDS', 15),
        'connect_timeout' => (int) env('ANDY_CORE_CONNECT_TIMEOUT_SECONDS', 5),
        'retry_times' => (int) env('ANDY_CORE_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('ANDY_CORE_RETRY_SLEEP_MS', 500),
        'uat_fake' => filter_var(env('ANDY_CORE_UAT_FAKE', false), FILTER_VALIDATE_BOOL),
    ],

    'woocommerce' => [
        'url' => env('WOO_BASE_URL', ''),
        'consumer_key' => env('WOO_CONSUMER_KEY', ''),
        'consumer_secret' => env('WOO_CONSUMER_SECRET', ''),
        'connection_key' => env('WOO_CONNECTION_KEY', 'beyourlover.com'),
        'timeout' => (int) env('WOO_TIMEOUT_SECONDS', 15),
        'completed_delivered_status' => env('WOO_COMPLETED_DELIVERED_STATUS', 'completed-delivered'),
        'product_sync_enabled' => (bool) env('WOO_PRODUCT_SYNC_ENABLED', false),
        'order_sync_enabled' => (bool) env('WOO_ORDER_SYNC_ENABLED', false),
        'media_allowed_hosts' => env('WOO_MEDIA_ALLOWED_HOSTS', ''),
        'media_max_bytes' => max(1, (int) env('WOO_MEDIA_MAX_BYTES', 10485760)),
    ],

    'google_ads' => [
        'client_id' => env('GOOGLE_ADS_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET', ''),
        'refresh_token' => env('GOOGLE_ADS_REFRESH_TOKEN', ''),
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN', ''),
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID', ''),
        'api_version' => env('GOOGLE_ADS_API_VERSION', 'v25'),
        'base_url' => env('GOOGLE_ADS_BASE_URL', 'https://googleads.googleapis.com'),
        'oauth_url' => env('GOOGLE_ADS_OAUTH_URL', 'https://oauth2.googleapis.com/token'),
        'timeout' => (int) env('GOOGLE_ADS_TIMEOUT_SECONDS', 20),
        'inquiry_actions' => [
            'google_ads_form_lead' => env('GOOGLE_ADS_INQUIRY_FORM_ACTION_NAME', 'YBY | WP | 表单Lead提交-V2607'),
            'google_ads_whatsapp_lead' => env('GOOGLE_ADS_INQUIRY_WHATSAPP_ACTION_NAME', 'YBY | WP | WA聊天留言-V2607'),
        ],
    ],

    'google_search_console' => [
        'client_id' => env('GSC_CLIENT_ID', ''),
        'client_secret' => env('GSC_CLIENT_SECRET', ''),
        'refresh_token' => env('GSC_REFRESH_TOKEN', ''),
        'base_url' => env('GSC_BASE_URL', 'https://www.googleapis.com/webmasters/v3'),
        'oauth_url' => env('GSC_OAUTH_URL', 'https://oauth2.googleapis.com/token'),
        'timeout' => (int) env('GSC_TIMEOUT_SECONDS', 20),
        'row_limit' => (int) env('GSC_ROW_LIMIT', 25000),
        'sync_enabled' => (bool) env('GSC_SYNC_ENABLED', false),
        'sync_project' => env('GSC_SYNC_PROJECT', 'yby-irrigation'),
        'sync_site' => env('GSC_SYNC_SITE', 'ybyirrigation-com'),
        'sync_property' => env('GSC_SYNC_PROPERTY', 'sc-domain:ybyirrigation.com'),
        'sync_time' => env('GSC_SYNC_TIME', '04:20'),
        'sync_timezone' => env('GSC_SYNC_TIMEZONE', 'Asia/Shanghai'),
        'sync_lookback_days' => (int) env('GSC_SYNC_LOOKBACK_DAYS', 7),
        'sync_data_lag_days' => (int) env('GSC_SYNC_DATA_LAG_DAYS', 2),
    ],

    'google_docs' => [
        'client_id' => env('GOOGLE_DOCS_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_DOCS_CLIENT_SECRET', ''),
        'refresh_token' => env('GOOGLE_DOCS_REFRESH_TOKEN', ''),
        'docs_base_url' => env('GOOGLE_DOCS_API_BASE_URL', 'https://docs.googleapis.com/v1'),
        'drive_base_url' => env('GOOGLE_DRIVE_API_BASE_URL', 'https://www.googleapis.com/drive/v3'),
        'oauth_url' => env('GOOGLE_DOCS_OAUTH_URL', 'https://oauth2.googleapis.com/token'),
        'timeout' => (int) env('GOOGLE_DOCS_TIMEOUT_SECONDS', 20),
    ],

    'content_ai' => [
        'provider' => env('CONTENT_AI_PROVIDER', 'openai'),
        'api_key' => env('CONTENT_AI_API_KEY', ''),
        'base_url' => env('CONTENT_AI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('CONTENT_AI_MODEL', 'gpt-5.6-terra'),
        'timeout' => (int) env('CONTENT_AI_TIMEOUT_SECONDS', 90),
        'max_output_tokens' => (int) env('CONTENT_AI_MAX_OUTPUT_TOKENS', 12000),
        'proxy' => env('CONTENT_AI_PROXY', ''),
    ],

    'google_analytics' => [
        'client_id' => env('GA4_CLIENT_ID', ''),
        'client_secret' => env('GA4_CLIENT_SECRET', ''),
        'refresh_token' => env('GA4_REFRESH_TOKEN', ''),
        'access_token' => env('GA4_ACCESS_TOKEN', ''),
        'access_token_file' => env('GA4_ACCESS_TOKEN_FILE', ''),
        'property_id' => env('GA4_PROPERTY_ID', ''),
        'measurement_id' => env('GA4_MEASUREMENT_ID', 'G-YKRXCX20TB'),
        'base_url' => env('GA4_BASE_URL', 'https://analyticsdata.googleapis.com/v1beta'),
        'oauth_url' => env('GA4_OAUTH_URL', 'https://oauth2.googleapis.com/token'),
        'timeout' => (int) env('GA4_TIMEOUT_SECONDS', 20),
        'row_limit' => (int) env('GA4_ROW_LIMIT', 100000),
        'sync_enabled' => (bool) env('GA4_SYNC_ENABLED', false),
        'sync_project' => env('GA4_SYNC_PROJECT', 'yby-irrigation'),
    ],

    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID', ''),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET', ''),
        'refresh_token' => env('YOUTUBE_REFRESH_TOKEN', ''),
        'access_token' => env('YOUTUBE_ACCESS_TOKEN', ''),
        'access_token_file' => env('YOUTUBE_ACCESS_TOKEN_FILE', ''),
        'data_base_url' => env('YOUTUBE_DATA_BASE_URL', 'https://www.googleapis.com/youtube/v3'),
        'analytics_base_url' => env('YOUTUBE_ANALYTICS_BASE_URL', 'https://youtubeanalytics.googleapis.com/v2'),
        'oauth_url' => env('YOUTUBE_OAUTH_URL', 'https://oauth2.googleapis.com/token'),
        'timeout' => (int) env('YOUTUBE_TIMEOUT_SECONDS', 20),
        'sync_project' => env('YOUTUBE_SYNC_PROJECT', 'yby-irrigation'),
        'sync_channel' => env('YOUTUBE_SYNC_CHANNEL', 'UCCdp_oR9MW_uPYOHf1qt0Qg'),
    ],

    'aftership_tracking' => [
        'api_key' => env('AFTERSHIP_TRACKING_API_KEY', ''),
        'version' => env('AFTERSHIP_TRACKING_API_VERSION', '2026-07'),
        'base_url' => env('AFTERSHIP_TRACKING_BASE_URL', 'https://api.aftership.com/tracking'),
        'timeout' => (int) env('AFTERSHIP_TRACKING_TIMEOUT_SECONDS', 15),
    ],

    'aftership_branded_page' => [
        'base_url' => env('AFTERSHIP_BRANDED_PAGE_BASE_URL', 'https://beyourlover.aftership.com'),
        'parser_version' => env('AFTERSHIP_BRANDED_PAGE_PARSER_VERSION', 'semantic-html-v1'),
        'timeout' => (int) env('AFTERSHIP_BRANDED_PAGE_TIMEOUT_SECONDS', 15),
    ],

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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
