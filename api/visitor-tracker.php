<?php
// Visitor Tracking Script
// Logs visitor information including IP, location, referrer, and user agent
// Sends email notification via Resend API for real visitors

require_once __DIR__ . '/config.php';

define('NOTIFICATION_EMAIL', 'lbbusiness2025@gmail.com');
define('BLOCK_BASE_URL', 'https://businessintuitive.tech/api');

function generateBlockToken($payload) {
    $raw = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $raw, BLOCK_SECRET, true)), '+/', '-_'), '=');
    return $raw . '.' . $sig;
}

function sendEmailNotification($visitorData) {
    $ch = curl_init();
    
    $location = $visitorData['location'];
    $locationStr = "{$location['city']}, {$location['region']}, {$location['country']}";
    $exp = (time() + 7 * 24 * 3600) * 1000;

    $ipToken = generateBlockToken(['ip' => $visitorData['ip'], 'exp' => $exp]);
    $blockIpUrl = BLOCK_BASE_URL . '/block-ip.php?token=' . urlencode($ipToken);

    $countryCode = $location['countryCode'] ?? '';
    $blockCountryBtn = '';
    if (!empty($countryCode) && $countryCode !== 'Unknown') {
        $countryToken = generateBlockToken(['country' => $countryCode, 'exp' => $exp]);
        $blockCountryUrl = BLOCK_BASE_URL . '/block-country.php?token=' . urlencode($countryToken) . '&country=' . urlencode($countryCode);
        $blockCountryBtn = "<a href='{$blockCountryUrl}' style='display:inline-block;background:#7c2d12;color:#fff;text-decoration:none;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:600;margin-left:10px'>Block {$countryCode}</a>";
    }

    $emailBody = "
    <html>
    <body style='font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: #080808; border-radius: 10px;'>
            <h2 style='color: #00d4aa; margin-bottom: 20px;'>New Website Visitor</h2>
            
            <div style='background: #111; padding: 20px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #222;'>
                <h3 style='margin-top: 0; color: #666; font-size: 14px; letter-spacing: 0.1em; text-transform: uppercase;'>Visitor Details</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; width: 140px; color: #999;'>Time:</td>
                        <td style='padding: 8px 0; color: #e8e8e8;'>{$visitorData['timestamp']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #999;'>IP Address:</td>
                        <td style='padding: 8px 0; color: #e8e8e8;'>{$visitorData['ip']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #999;'>Location:</td>
                        <td style='padding: 8px 0; color: #e8e8e8;'>{$locationStr}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #999;'>ISP:</td>
                        <td style='padding: 8px 0; color: #e8e8e8;'>{$location['isp']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #999;'>Page:</td>
                        <td style='padding: 8px 0; color: #e8e8e8;'>{$visitorData['page']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #999;'>Referrer:</td>
                        <td style='padding: 8px 0; color: #e8e8e8;'>{$visitorData['referrer']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #999;'>Browser:</td>
                        <td style='padding: 8px 0; color: #e8e8e8;'>{$visitorData['browser']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #999;'>Device:</td>
                        <td style='padding: 8px 0; color: #e8e8e8;'>{$visitorData['deviceType']}</td>
                    </tr>
                </table>
            </div>

            <div style='padding: 16px 0; border-top: 1px solid #222; margin-top: 4px;'>
                <a href='{$blockIpUrl}' style='display:inline-block;background:#dc2626;color:#fff;text-decoration:none;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:600'>Block this IP</a>
                {$blockCountryBtn}
            </div>
            
            <p style='color: #666; font-size: 12px; margin-top: 20px;'>
                Business Intuitive &mdash; Geometric Landing Page
            </p>
        </div>
    </body>
    </html>
    ";
    
    $data = [
        'from' => 'Business Intuitive <onboarding@resend.dev>',
        'to' => [NOTIFICATION_EMAIL],
        'subject' => "New Visitor from {$locationStr}",
        'html' => $emailBody
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.resend.com/emails',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
}

function getVisitorIP() {
    $ip = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

function getLocationFromIP($ip) {
    $url = "http://ip-api.com/json/{$ip}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        $data = json_decode($response, true);
        if ($data && $data['status'] === 'success') {
            return [
                'country' => $data['country'] ?? 'Unknown',
                'countryCode' => $data['countryCode'] ?? '',
                'region' => $data['regionName'] ?? 'Unknown',
                'city' => $data['city'] ?? 'Unknown',
                'zip' => $data['zip'] ?? 'Unknown',
                'lat' => $data['lat'] ?? 0,
                'lon' => $data['lon'] ?? 0,
                'isp' => $data['isp'] ?? 'Unknown'
            ];
        }
    }
    
    return [
        'country' => 'Unknown',
        'region' => 'Unknown',
        'city' => 'Unknown',
        'zip' => 'Unknown',
        'lat' => 0,
        'lon' => 0,
        'isp' => 'Unknown'
    ];
}

function isBot($userAgent, $ip) {
    $botPatterns = [
        '/bot/i', '/crawl/i', '/spider/i', '/slurp/i',
        '/google/i', '/bing/i', '/yahoo/i', '/baidu/i',
        '/yandex/i', '/duckduck/i', '/facebook/i', '/twitter/i',
        '/linkedin/i', '/pinterest/i', '/whatsapp/i',
        '/curl/i', '/wget/i', '/python/i', '/java/i',
        '/http/i', '/libwww/i', '/perl/i', '/php/i',
        '/monitoring/i', '/uptime/i', '/pingdom/i', '/newrelic/i',
        '/lighthouse/i', '/gtmetrix/i', '/pagespeed/i'
    ];
    
    foreach ($botPatterns as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            return true;
        }
    }
    
    $botIPs = [
        '35.230.', '35.242.',
        '2001:19f0:',
        '66.249.',
        '157.55.',
    ];
    
    foreach ($botIPs as $botIP) {
        if (strpos($ip, $botIP) === 0) {
            return true;
        }
    }
    
    if (isset($_GET['LSCWP_CTRL']) || strpos($_SERVER['REQUEST_URI'] ?? '', 'LSCWP_CTRL') !== false) {
        return true;
    }
    
    if ($userAgent === 'Unknown' || empty($userAgent)) {
        return true;
    }
    
    return false;
}

function logVisitor() {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/visitor-logs.json';
    $botLogFile = $logDir . '/bot-logs.json';
    
    $ip = getVisitorIP();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $location = getLocationFromIP($ip);
    $referrer = $_SERVER['HTTP_REFERER'] ?? 'Direct';
    $page = $_SERVER['REQUEST_URI'] ?? '/';
    $timestamp = date('Y-m-d H:i:s');
    
    $isBot = isBot($userAgent, $ip);
    
    if ($isBot) {
        $logFile = $botLogFile;
    }
    
    $deviceType = 'Desktop';
    if (preg_match('/mobile|android|iphone|ipad|tablet/i', $userAgent)) {
        if (preg_match('/ipad|tablet/i', $userAgent)) {
            $deviceType = 'Tablet';
        } else {
            $deviceType = 'Mobile';
        }
    }
    
    $browser = 'Unknown';
    if (preg_match('/MSIE|Trident/i', $userAgent)) {
        $browser = 'Internet Explorer';
    } elseif (preg_match('/Edge/i', $userAgent)) {
        $browser = 'Microsoft Edge';
    } elseif (preg_match('/Chrome/i', $userAgent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Safari/i', $userAgent)) {
        $browser = 'Safari';
    } elseif (preg_match('/Firefox/i', $userAgent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Opera|OPR/i', $userAgent)) {
        $browser = 'Opera';
    }
    
    $botType = 'Human';
    if ($isBot) {
        if (strpos($ip, '35.230.') === 0 || strpos($ip, '35.242.') === 0) {
            $botType = 'LiteSpeed Cache';
        } elseif (strpos($ip, '2001:19f0:') === 0) {
            $botType = 'Uptime Monitor';
        } elseif (preg_match('/google/i', $userAgent)) {
            $botType = 'Googlebot';
        } elseif (preg_match('/bing/i', $userAgent)) {
            $botType = 'Bingbot';
        } elseif (strpos($page, 'LSCWP_CTRL') !== false) {
            $botType = 'LiteSpeed Optimization';
        } else {
            $botType = 'Unknown Bot';
        }
    }
    
    $visitorData = [
        'timestamp' => $timestamp,
        'ip' => $ip,
        'location' => $location,
        'page' => $page,
        'referrer' => $referrer,
        'userAgent' => $userAgent,
        'browser' => $browser,
        'deviceType' => $deviceType,
        'isBot' => $isBot,
        'botType' => $botType
    ];
    
    $logs = [];
    if (file_exists($logFile)) {
        $content = file_get_contents($logFile);
        $logs = json_decode($content, true) ?? [];
    }
    
    $logs[] = $visitorData;
    
    if (count($logs) > 1000) {
        $logs = array_slice($logs, -1000);
    }
    
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT));
    
    if (!$isBot && !hasSeenToday($ip)) {
        markSeenToday($ip);
        sendEmailNotification($visitorData);
    }
}

function seenTodayFile() {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    return $logDir . '/visitor-seen-' . date('Y-m-d') . '.json';
}

function hasSeenToday($ip) {
    $file = seenTodayFile();
    if (!file_exists($file)) return false;
    $seen = json_decode(file_get_contents($file), true) ?? [];
    $key = hash('sha256', date('Y-m-d') . '|' . $ip);
    return isset($seen[$key]);
}

function markSeenToday($ip) {
    $file = seenTodayFile();
    $seen = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
    $key = hash('sha256', date('Y-m-d') . '|' . $ip);
    $seen[$key] = time();
    file_put_contents($file, json_encode($seen));
}

logVisitor();
?>
