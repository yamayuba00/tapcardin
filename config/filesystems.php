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
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Unggahan yang tampil ke publik (foto profil, logo, gambar desain, QRIS). PUBLIC_DISK_DRIVER=s3 → disimpan di
        // bucket S3 (folder public/, dibaca publik lewat bucket policy); selain itu di storage/app/public server.
        'public' => env('PUBLIC_DISK_DRIVER', 'local') === 's3' ? [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'root' => 'public',
            'url' => rtrim((string) env('AWS_URL'), '/'), // Laravel menambahkan root (public/) sendiri ke URL
            // bucket tidak menerima ACL per objek; akses baca diatur bucket policy untuk public/*
            'retain_visibility' => false,
            'throw' => false,
            'report' => false,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Gambar email blast (promo). BLAST_DISK_DRIVER=s3 → bucket S3 yang sama, folder blast/ (bucket policy harus
        // mengizinkan baca publik untuk blast/*, kalau tidak gambar tidak tampil di email); selain itu storage/app/public/blast.
        'blast' => env('BLAST_DISK_DRIVER', 'local') === 's3' ? [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'root' => 'blast',
            'url' => rtrim((string) env('AWS_URL'), '/'), // Laravel menambahkan root (blast/) sendiri ke URL
            // tanpa ACL per objek (bucket modern menolak ACL): baca publik diatur bucket policy untuk blast/*
            'retain_visibility' => false,
            'throw' => true,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/public/blast'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage/blast',
            'visibility' => 'public',
            'throw' => true,
        ],

        // backup database (tapcard:backup) — bucket yang sama, folder backups/ tidak terbaca publik
        'backups' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('BACKUP_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'root' => 'backups',
            'retain_visibility' => false,
            'throw' => true,
        ],

        // dokumen terbit (tagihan PDF): privat. DOCUMENTS_DISK_DRIVER=s3 → bucket S3 (folder documents/, tidak publik);
        // selain itu storage/app/documents di server.
        'documents' => env('DOCUMENTS_DISK_DRIVER', 'local') === 's3' ? [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'root' => 'documents',
            'retain_visibility' => false,
            'throw' => true,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/documents'),
            'throw' => true,
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
