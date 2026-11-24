<?php

function google_maps_api_key(): string {
    $environmentKey = getenv('GOOGLE_MAPS_API_KEY');
    if (is_string($environmentKey) && trim($environmentKey) !== '') {
        return trim($environmentKey);
    }

    $localConfig = __DIR__ . '/maps.local.php';
    if (is_file($localConfig)) {
        $localKey = require $localConfig;
        if (is_string($localKey) && trim($localKey) !== '') {
            return trim($localKey);
        }
    }

    return '';
}
