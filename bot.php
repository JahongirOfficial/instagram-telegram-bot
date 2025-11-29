<?php
/**
 * Instagram Telegram Bot - PHP versiyasi
 * Webhook orqali ishlaydi
 */

// Konfiguratsiya
$config = [
    'bot_token' => '8038376421:AAFtbldLbquVurnRlc6mf08k_bx6xEwcc1I', // .env dan o'qish yoki shu yerga yozing
    'debug' => true
];

// .env faylidan o'qish (agar mavjud bo'lsa)
if (file_exists(__DIR__ . '/.env')) {
    $env = parse_ini_file(__DIR__ . '/.env');
    if (isset($env['TELEGRAM_BOT_TOKEN'])) {
        $config['bot_token'] = $env['TELEGRAM_BOT_TOKEN'];
    }
}

// Telegram API
class TelegramBot {
    private $token;
    private $apiUrl;

    public function __construct($token) {
        $this->token = $token;
        $this->apiUrl = "https://api.telegram.org/bot{$token}/";
    }

    public function sendMessage($chatId, $text, $parseMode = null) {
        $params = [
            'chat_id' => $chatId,
            'text' => $text
        ];
        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }
        return $this->request('sendMessage', $params);
    }

    private function request($method, $params = []) {
        $ch = curl_init($this->apiUrl . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }
}


// Instagram ma'lumotlarini olish
class InstagramParser {
    
    public function getInfo($url) {
        $postId = $this->extractPostId($url);
        if (!$postId) {
            throw new Exception('Post ID topilmadi');
        }

        $caption = '';
        $author = "Noma'lum";

        // 1-usul: oEmbed API
        $oembedData = $this->tryOembed($url);
        if ($oembedData) {
            $caption = $oembedData['title'] ?? '';
            $author = $oembedData['author'] ?? $author;
        }

        // 2-usul: Embed sahifa
        if (empty($caption)) {
            $embedData = $this->tryEmbed($postId);
            if ($embedData) {
                $caption = $embedData['caption'] ?? $caption;
                $author = $embedData['author'] ?? $author;
            }
        }

        // 3-usul: Asosiy sahifa
        if (empty($caption)) {
            $mainData = $this->tryMainPage($postId);
            if ($mainData) {
                $caption = $mainData['caption'] ?? $caption;
                $author = $mainData['author'] ?? $author;
            }
        }

        // Agar hech bo'lmasa author topilgan bo'lsa, davom etamiz
        if (empty($caption) && $author === "Noma'lum") {
            error_log("Instagram: hech qanday ma'lumot topilmadi - postId: $postId");
            throw new Exception("Ma'lumotlar topilmadi");
        }
        
        error_log("Instagram OK: author=$author, caption_len=" . strlen($caption));

        // Hashtag larni olish
        preg_match_all('/#[\w\x{0400}-\x{04FF}а-яА-Я]+/u', $caption, $matches);
        $hashtags = $matches[0] ?? [];

        return [
            'title' => $caption,
            'author' => $author,
            'hashtags' => $hashtags
        ];
    }

    private function extractPostId($url) {
        if (preg_match('/instagram\.com\/(p|reel|reels|tv)\/([\w-]+)/i', $url, $matches)) {
            return $matches[2];
        }
        return null;
    }

    private function tryOembed($url) {
        try {
            $oembedUrl = 'https://api.instagram.com/oembed/?url=' . urlencode($url);
            $response = $this->httpGet($oembedUrl);
            if ($response) {
                $data = json_decode($response, true);
                if ($data) {
                    return [
                        'title' => $data['title'] ?? '',
                        'author' => $data['author_name'] ?? ''
                    ];
                }
            }
        } catch (Exception $e) {}
        return null;
    }

    private function tryEmbed($postId) {
        try {
            // Avval reel uchun, keyin p uchun sinab ko'ramiz
            $urls = [
                "https://www.instagram.com/reel/{$postId}/embed/captioned/",
                "https://www.instagram.com/p/{$postId}/embed/captioned/",
                "https://www.instagram.com/reel/{$postId}/embed/",
                "https://www.instagram.com/p/{$postId}/embed/"
            ];
            
            foreach ($urls as $embedUrl) {
                $html = $this->httpGet($embedUrl);
                if (!$html) continue;

                $caption = '';
                $author = '';

                // Caption ni olish - bir necha pattern
                if (preg_match('/"caption"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $html, $m)) {
                    $caption = $this->decodeUnicode($m[1]);
                } elseif (preg_match('/class="Caption"[^>]*>(.*?)<\/div>/s', $html, $m)) {
                    $caption = strip_tags($m[1]);
                } elseif (preg_match('/<meta[^>]*property="og:description"[^>]*content="([^"]+)"/i', $html, $m)) {
                    $caption = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                }
                
                // Author ni olish
                if (preg_match('/"username"\s*:\s*"([^"]+)"/', $html, $m)) {
                    $author = $m[1];
                } elseif (preg_match('/class="UsernameText"[^>]*>([^<]+)</i', $html, $m)) {
                    $author = trim($m[1]);
                }

                if ($caption || $author) {
                    error_log("Embed OK: $embedUrl");
                    return ['caption' => $caption, 'author' => $author];
                }
            }
        } catch (Exception $e) {
            error_log("Embed error: " . $e->getMessage());
        }
        return null;
    }

    private function tryMainPage($postId) {
        try {
            $urls = [
                "https://www.instagram.com/reel/{$postId}/",
                "https://www.instagram.com/p/{$postId}/"
            ];
            
            foreach ($urls as $mainUrl) {
                $html = $this->httpGet($mainUrl);
                if (!$html) continue;

                $caption = '';
                $author = '';

                // Meta teglardan olish
                if (preg_match('/<meta[^>]*property="og:description"[^>]*content="([^"]+)"/i', $html, $m)) {
                    $caption = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                }
                
                if (preg_match('/<meta[^>]*property="og:title"[^>]*content="([^"]+)"/i', $html, $m)) {
                    $title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                    // "Author on Instagram: caption..." formatidan
                    if (preg_match('/^(.+?)\s+on\s+Instagram/i', $title, $am)) {
                        $author = trim($am[1]);
                    }
                    // Agar caption bo'sh bo'lsa, title dan olish
                    if (empty($caption) && preg_match('/Instagram[:\s]+["\']?(.+)/i', $title, $cm)) {
                        $caption = trim($cm[1], ' "\'');
                    }
                }
                
                // Title tegidan
                if (empty($author) && preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
                    $title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                    if (preg_match('/^(.+?)\s+on\s+Instagram/i', $title, $am)) {
                        $author = trim($am[1]);
                    }
                }

                if ($caption || $author) {
                    error_log("MainPage OK: $mainUrl");
                    return ['caption' => $caption, 'author' => $author];
                }
            }
        } catch (Exception $e) {
            error_log("MainPage error: " . $e->getMessage());
        }
        return null;
    }

    private function httpGet($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
                'Upgrade-Insecure-Requests: 1',
                'Cache-Control: max-age=0'
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("CURL Error: $error");
        }
        error_log("HTTP $httpCode for $url");
        
        return ($httpCode >= 200 && $httpCode < 400) ? $response : null;
    }

