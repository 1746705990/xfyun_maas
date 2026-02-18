<?php
session_start();
$config = require 'config.php';

// ================= 后端逻辑 =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    
    $prompt = $_POST['prompt'] ?? '';
    $neg_prompt = $_POST['negative_prompt'] ?? '';
    $model = $_POST['model'] ?? 'xopqwentti20b';
    $scheduler = $_POST['scheduler'] ?? 'Euler a';
    $width = intval($_POST['width'] ?? 1024);
    $height = intval($_POST['height'] ?? 1024);
    $scale = floatval($_POST['scale'] ?? 5.0);
    $steps = intval($_POST['steps'] ?? 20);
    // 种子：如果用户填-1或留空，则由后端生成随机数
    $seed_input = $_POST['seed'] ?? '';
    $seed = ($seed_input === '' || $seed_input == -1) ? rand(0, 2147483647) : intval($seed_input);

    if (empty($prompt)) { echo json_encode(['code'=>400,'msg'=>'提示词不能为空']); exit; }

    $host = parse_url($config['API_URL'], PHP_URL_HOST);
    $path = parse_url($config['API_URL'], PHP_URL_PATH);
    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $signature_origin = "host: $host\ndate: $date\nPOST $path HTTP/1.1";
    $signature = base64_encode(hash_hmac('sha256', $signature_origin, $config['API_SECRET'], true));
    $authorization = sprintf('api_key="%s", algorithm="hmac-sha256", headers="host date request-line", signature="%s"', $config['API_KEY'], $signature);

    $payload = [
        "header" => [ "app_id" => $config['APP_ID'], "uid" => "user_".substr(session_id(), 0, 8), "patch_id" => [] ],
        "parameter" => [ "chat" => [
            "domain" => $model, "width" => $width, "height" => $height,
            "guidance_scale" => $scale, "num_inference_steps" => $steps, "seed" => $seed, "scheduler" => $scheduler
        ]],
        "payload" => [ "message" => [ "text" => [["role" => "user", "content" => $prompt]] ] ]
    ];
    if (!empty($neg_prompt)) { $payload['payload']['negative_prompts'] = ["text" => $neg_prompt]; }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $config['API_URL'], CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [ "Content-Type: application/json", "Host: $host", "Date: $date", "Authorization: $authorization" ]
    ]);
    
    $start_time = microtime(true);
    $response = curl_exec($ch);
    $end_time = microtime(true);
    curl_close($ch);

    $json = json_decode($response, true);
    if (($json['header']['code']??-1) === 0) {
        echo json_encode([
            'code'=>0, 
            'data'=>$json['payload']['choices']['text'][0]['content'], 
            'seed'=>$seed,
            'time'=>round($end_time - $start_time, 2)
        ]);
    } else {
        echo json_encode(['code'=>500, 'msg'=>'API错误: '.($json['header']['message']??'未知'), 'raw'=>$json]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>IMAGINE PRO</title>
    <?php include 'assets.php'; ?>

    <style>
        :root {
            --bg-main: #09090b;
            --bg-panel: #18181b;
            --border: #27272a;
            --accent: #3b82f6;
            --text-main: #e4e4e7;
        }
        body { background-color: var(--bg-main); color: var(--text-main); font-family: 'Inter', sans-serif; }
        
        /* 仅在电脑端美化滚动条 */
        @media (min-width: 1024px) {
            ::-webkit-scrollbar { width: 6px; }
            ::-webkit-scrollbar-track { background: transparent; }
            ::-webkit-scrollbar-thumb { background: #3f3f46; border-radius: 3px; }
        }

        .pro-label { font-size: 0.75rem; color: #a1a1aa; font-weight: 600; text-transform: uppercase; margin-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center; }
        .pro-input {
            background-color: #09090b; border: 1px solid var(--border); color: white;
            transition: border-color 0.2s; font-family: 'Inter', sans-serif;
        }
        .pro-input:focus { outline: none; border-color: var(--accent); }
        .data-font { font-family: 'JetBrains Mono', monospace; }

        /* 滑块样式 */
        input[type=range] { -webkit-appearance: none; background: transparent; height: 24px; cursor: pointer; }
        input[type=range]::-webkit-slider-runnable-track { height: 4px; background: #27272a; border-radius: 2px; }
        input[type=range]::-webkit-slider-thumb {
            -webkit-appearance: none; height: 16px; width: 16px;
            background: var(--accent); margin-top: -6px; border-radius: 50%;
            box-shadow: 0 0 0 2px #09090b;
        }
        
        /* 比例选择卡片 */
        .ratio-card {
            border: 1px solid var(--border); background: #09090b; border-radius: 4px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s; padding: 8px 4px;
        }
        .ratio-card:hover { border-color: #52525b; }
        .ratio-card.active { border-color: var(--accent); background: rgba(59, 130, 246, 0.1); color: var(--accent); }
        .ratio-box { border: 1px solid currentColor; margin-bottom: 4px; opacity: 0.8; }
    </style>
</head>
<body class="min-h-screen lg:h-screen lg:overflow-hidden flex flex-col">

    <header class="h-14 border-b border-[#27272a] bg-[#18181b] flex items-center px-4 justify-between flex-shrink-0 z-40 sticky top-0">
        <div class="flex items-center gap-2">
            <div class="w-7 h-7 bg-blue-600 rounded flex items-center justify-center shadow-blue-900/20 shadow-lg">
                <i class="ph-bold ph-faders text-white"></i>
            </div>
            <h1 class="font-bold tracking-tight text-base">IMAGINE <span class="text-blue-500">PRO</span></h1>
        </div>
        <div class="flex items-center gap-3">
            <a href="https://www.xfyun.cn/doc/spark/%E5%9B%BE%E7%89%87%E7%94%9F%E6%88%90.html" target="_blank" class="flex items-center justify-center w-6 h-6 rounded hover:bg-[#27272a] text-gray-500 hover:text-blue-400 transition-colors" title="API接口文档 (讯飞星火)">
                <i class="ph-bold ph-book-open"></i>
            </a>
            <span class="text-[10px] bg-[#27272a] px-2 py-1 rounded text-gray-400 border border-[#3f3f46]">V1.0.0</span>
        </div>
    </header>

    <div class="flex-1 flex flex-col lg:flex-row w-full lg:overflow-hidden">
        
        <main id="viewport" class="order-1 lg:order-2 flex-1 bg-[#09090b] relative flex flex-col items-center justify-center p-4 min-h-[50vh] border-b lg:border-b-0 lg:border-l border-[#27272a]">
            <div class="absolute inset-0 opacity-20 pointer-events-none" 
                 style="background-image: radial-gradient(#333 1px, transparent 1px); background-size: 20px 20px;"></div>

            <div class="relative z-10 w-full h-full flex flex-col items-center justify-center">
                
                <div id="emptyState" class="text-center opacity-40">
                    <i class="ph-duotone ph-image text-4xl text-gray-500 mb-2"></i>
                    <p class="text-xs font-mono text-gray-500">READY</p>
                </div>

                <div id="loadingState" class="hidden absolute inset-0 bg-[#09090b]/80 backdrop-blur-sm z-20 flex flex-col justify-center items-center">
                    <div class="w-8 h-8 border-2 border-[#27272a] border-t-blue-500 rounded-full animate-spin mb-3"></div>
                    <div class="text-xs font-mono text-blue-400 animate-pulse">GENERATING...</div>
                </div>

                <img id="resultImg" src="" class="hidden w-auto h-auto max-w-full max-h-[70vh] object-contain rounded border border-[#27272a] shadow-2xl" alt="Result">
                
                <div id="resultInfo" class="hidden mt-4 lg:absolute lg:bottom-6 lg:mt-0 flex items-center gap-4 bg-[#18181b] border border-[#27272a] px-4 py-2 rounded-full shadow-xl z-30">
                    <div class="text-xs font-mono text-gray-400">
                        <i class="ph-bold ph-clock mr-1"></i><span id="timeVal">--s</span>
                    </div>
                    <div class="w-px h-3 bg-[#3f3f46]"></div>
                    <a id="downloadBtn" href="#" download class="text-xs font-bold text-white hover:text-blue-400 flex items-center gap-1">
                        <i class="ph-bold ph-download-simple"></i> 保存
                    </a>
                </div>
            </div>
        </main>

        <aside class="order-2 lg:order-1 w-full lg:w-[400px] bg-[#09090b] lg:border-r border-[#27272a] flex flex-col z-30 lg:h-full lg:overflow-y-auto">
            <form id="aiForm" class="p-5 space-y-6 pb-24 lg:pb-10">
                
                <div>
                    <span class="pro-label">基础模型 / MODEL</span>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach($config['MODELS'] as $k => $v): ?>
                        <label class="cursor-pointer relative">
                            <input type="radio" name="model" value="<?= $k ?>" class="peer sr-only" <?= $k==='xopqwentti20b'?'checked':'' ?>>
                            <div class="text-center py-2.5 bg-[#18181b] border border-[#27272a] rounded text-xs peer-checked:border-blue-500 peer-checked:text-blue-400 peer-checked:bg-blue-900/10 transition-all">
                                <?= explode(' ', $v)[0] ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <span class="pro-label">正向提示词 / PROMPT</span>
                    <textarea name="prompt" rows="4" class="pro-input w-full p-3 rounded text-sm leading-relaxed resize-none" placeholder="描述画面细节...">赛博朋克风格的未来城市，街道湿润，霓虹灯倒影，高细节，8k分辨率</textarea>
                </div>
                <div>
                    <span class="pro-label text-red-400/80">负向排除 / NEGATIVE</span>
                    <textarea name="negative_prompt" rows="2" class="pro-input w-full p-3 rounded text-xs text-gray-400 resize-none" placeholder="不想出现的元素...">low quality, blurry, deformed, watermark</textarea>
                </div>

                <div>
                    <span class="pro-label">画幅比例 / ASPECT RATIO</span>
                    <input type="hidden" name="resolution" id="resolution" value="1024,1024">
                    
                    <div class="grid grid-cols-3 gap-2">
                        <div class="ratio-card active" onclick="setRatio(this, '1024,1024')">
                            <div class="ratio-box w-6 h-6 bg-current"></div>
                            <span class="text-[10px] mt-1">1:1</span>
                            <span class="text-[9px] scale-90 opacity-60">1024x</span>
                        </div>
                        <div class="ratio-card" onclick="setRatio(this, '768,1024')">
                            <div class="ratio-box w-4 h-6 bg-current"></div>
                            <span class="text-[10px] mt-1">3:4</span>
                            <span class="text-[9px] scale-90 opacity-60">768w</span>
                        </div>
                        <div class="ratio-card" onclick="setRatio(this, '1024,768')">
                            <div class="ratio-box w-6 h-4 bg-current"></div>
                            <span class="text-[10px] mt-1">4:3</span>
                            <span class="text-[9px] scale-90 opacity-60">768h</span>
                        </div>
                        <div class="ratio-card" onclick="setRatio(this, '576,1024')">
                            <div class="ratio-box w-3 h-6 bg-current"></div>
                            <span class="text-[10px] mt-1">9:16</span>
                            <span class="text-[9px] scale-90 opacity-60">576w</span>
                        </div>
                        <div class="ratio-card" onclick="setRatio(this, '1024,576')">
                            <div class="ratio-box w-6 h-3 bg-current"></div>
                            <span class="text-[10px] mt-1">16:9</span>
                            <span class="text-[9px] scale-90 opacity-60">576h</span>
                        </div>
                        <div class="ratio-card" onclick="setRatio(this, '768,768')">
                            <div class="ratio-box w-5 h-5 bg-current"></div>
                            <span class="text-[10px] mt-1">1:1</span>
                            <span class="text-[9px] scale-90 opacity-60">768x</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 pt-2">
                    
                    <div>
                        <span class="pro-label">采样器 / SAMPLER</span>
                        <select name="scheduler" class="pro-input w-full p-2.5 rounded text-xs">
                            <option value="Euler a" selected>Euler a (推荐)</option>
                            <option value="DPM++ 2M Karras">DPM++ 2M Karras</option>
                            <option value="DPM++ SDE Karras">DPM++ SDE Karras</option>
                            <option value="DDIM">DDIM</option>
                            <option value="Euler">Euler</option>
                        </select>
                    </div>

                    <div>
                        <div class="pro-label">
                            <span>迭代步数 / STEPS</span>
                            <span id="stepVal" class="text-blue-400 data-font">20</span>
                        </div>
                        <input type="range" name="steps" min="10" max="50" step="1" value="20" class="w-full" oninput="document.getElementById('stepVal').innerText = this.value">
                    </div>

                    <div>
                        <div class="pro-label">
                            <span>提示词相关度 / CFG SCALE</span>
                            <span id="scaleVal" class="text-blue-400 data-font">5.0</span>
                        </div>
                        <input type="range" name="scale" min="1.0" max="20.0" step="0.5" value="5.0" class="w-full" oninput="document.getElementById('scaleVal').innerText = this.value">
                    </div>

                    <div>
                        <span class="pro-label">随机种子 / SEED</span>
                        <div class="flex gap-2">
                            <input type="number" name="seed" id="seedInput" placeholder="-1 (随机)" class="pro-input flex-1 p-2.5 rounded text-xs data-font">
                            <button type="button" onclick="randomSeed()" class="px-3 bg-[#18181b] border border-[#27272a] rounded hover:border-gray-500 text-gray-400">
                                <i class="ph-bold ph-dice-five"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <button type="submit" id="submitBtn" class="w-full py-4 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded shadow-lg shadow-blue-900/30 flex justify-center items-center gap-2 text-sm transition-all mt-4">
                    <span>立即生成</span>
                    <i class="ph-bold ph-lightning"></i>
                </button>
            </form>
        </aside>

    </div>

    <script>
        // 比例选择逻辑
        function setRatio(el, val) {
            document.getElementById('resolution').value = val;
            document.querySelectorAll('.ratio-card').forEach(c => c.classList.remove('active'));
            el.classList.add('active');
        }

        // 随机种子逻辑
        function randomSeed() {
            const seed = Math.floor(Math.random() * 2147483647);
            document.getElementById('seedInput').value = seed;
        }

        // 表单提交逻辑
        const form = document.getElementById('aiForm');
        const submitBtn = document.getElementById('submitBtn');
        const emptyState = document.getElementById('emptyState');
        const loadingState = document.getElementById('loadingState');
        const resultImg = document.getElementById('resultImg');
        const resultInfo = document.getElementById('resultInfo');
        const viewport = document.getElementById('viewport');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // 手机端自动回顶
            if (window.innerWidth < 1024) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> 正在生成...';
            
            loadingState.classList.remove('hidden');
            resultInfo.classList.add('hidden');
            if(resultImg.classList.contains('hidden')) emptyState.classList.add('hidden');

            const formData = new FormData(form);
            const [w, h] = document.getElementById('resolution').value.split(',');
            formData.append('width', w);
            formData.append('height', h);

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    body: formData
                });
                const res = await response.json();

                if (res.code === 0) {
                    const imgUrl = 'data:image/png;base64,' + res.data;
                    resultImg.onload = () => {
                        loadingState.classList.add('hidden');
                        emptyState.classList.add('hidden');
                        resultImg.classList.remove('hidden');
                    };
                    resultImg.src = imgUrl;

                    document.getElementById('timeVal').innerText = res.time + 's';
                    document.getElementById('downloadBtn').href = imgUrl;
                    document.getElementById('downloadBtn').download = `AI_Gen_${res.seed}.png`;
                    resultInfo.classList.remove('hidden');
                } else {
                    alert('生成失败: ' + res.msg);
                    loadingState.classList.add('hidden');
                    if(resultImg.classList.contains('hidden')) emptyState.classList.remove('hidden');
                }
            } catch (error) {
                alert('网络连接错误');
                loadingState.classList.add('hidden');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span>立即生成</span><i class="ph-bold ph-lightning"></i>';
            }
        });
    </script>
</body>
</html>