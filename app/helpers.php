<?php

if (! function_exists('public_storage_url')) {
    function public_storage_url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return '/storage/'.ltrim(str_replace('\\', '/', $path), '/');
    }
}
