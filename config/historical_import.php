<?php

declare(strict_types=1);

/**
 * Contrato único de carga para la importación histórica.
 *
 * El límite PHP del servidor debe ser superior a estos valores:
 * upload_max_filesize >= max_file_size_mb
 * post_max_size > max_batch_size_mb (se recomienda 384M o superior).
 *
 * @return array{max_files:int,max_file_size_mb:int,max_batch_size_mb:int}
 */
function historical_import_upload_config(): array
{
    return [
        'max_files' => 20,
        'max_file_size_mb' => 250,
        'max_batch_size_mb' => 300,
    ];
}
