<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Avatar Disk
    |--------------------------------------------------------------------------
    |
    | Disk that uploaded user avatars (resized 256x256 PNGs) are stored on.
    | It must be publicly readable; run `php artisan storage:link` for the
    | default "public" disk.
    |
    */

    'avatar_disk' => env('AVATAR_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Server Icon Disk
    |--------------------------------------------------------------------------
    |
    | Disk that uploaded server icons (resized 256x256 PNGs) are stored on.
    | Like the avatar disk it must be publicly readable.
    |
    */

    'server_icon_disk' => env('SERVER_ICON_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Message Attachments
    |--------------------------------------------------------------------------
    |
    | Disk that files attached to messages are stored on (ISSUE-19). It does
    | not need to be public: files are only served through signed URLs
    | (App\Http\Controllers\AttachmentController), which expire after
    | attachment_url_ttl minutes. Each file keeps the name of its disk, so
    | changing the disk later keeps older files readable.
    |
    */

    'attachment_disk' => env('ATTACHMENT_DISK', 'local'),

    'attachment_url_ttl' => (int) env('ATTACHMENT_URL_TTL', 60 * 24),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Off (ISSUE-41): this disk holds private attachments, which
            // AttachmentController serves. `serve` would add Laravel's own
            // GET and PUT /storage/{path} routes for signed temporary
            // URLs, an upload endpoint outside the web group that nothing
            // here uses.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
