<?php
/**
 * ========================================
 * رفع M3U إلى GitHub - نسخة محسنة
 * ========================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ========== إعدادات GitHub ==========
// ملاحظة: يفضل استخدام متغيرات البيئة أو ملف خارجي للتوكن
define('GITHUB_TOKEN', 'github_pat_' . '11A7BZZLA0HlOYw8Gicq8S_H5NL0OF5y89NR3X5zii4jyjqVzrjPQNafdzwguf8vWAJ7SLNXUYRuVnuhRm');
define('GITHUB_REPO', 'Momotv10/Momh');
define('GITHUB_BRANCH', 'main');

// ========== إعدادات الملفات المؤقتة ==========
define('TEMP_DIR', __DIR__ . '/temp_uploads/');

if (!is_dir(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0777, true);
}

// ========== قائمة بروكسيات مجانية ==========
$proxies = [
    '', // بدون بروكسي (افتراضي)
    'https://api.allorigins.win/raw?url=',
    'https://corsproxy.io/?url=',
    'https://proxy.cors.sh/',
];

// ========== معالجة طلبات POST ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'fetch_via_proxy') {
            $url = $_POST['url'] ?? '';
            $proxy_index = (int)($_POST['proxy_index'] ?? 0);
            
            if (empty($url)) {
                throw new Exception('الرابط مطلوب');
            }
            
            $content = fetchWithImprovedMethod($url, $proxy_index);
            
            if (empty($content)) {
                throw new Exception('فشل تحميل الملف أو المحتوى فارغ');
            }
            
            // التحقق من أن المحتوى ليس صفحة خطأ HTML
            if (stripos($content, '<html') !== false && stripos($content, '404 Not Found') !== false) {
                throw new Exception('تم استلام صفحة خطأ 404 من السيرفر');
            }

            echo json_encode(['success' => true, 'content' => $content]);
            
        } elseif ($action === 'upload_content') {
            $m3u_content = $_POST['m3u_content'] ?? '';
            $filename = $_POST['filename'] ?? 'playlist.m3u';
            $github_path = $_POST['github_path'] ?? '';
            
            if (empty($m3u_content)) {
                throw new Exception('محتوى الملف فارغ');
            }
            
            $uploader = new GitHubUploader();
            $result = $uploader->uploadContent($m3u_content, $filename, $github_path);
            
            echo json_encode(['success' => true, 'url' => $result]);
            
        } else {
            throw new Exception('إجراء غير معروف');
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit;
}

/**
 * وظيفة تحميل محسنة تستخدم cURL مع إعدادات تحاكي المتصفح و wget
 */
