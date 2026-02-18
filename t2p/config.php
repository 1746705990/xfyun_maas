<?php
// config.php
return [
    // 讯飞开放平台/星辰MaaS 的密钥
    // https://maas.xfyun.cn/modelService
    'APP_ID' => '12345',
    'API_KEY' => '12345',
    'API_SECRET' => '12345',

    // API 地址
    'API_URL' => 'https://maas-api.cn-huabei-1.xf-yun.com/v2.1/tti',
    
    // 可选模型列表
    'MODELS' => [
        'xopqwentti20b' => 'Qwen-Image-2512 (旗舰画质)',
        'xopzimageturbo' => 'Z-Image-Turbo (极速生成)'
    ]
];
?>