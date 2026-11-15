<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<!DOCTYPE html>
<html lang="<?= e($config['brand']['locale'] ?? 'en') ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <?= lumora_head_meta($meta ?? [], $config ?? []) ?>
    <script nonce="<?= e(lumora_csp_nonce()) ?>">try{const s=localStorage.getItem('color-scheme');if(s==='light'||s==='dark'){const m=document.querySelector('meta[name="color-scheme"]');if(m)m.content=s;document.documentElement.style.colorScheme=s;}}catch(_){}</script>
</head>
<body>
<a href="#main-content" class="lumora-skip-link">Skip to main content</a>