function fetchWithImprovedMethod($url, $proxy_index) {
    global $proxies;
    
    $proxy_prefix = $proxies[$proxy_index] ?? '';
    $final_url = !empty($proxy_prefix) ? $proxy_prefix . urlencode($url) : $url;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $final_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    
    // إعدادات الهيدر لمحاكاة متصفح حقيقي وتجنب الحظر
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: */*',
        'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
        'Connection: keep-alive',
    ];
    
    if (!empty($proxy_prefix) && strpos($proxy_prefix, 'cors-anywhere') !== false) {
        $headers[] = 'Origin: https://github.com';
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    // محاكاة سلوك wget في التعامل مع الروابط التي قد تتطلب كوكيز أو جلسات
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    
    $content = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL Error: " . $error);
    }
    
    if ($http_code >= 400) {
        throw new Exception("Server returned HTTP $http_code");
    }
    
    return $content;
}

class GitHubUploader {
    
    private $token;
    private $repo;
    private $branch;
    
    public function __construct() {
        $this->token = GITHUB_TOKEN;
        $this->repo = GITHUB_REPO;
        $this->branch = GITHUB_BRANCH;
    }
    
    public function uploadContent($content, $filename, $remote_path = null) {
        $local_path = TEMP_DIR . $filename;
        file_put_contents($local_path, $content);
        
        $result = $this->uploadToGithub($local_path, $remote_path, $filename);
        $this->cleanTemp($local_path);
        
        return $result;
    }
    
    public function uploadToGithub($file_path, $remote_path = null, $filename = null) {
        if (!file_exists($file_path)) {
            throw new Exception("الملف غير موجود محلياً");
        }
        
        $content = file_get_contents($file_path);
        $encoded = base64_encode($content);
        
        if ($filename === null) {
            $filename = basename($file_path);
        }
        
        if (empty($remote_path)) {
            $remote_path = $filename;
        } else {
            $remote_path = rtrim($remote_path, '/') . '/' . $filename;
        }
        
        $remote_path = ltrim($remote_path, '/');
        
        $existing_sha = $this->getFileSha($remote_path);
        
        $data = [
            'message' => 'تحديث قائمة التشغيل تلقائياً - ' . date('Y-m-d H:i:s'),
            'content' => $encoded,
            'branch' => $this->branch
        ];
        
        if ($existing_sha) {
            $data['sha'] = $existing_sha;
        }
        
        $url = "https://api.github.com/repos/{$this->repo}/contents/{$remote_path}";
        $result = $this->githubRequest($url, 'PUT', $data);
        
        if (isset($result['content'])) {
            return "https://raw.githubusercontent.com/{$this->repo}/{$this->branch}/{$remote_path}";
        }
        
        throw new Exception("فشل الرفع إلى GitHub: " . ($result['message'] ?? 'خطأ غير معروف'));
    }
    
    private function getFileSha($path) {
        $url = "https://api.github.com/repos/{$this->repo}/contents/{$path}";
        try {
            $result = $this->githubRequest($url, 'GET');
            return $result['sha'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function githubRequest($url, $method = 'GET', $data = null) {
        $ch = curl_init();
        
        $headers = [
            'User-Agent: M3U-Uploader-Pro/2.0',
            'Authorization: token ' . $this->token,
            'Accept: application/vnd.github.v3+json'
        ];
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($method === 'PUT' && $data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 404 && $method === 'GET') {
            return null;
        }
        
        $decoded = json_decode($response, true);
        
        if ($http_code >= 400) {
            $msg = $decoded['message'] ?? "HTTP $http_code";
            throw new Exception("GitHub API Error: $msg");
        }
        
        return $decoded;
    }
    
    public function cleanTemp($file_path = null) {
        if ($file_path && file_exists($file_path)) {
            unlink($file_path);
        }
    }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M3U GitHub Uploader Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --secondary: #7c3aed;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #1e293b;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Tajawal', sans-serif; }
        body { background-color: var(--bg); color: var(--text); padding: 20px; line-height: 1.6; }
        .container { max-width: 800px; margin: 40px auto; background: var(--card); padding: 40px; border-radius: 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        h1 { text-align: center; margin-bottom: 30px; color: var(--primary); font-size: 28px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 700; color: #475569; }
        input, select { width: 100%; padding: 14px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 16px; transition: all 0.3s; }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); }
        button { width: 100%; padding: 16px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; border: none; border-radius: 12px; font-size: 18px; font-weight: 700; cursor: pointer; transition: transform 0.2s, opacity 0.2s; }
        button:hover { transform: translateY(-2px); opacity: 0.9; }
        button:disabled { background: #94a3b8; cursor: not-allowed; transform: none; }
        .status-box { margin-top: 25px; padding: 20px; border-radius: 12px; display: none; }
        .success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; display: block; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; display: block; }
        .loading { display: none; text-align: center; margin: 20px 0; }
        .spinner { border: 4px solid rgba(0,0,0,0.1); width: 36px; height: 36px; border-radius: 50%; border-left-color: var(--primary); animation: spin 1s linear infinite; display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .link-display { background: #f1f5f9; padding: 12px; border-radius: 8px; font-family: monospace; font-size: 14px; margin-top: 10px; word-break: break-all; border: 1px dashed #cbd5e1; }
    </style>
</head>
<body>
    <div class="container">
        <h1>M3U GitHub Uploader Pro</h1>
        
        <div class="form-group">
            <label>رابط ملف M3U:</label>
            <input type="url" id="m3u_url" placeholder="http://example.com/playlist.m3u" required>
        </div>

        <div class="form-group">
            <label>اسم الملف في GitHub:</label>
            <input type="text" id="filename" placeholder="playlist.m3u">
        </div>

        <div class="form-group">
            <label>المسار (اختياري):</label>
            <input type="text" id="github_path" placeholder="lists/iptv">
        </div>

        <div class="form-group">
            <label>وضع التحميل:</label>
            <select id="proxy_mode">
                <option value="0">تحميل مباشر (موصى به)</option>
                <option value="1">عبر AllOrigins Proxy</option>
                <option value="2">عبر CorsProxy.io</option>
            </select>
        </div>

        <button id="start_btn">بدء عملية التحميل والرفع</button>

        <div class="loading" id="loader">
            <div class="spinner"></div>
            <p id="loader_text">جاري التحميل...</p>
        </div>

        <div id="status" class="status-box"></div>
    </div>

    <script>
        const startBtn = document.getElementById('start_btn');
        const statusBox = document.getElementById('status');
        const loader = document.getElementById('loader');
        const loaderText = document.getElementById('loader_text');

        startBtn.addEventListener('click', async () => {
            const url = document.getElementById('m3u_url').value;
            if (!url) return alert('يرجى إدخال الرابط');

            startBtn.disabled = true;
            statusBox.style.display = 'none';
            loader.style.display = 'block';
            loaderText.innerText = 'جاري تحميل الملف من السيرفر...';

            try {
                // المرحلة 1: التحميل
                const fetchForm = new FormData();
                fetchForm.append('action', 'fetch_via_proxy');
                fetchForm.append('url', url);
                fetchForm.append('proxy_index', document.getElementById('proxy_mode').value);

                const fetchRes = await fetch('', { method: 'POST', body: fetchForm });
                const fetchData = await fetchRes.json();

                if (!fetchData.success) throw new Error(fetchData.error);

                loaderText.innerText = 'تم التحميل بنجاح! جاري الرفع إلى GitHub...';

                // المرحلة 2: الرفع
                const uploadForm = new FormData();
                uploadForm.append('action', 'upload_content');
                uploadForm.append('m3u_content', fetchData.content);
                uploadForm.append('filename', document.getElementById('filename').value || 'playlist.m3u');
                uploadForm.append('github_path', document.getElementById('github_path').value);

                const uploadRes = await fetch('', { method: 'POST', body: uploadForm });
                const uploadData = await uploadRes.json();

                if (!uploadData.success) throw new Error(uploadData.error);

                statusBox.className = 'status-box success';
                statusBox.innerHTML = `
                    <strong>✅ اكتملت العملية بنجاح!</strong><br>
                    تم رفع الملف وتحديثه في GitHub.<br>
                    <div class="link-display">${uploadData.url}</div>
                `;
            } catch (err) {
                statusBox.className = 'status-box error';
                statusBox.innerHTML = `<strong>❌ خطأ:</strong> ${err.message}`;
            } finally {
                startBtn.disabled = false;
                loader.style.display = 'none';
                statusBox.style.display = 'block';
            }
        });
    </script>
</body>
</html>