    private function decodeUnicode($str) {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($m) {
            return mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UCS-2BE');
        }, str_replace('\\n', "\n", $str));
    }
}


// Instagram URL ni tekshirish
function isInstagramUrl($url) {
    return preg_match('/instagram\.com\/(p|reel|reels|tv)\/[\w-]+/i', $url);
}

// Markdown escape
function escapeMarkdown($text) {
    return preg_replace('/([_*`\[])/', '\\\\$1', $text ?? '');
}

// Asosiy bot logikasi
function handleUpdate($update, $bot) {
    if (!isset($update['message']['text'])) {
        return;
    }

    $chatId = $update['message']['chat']['id'];
    $text = trim($update['message']['text']);

    // /start buyrug'i
    if ($text === '/start') {
        $bot->sendMessage($chatId, 
            "👋 Salom! Men Instagram video ma'lumotlarini oluvchi botman.\n\n" .
            "📹 Menga Instagram video/reel havolasini yuboring va men sizga:\n" .
            "• Video nomi (caption)\n" .
            "• Teglar (hashtags)\n" .
            "• Muallif ismini yuboraman.\n\n" .
            "🔗 Misol: https://www.instagram.com/reel/ABC123/"
        );
        return;
    }

    // Instagram URL ni tekshirish
    if (isInstagramUrl($text)) {
        $bot->sendMessage($chatId, "⏳ Ma'lumotlar yuklanmoqda...");

        try {
            $parser = new InstagramParser();
            $info = $parser->getInfo($text);

            $safeAuthor = escapeMarkdown($info['author']);
            $safeTitle = escapeMarkdown($info['title']);
            $safeHashtags = implode(' ', array_map('escapeMarkdown', $info['hashtags']));

            $response = "📹 *Instagram Video Ma'lumotlari*\n\n";
            $response .= "👤 *Muallif:* {$safeAuthor}\n\n";

            if (!empty($info['title'])) {
                $response .= "📝 *Caption:*\n{$safeTitle}\n\n";
            }

            if (!empty($info['hashtags'])) {
                $count = count($info['hashtags']);
                $response .= "🏷 *Teglar ({$count} ta):*\n{$safeHashtags}\n";
            } else {
                $response .= "🏷 *Teglar:* Teglar topilmadi\n";
            }

            $bot->sendMessage($chatId, $response, 'Markdown');

        } catch (Exception $e) {
            $bot->sendMessage($chatId,
                "❌ Xatolik yuz berdi.\n\n" .
                "Mumkin sabablar:\n" .
                "• Post yopiq (private) akkauntda\n" .
                "• Havola noto'g'ri\n" .
                "• Instagram cheklovlari\n\n" .
                "Iltimos, ochiq akkauntdagi postni yuboring."
            );
        }
    } elseif (strpos($text, 'instagram.com') !== false) {
        $bot->sendMessage($chatId, "⚠️ Iltimos, to'g'ri Instagram video/reel havolasini yuboring.");
    }
}

// Webhook yoki Long Polling
$bot = new TelegramBot($config['bot_token']);

// Webhook rejimi (serverda ishlatish uchun)
$input = file_get_contents('php://input');
if ($input) {
    $update = json_decode($input, true);
    if ($update) {
        handleUpdate($update, $bot);
    }
    exit;
}

// Long Polling rejimi (lokal test uchun)
echo "🤖 Bot ishga tushdi (Long Polling rejimi)...\n";

$offset = 0;
while (true) {
    $ch = curl_init("https://api.telegram.org/bot{$config['bot_token']}/getUpdates?offset={$offset}&timeout=30");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 35
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (isset($data['result'])) {
        foreach ($data['result'] as $update) {
            $offset = $update['update_id'] + 1;
            handleUpdate($update, $bot);
        }
    }
    
    sleep(1);
}
