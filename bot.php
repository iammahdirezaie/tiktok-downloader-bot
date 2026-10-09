<?php
if (!file_exists(__DIR__ . '/config.php')) {
    die("Error: config.php not found. Please duplicate config.example.php to config.php and enter your credentials.");
}
require_once __DIR__ . '/config.php';

date_default_timezone_set('Asia/Tehran');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);

function logError($message) {
    $time = date('Y/m/d H:i:s');
    file_put_contents(__DIR__ . '/error.log', "[{$time}] {$message}\n", FILE_APPEND);
}

// دیتابیس SQLite
$db = new SQLite3(__DIR__ . '/bot.db');
$db->busyTimeout(60000);
$db->exec("PRAGMA journal_mode = WAL;");
$db->exec("PRAGMA synchronous = NORMAL;");

$db->exec("CREATE TABLE IF NOT EXISTS users (
    user_id INTEGER PRIMARY KEY,
    step TEXT DEFAULT 'none',
    temp_data TEXT DEFAULT '',
    last_date TEXT DEFAULT '',
    downloads_count INTEGER DEFAULT 0
)");

$db->exec("CREATE TABLE IF NOT EXISTS audio_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    audio_url TEXT,
    created_at INTEGER
)");

$db->exec("CREATE TABLE IF NOT EXISTS channels (
    channel_id TEXT PRIMARY KEY,
    invite_link TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, val TEXT)");
$db->exec("INSERT OR IGNORE INTO settings (key, val) VALUES ('maintenance_mode', 'off')");
$db->exec("INSERT OR IGNORE INTO settings (key, val) VALUES ('last_db_backup_time', '0')");
$db->exec("INSERT OR IGNORE INTO settings (key, val) VALUES ('total_downloads', '0')");
$db->exec("INSERT OR IGNORE INTO settings (key, val) VALUES ('ad_status', 'off')");
$db->exec("INSERT OR IGNORE INTO settings (key, val) VALUES ('ad_text', '🌟 به کانال ما بپیوندید')");
$db->exec("INSERT OR IGNORE INTO settings (key, val) VALUES ('ad_photo', '')");

function getSetting($key) {
    global $db;
    $stmt = $db->prepare("SELECT val FROM settings WHERE key = :key");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $res ? $res['val'] : null;
}

function setSetting($key, $val) {
    global $db;
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, val) VALUES (:key, :val)");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':val', $val, SQLITE3_TEXT);
    $stmt->execute();
}

function getUser($user_id) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = :uid");
    $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $today = date('Y-m-d');

    if (!$user) {
        $stmt = $db->prepare("INSERT INTO users (user_id, step, temp_data, last_date, downloads_count) VALUES (:uid, 'none', '', :ld, 0)");
        $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(':ld', $today, SQLITE3_TEXT);
        $stmt->execute();
        return ['user_id' => $user_id, 'step' => 'none', 'temp_data' => '', 'last_date' => $today, 'downloads_count' => 0];
    } else {
        if ($user['last_date'] !== $today) {
            $stmt = $db->prepare("UPDATE users SET last_date = :ld WHERE user_id = :uid");
            $stmt->bindValue(':ld', $today, SQLITE3_TEXT);
            $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
            $stmt->execute();
            $user['last_date'] = $today;
        }
    }
    return $user;
}

function setUserStep($user_id, $step, $temp_data = '') {
    global $db;
    $stmt = $db->prepare("UPDATE users SET step = :step, temp_data = :temp WHERE user_id = :uid");
    $stmt->bindValue(':step', $step, SQLITE3_TEXT);
    $stmt->bindValue(':temp', $temp_data, SQLITE3_TEXT);
    $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
    $stmt->execute();
}

function incrementStats($user_id) {
    global $db;
    $stmt = $db->prepare("UPDATE users SET downloads_count = downloads_count + 1 WHERE user_id = :uid");
    $stmt->bindValue(':uid', $user_id, SQLITE3_INTEGER);
    $stmt->execute();

    $total = intval(getSetting('total_downloads') ?: 0) + 1;
    setSetting('total_downloads', (string)$total);
}

function saveAudioUrlToCache($audio_url) {
    global $db;
    $stmt = $db->prepare("INSERT INTO audio_cache (audio_url, created_at) VALUES (:url, :ca)");
    $stmt->bindValue(':url', $audio_url, SQLITE3_TEXT);
    $stmt->bindValue(':ca', time(), SQLITE3_INTEGER);
    $stmt->execute();
    return $db->lastInsertRowID();
}

function getAudioUrlFromCache($audio_id) {
    global $db;
    $stmt = $db->prepare("SELECT audio_url FROM audio_cache WHERE id = :id");
    $stmt->bindValue(':id', $audio_id, SQLITE3_INTEGER);
    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $res ? $res['audio_url'] : null;
}

function request($method, $data = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    $res = curl_exec($ch);
    if (curl_errno($ch)) {
        logError("cURL Error in {$method}: " . curl_error($ch));
    } else {
        $json = json_decode($res, true);
        if (isset($json['ok']) && !$json['ok']) {
            logError("Telegram Error [{$method}]: " . ($json['description'] ?? ''));
        }
    }
    curl_close($ch);
    return json_decode($res, true);
}

function sendChatAction($chat_id, $action = 'upload_video') {
    return request('sendChatAction', ['chat_id' => $chat_id, 'action' => $action]);
}

function setReaction($chat_id, $message_id, $emoji = '❤️‍🔥') {
    $reaction = json_encode([['type' => 'emoji', 'emoji' => $emoji]]);
    return request('setMessageReaction', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'reaction' => $reaction
    ]);
}

function checkUserJoined($user_id) {
    global $db;
    if ($user_id == OWNER_ID) return ['joined' => true, 'buttons' => []];

    $res = $db->query("SELECT * FROM channels");
    $unjoined_buttons = [];

    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $ch_id = $row['channel_id'];
        $check = request('getChatMember', [
            'chat_id' => $ch_id,
            'user_id' => $user_id
        ]);

        $status = $check['result']['status'] ?? '';
        if (!in_array($status, ['member', 'administrator', 'creator'])) {
            $link = !empty($row['invite_link']) ? $row['invite_link'] : "https://t.me/" . ltrim($ch_id, '@');
            $unjoined_buttons[] = [['text' => '📢 عضویت در کانال', 'url' => $link]];
        }
    }

    if (!empty($unjoined_buttons)) {
        $unjoined_buttons[] = [['text' => '🔄 تایید عضویت', 'callback_data' => 'check_join']];
        return ['joined' => false, 'buttons' => $unjoined_buttons];
    }

    return ['joined' => true, 'buttons' => []];
}

function sendAdAfterSuccess($chat_id) {
    $ad_status = getSetting('ad_status');
    if ($ad_status === 'on') {
        $ad_text = getSetting('ad_text');
        $ad_photo = getSetting('ad_photo');
        if (!empty($ad_photo)) {
            sendChatAction($chat_id, 'upload_photo');
            request('sendPhoto', [
                'chat_id' => $chat_id,
                'photo' => $ad_photo,
                'caption' => $ad_text
            ]);
        } else {
            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $ad_text
            ]);
        }
    }
}

function fetchCurlData($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function downloadDirectToFile($url, $destination) {
    $fp = fopen($destination, 'wb');
    if (!$fp) return false;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    return ($res && $code == 200 && file_exists($destination) && filesize($destination) > 1024);
}

function getTikTokMedia($url) {
    $clean_url = trim($url);
    $clean_url = preg_replace('/[^\x20-\x7E]/', '', $clean_url);

    $ch = curl_init($clean_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15');
    curl_exec($ch);
    $real_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if (empty($real_url)) $real_url = $clean_url;

    $parsed = parse_url($real_url);
    $real_clean = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '');
    $real_clean = rtrim($real_clean, '/');

    $res_tikwm = fetchCurlData("https://www.tikwm.com/api/?url=" . urlencode($real_clean) . "&hd=1");
    if ($res_tikwm) {
        $j = json_decode($res_tikwm, true);
        if (isset($j['data'])) {
            $data = $j['data'];
            $author = $data['author']['unique_id'] ?? ($data['author']['nickname'] ?? 'TikTok');
            $caption = $data['title'] ?? '';
            $music = !empty($data['music']) ? $data['music'] : ($data['music_info']['play'] ?? null);

            if (!empty($data['images']) && is_array($data['images'])) {
                return [
                    'type' => 'album',
                    'images' => $data['images'],
                    'caption' => $caption,
                    'author' => $author,
                    'music' => $music,
                    'url' => $real_clean
                ];
            }

            $video_url = !empty($data['hdplay']) ? $data['hdplay'] : ($data['play'] ?? null);
            if ($video_url) {
                return [
                    'type' => 'video',
                    'url' => $video_url,
                    'caption' => $caption,
                    'author' => $author,
                    'music' => $music,
                    'orig_url' => $real_clean
                ];
            }
        } else {
            logError("TikWM Response Error: " . $res_tikwm);
        }
    }
    return null;
}

function getAdminMenu() {
    $m_status = getSetting('maintenance_mode') === 'on' ? '🔴 خاموش کردن حالت تعمیرات' : '🛠 روشن کردن حالت تعمیرات';
    $keyboard = [
        [['text' => '📊 آمار کلی و روزانه ربات']],
        [['text' => '📢 مدیریت تبلیغات بعد از دانلود']],
        [['text' => '🔒 مدیریت قفل کانال (جوین اجباری)']],
        [['text' => '📨 ارسال پیام همگانی'], ['text' => '📩 ارسال پیام به کاربر']],
        [['text' => $m_status]],
        [['text' => '🏠 بازگشت به منوی اصلی']]
    ];
    return json_encode(['keyboard' => $keyboard, 'resize_keyboard' => true]);
}

$raw_update = file_get_contents('php://input');
if (empty($raw_update)) exit;

$update = json_decode($raw_update, true);
$now_time = time();

// Auto DB Backup every 24h
$last_backup = intval(getSetting('last_db_backup_time'));
if (($now_time - $last_backup) >= 86400) {
    setSetting('last_db_backup_time', (string)$now_time);
    $db_file = __DIR__ . '/bot.db';
    if (file_exists($db_file)) {
        sendChatAction(OWNER_ID, 'upload_document');
        request('sendDocument', [
            'chat_id' => OWNER_ID,
            'document' => new CURLFile($db_file),
            'caption' => "📦 بکاپ خودکار دیتابیس ربات\n📅 تاریخ: " . date('Y/m/d H:i:s')
        ]);
    }
}

// Callbacks
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $cb_id = $cb['id'];
    $data = $cb['data'];
    $cb_chat_id = $cb['message']['chat']['id'];
    $cb_user_id = $cb['from']['id'];

    if ($data === 'check_join') {
        $join_status = checkUserJoined($cb_user_id);
        if ($join_status['joined']) {
            request('answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => '✅ عضویت شما تایید شد! حالا می‌توانید لینک خود را ارسال کنید.', 'show_alert' => true]);
            request('deleteMessage', ['chat_id' => $cb_chat_id, 'message_id' => $cb['message']['message_id']]);
        } else {
            request('answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => '❌ هنوز در کانال‌های زیر عضو نشده‌اید!', 'show_alert' => true]);
        }
        exit;
    }

    if (strpos($data, 'aud_') === 0) {
        $audio_id = intval(substr($data, 4));
        $audio_url = getAudioUrlFromCache($audio_id);

        if (!empty($audio_url)) {
            request('answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => '⏳ در حال ارسال فایل صوتی...']);
            sendChatAction($cb_chat_id, 'upload_voice');

            if (!file_exists(__DIR__ . '/temp')) mkdir(__DIR__ . '/temp', 0777, true);
            $tmp_aud = __DIR__ . '/temp/aud_' . time() . '_' . $audio_id . '.mp3';

            if (downloadDirectToFile($audio_url, $tmp_aud)) {
                request('sendAudio', [
                    'chat_id' => $cb_chat_id,
                    'audio' => new CURLFile($tmp_aud),
                    'caption' => "🎵 موزیک تیک‌تاک\n\n🔍 <a href=\"https://t.me/" . BOT_USERNAME . "\">Download by @" . BOT_USERNAME . "</a>",
                    'parse_mode' => 'HTML'
                ]);
                @unlink($tmp_aud);
            } else {
                request('sendAudio', [
                    'chat_id' => $cb_chat_id,
                    'audio' => $audio_url,
                    'caption' => "🎵 موزیک تیک‌‌تاک\n\n🔍 <a href=\"https://t.me/" . BOT_USERNAME . "\">Download by @" . BOT_USERNAME . "</a>",
                    'parse_mode' => 'HTML'
                ]);
            }
        } else {
            request('answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => '❌ صوت یافت نشد یا منقضی شده است.', 'show_alert' => true]);
        }
    }
    exit;
}

if (isset($update['message'])) {
    $msg = $update['message'];
    $chat_id = $msg['chat']['id'];
    $from_id = $msg['from']['id'];
    $message_id = $msg['message_id'];
    $text = trim($msg['text'] ?? '');

    $user = getUser($from_id);

    if (getSetting('maintenance_mode') === 'on' && $from_id != OWNER_ID) {
        request('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🛠 ربات در حال حاضر در حال تعمیرات و بروزرسانی می‌باشد.\nلطفاً دقایقی دیگر مجدداً تلاش فرمایید. 🙏"
        ]);
        exit;
    }

    if ($text === '/start') {
        setUserStep($from_id, 'none');
        setReaction($chat_id, $message_id, '❤️‍🔥');

        $welcome_text = "به ربات تیک تاک دانلودر خوش آمدید 🖐\n\n"
                      . "🟡 لینک ویدیو تیک تاک را ارسال کن تا در سریع ترین زمان ممکن ویدیوشو برات بفرستم :\n\n"
                      . "📌 مثال :\nhttps://vt.tiktok.com/ZSNoTFjaNV/\n\n"
                      . "👤 @" . BOT_USERNAME;

        $reply_markup = null;
        if ($from_id == OWNER_ID) {
            $reply_markup = json_encode([
                'keyboard' => [
                    [['text' => '⚙️ پنل مدیریت']]
                ],
                'resize_keyboard' => true
            ]);
        }

        request('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $welcome_text,
            'disable_web_page_preview' => true,
            'reply_markup' => $reply_markup
        ]);
        exit;
    }

    // پنل مدیریت
    if ($from_id == OWNER_ID) {
        if ($text === '⚙️ پنل مدیریت' || $text === '🔙 بازگشت به مدیریت') {
            setUserStep($from_id, 'admin_home');
            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "⚙️ به پنل مدیریت ربات خوش آمدید:",
                'reply_markup' => getAdminMenu()
            ]);
            exit;
        }

        if ($text === '🏠 بازگشت به منوی اصلی') {
            setUserStep($from_id, 'none');
            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "منوی مدیریت بسته شد.",
                'reply_markup' => json_encode(['keyboard' => [[['text' => '⚙️ پنل مدیریت']]], 'resize_keyboard' => true])
            ]);
            exit;
        }

        if ($text === '🔒 مدیریت قفل کانال (جوین اجباری)') {
            setUserStep($from_id, 'admin_channels');
            $chs = $db->query("SELECT * FROM channels");
            $list = "📋 لیست کانال‌های تنظیم شده:\n\n";
            $i = 1;
            while ($c = $chs->fetchArray(SQLITE3_ASSOC)) {
                $list .= "{$i}. کانال: <code>{$c['channel_id']}</code>\nلینک: {$c['invite_link']}\n\n";
                $i++;
            }
            if ($i === 1) $list .= "هیچ کانالی تنظیم نشده است.\n\n";

            $f_menu = json_encode([
                'keyboard' => [
                    [['text' => '➕ افزودن کانال اجباری'], ['text' => '➖ حذف کانال اجباری']],
                    [['text' => '🔙 بازگشت به مدیریت']]
                ],
                'resize_keyboard' => true
            ]);

            request('sendMessage', ['chat_id' => $chat_id, 'text' => $list . "یکی از گزینه‌ها را انتخاب کنید:", 'parse_mode' => 'HTML', 'reply_markup' => $f_menu]);
            exit;
        }

        if ($text === '➕ افزودن کانال اجباری') {
            setUserStep($from_id, 'add_channel');
            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "لطفاً آیدی عددی کانال (یا یوزرنیم به همراه @) و لینک عضویت را به فرمت زیر با یک خط فاصله بفرستید:\n\n<code>@mychannel https://t.me/mychannel</code>\n\nنکته: ربات حتماً باید ادمین کانال باشد!",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['keyboard' => [[['text' => '🔙 بازگشت به مدیریت']]], 'resize_keyboard' => true])
            ]);
            exit;
        }

        if ($user['step'] === 'add_channel' && $text !== '🔙 بازگشت به مدیریت') {
            $parts = explode(' ', $text);
            if (count($parts) >= 2) {
                $ch_id = trim($parts[0]);
                $ch_link = trim($parts[1]);

                $stmt = $db->prepare("INSERT OR REPLACE INTO channels (channel_id, invite_link) VALUES (:id, :link)");
                $stmt->bindValue(':id', $ch_id, SQLITE3_TEXT);
                $stmt->bindValue(':link', $ch_link, SQLITE3_TEXT);
                $stmt->execute();

                setUserStep($from_id, 'admin_home');
                request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ کانال با موفقیت ثبت شد.", 'reply_markup' => getAdminMenu()]);
            } else {
                request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فرمت ورودی اشتباه است. لطفاً دقیقاً مانند نمونه ارسال کنید."]);
            }
            exit;
        }

        if ($text === '➖ حذف کانال اجباری') {
            setUserStep($from_id, 'del_channel');
            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "شناسه یا یوزرنیم کانالی که قصد حذف آن را دارید ارسال کنید:",
                'reply_markup' => json_encode(['keyboard' => [[['text' => '🔙 بازگشت به مدیریت']]], 'resize_keyboard' => true])
            ]);
            exit;
        }

        if ($user['step'] === 'del_channel' && $text !== '🔙 بازگشت به مدیریت') {
            $stmt = $db->prepare("DELETE FROM channels WHERE channel_id = :id");
            $stmt->bindValue(':id', $text, SQLITE3_TEXT);
            $stmt->execute();

            setUserStep($from_id, 'admin_home');
            request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ کانال حذف شد.", 'reply_markup' => getAdminMenu()]);
            exit;
        }

        if ($text === '📊 آمار کلی و روزانه ربات') {
            $total_users = $db->querySingle("SELECT COUNT(*) FROM users");
            $today_str = date('Y-m-d');
            
            $stmt_td = $db->prepare("SELECT COUNT(*) FROM users WHERE last_date = :td");
            $stmt_td->bindValue(':td', $today_str, SQLITE3_TEXT);
            $today_active = $stmt_td->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0;

            $total_downloads = getSetting('total_downloads') ?: '0';

            $report = "📊 <b>آمار سیستم ربات تیک‌تاک:</b>\n\n"
                    . "👥 کل کاربران: <b>{$total_users}</b>\n"
                    . "🔥 فعال امروز: <b>{$today_active}</b>\n"
                    . "📥 مجموع کل دانلودها: <b>{$total_downloads}</b>\n\n"
                    . "🕒 تاریخ: " . date('Y/m/d H:i:s');

            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $report,
                'parse_mode' => 'HTML',
                'reply_markup' => getAdminMenu()
            ]);
            exit;
        }

        if ($text === '📢 مدیریت تبلیغات بعد از دانلود') {
            setUserStep($from_id, 'admin_ads');
            $status = getSetting('ad_status') === 'on' ? '🟢 فعال' : '🔴 غیرفعال';
            $ad_text = getSetting('ad_text');
            $ad_photo = getSetting('ad_photo') ? 'دارد ✅' : 'ندارد ❌';

            $ad_menu = json_encode([
                'keyboard' => [
                    [['text' => '🟢 روشن کردن تبلیغ'], ['text' => '🔴 خاموش کردن تبلیغ']],
                    [['text' => '📝 تغییر متن تبلیغ'], ['text' => '🖼 تغییر بنر (عکس) تبلیغ']],
                    [['text' => '🗑 حذف عکس بنر (فقط متن)'], ['text' => '🔙 بازگشت به مدیریت']]
                ],
                'resize_keyboard' => true
            ]);

            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "📢 تنظیمات تبلیغات بعد از دانلود:\n\n▪️ وضعیت: {$status}\n▪️ بنر عکس: {$ad_photo}\n▪️ متن:\n{$ad_text}",
                'reply_markup' => $ad_menu
            ]);
            exit;
        }

        if ($text === '🟢 روشن کردن تبلیغ') {
            setSetting('ad_status', 'on');
            request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ تبلیغات فعال شد."]);
            exit;
        }

        if ($text === '🔴 خاموش کردن تبلیغ') {
            setSetting('ad_status', 'off');
            request('sendMessage', ['chat_id' => $chat_id, 'text' => "🔴 تبلیغات غیرفعال شد."]);
            exit;
        }

        if ($text === '🗑 حذف عکس بنر (فقط متن)') {
            setSetting('ad_photo', '');
            request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ عکس بنر حذف شد."]);
            exit;
        }

        if ($text === '📝 تغییر متن تبلیغ') {
            setUserStep($from_id, 'set_ad_text');
            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "📝 متن جدید تبلیغ را بفرستید:",
                'reply_markup' => json_encode(['keyboard' => [[['text' => '🔙 بازگشت به مدیریت']]], 'resize_keyboard' => true])
            ]);
            exit;
        }

        if ($user['step'] === 'set_ad_text') {
            setSetting('ad_text', $text);
            setUserStep($from_id, 'admin_home');
            request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ متن تبلیغات ذخیره شد.", 'reply_markup' => getAdminMenu()]);
            exit;
        }

        if ($text === '🖼 تغییر بنر (عکس) تبلیغ') {
            setUserStep($from_id, 'set_ad_photo');
            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "🖼 عکس جدید بنر را ارسال کنید:",
                'reply_markup' => json_encode(['keyboard' => [[['text' => '🔙 بازگشت به مدیریت']]], 'resize_keyboard' => true])
            ]);
            exit;
        }

        if ($user['step'] === 'set_ad_photo') {
            if (isset($msg['photo'])) {
                $photo_id = end($msg['photo'])['file_id'];
                setSetting('ad_photo', $photo_id);
                setUserStep($from_id, 'admin_home');
                request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ تصویر بنر ذخیره شد.", 'reply_markup' => getAdminMenu()]);
            } else {
                request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ لطفاً فقط عکس بفرستید."]);
            }
            exit;
        }

        if ($text === '🛠 روشن کردن حالت تعمیرات') {
            setSetting('maintenance_mode', 'on');
            request('sendMessage', ['chat_id' => $chat_id, 'text' => "🔴 حالت تعمیرات فعال شد.", 'reply_markup' => getAdminMenu()]);
            exit;
        }

        if ($text === '🔴 خاموش کردن حالت تعمیرات') {
            setSetting('maintenance_mode', 'off');
            request('sendMessage', ['chat_id' => $chat_id, 'text' => "🟢 حالت تعمیرات خاموش شد.", 'reply_markup' => getAdminMenu()]);
            exit;
        }

        if ($text === '📨 ارسال پیام همگانی') {
            setUserStep($from_id, 'admin_broadcast');
            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "📨 پیام خود را جهت ارسال همگانی بفرستید:",
                'reply_markup' => json_encode(['keyboard' => [[['text' => '🔙 بازگشت به مدیریت']]], 'resize_keyboard' => true])
            ]);
            exit;
        }

        if ($user['step'] === 'admin_broadcast' && $text !== '🔙 بازگشت به مدیریت') {
            setUserStep($from_id, 'admin_home');
            request('sendMessage', ['chat_id' => $chat_id, 'text' => "⏳ در حال ارسال همگانی..."]);

            $users_res = $db->query("SELECT user_id FROM users");
            $success = 0; $fail = 0; $counter = 0;

            while ($row = $users_res->fetchArray(SQLITE3_ASSOC)) {
                $target_id = $row['user_id'];
                $send = request('copyMessage', [
                    'chat_id' => $target_id,
                    'from_chat_id' => $chat_id,
                    'message_id' => $message_id
                ]);

                if (isset($send['ok']) && $send['ok']) $success++;
                else $fail++;

                $counter++;
                usleep(35000);
                if ($counter % 200 === 0) sleep(1);
            }

            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "📊 ارسال همگانی به پایان رسید:\n\n🟢 موفق: {$success}\n🔴 ناموفق: {$fail}",
                'reply_markup' => getAdminMenu()
            ]);
            exit;
        }

        if ($text === '📩 ارسال پیام به کاربر') {
            setUserStep($from_id, 'admin_target_user');
            request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "📩 آیدی عددی کاربر را بفرستید:",
                'reply_markup' => json_encode(['keyboard' => [[['text' => '🔙 بازگشت به مدیریت']]], 'resize_keyboard' => true])
            ]);
            exit;
        }

        if ($user['step'] === 'admin_target_user' && $text !== '🔙 بازگشت به مدیریت') {
            if (is_numeric($text)) {
                setUserStep($from_id, 'admin_send_msg_to_user', $text);
                request('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "👤 مخاطب: <code>{$text}</code>\nپیام خود را بفرستید:",
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['keyboard' => [[['text' => '🔙 بازگشت به مدیریت']]], 'resize_keyboard' => true])
                ]);
            } else {
                request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط شناسه عددی بفرستید."]);
            }
            exit;
        }

        if ($user['step'] === 'admin_send_msg_to_user' && $text !== '🔙 بازگشت به مدیریت') {
            $target_uid = intval($user['temp_data']);
            request('sendMessage', ['chat_id' => $target_uid, 'text' => "📩 پیامی از طرف مدیریت:"]);
            $send_res = request('copyMessage', [
                'chat_id' => $target_uid,
                'from_chat_id' => $chat_id,
                'message_id' => $message_id
            ]);

            setUserStep($from_id, 'admin_home');
            if (isset($send_res['ok']) && $send_res['ok']) {
                request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پیام با موفقیت ارسال شد.", 'reply_markup' => getAdminMenu()]);
            } else {
                request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ ارسال پیام ناموفق بود.", 'reply_markup' => getAdminMenu()]);
            }
            exit;
        }
    }

    // TikTok URL Detection
    if (preg_match('/(https?:\/\/(?:www\.|vm\.|vt\.|v\.)?tiktok\.com\/[^\s]+)/i', $text, $matches)) {
        
        $join_data = checkUserJoined($from_id);
        if (!$join_data['joined']) {
            request('sendMessage', [
                'chat_id' => $chat_id,
                'reply_to_message_id' => $message_id,
                'text' => "⚠️ برای استفاده از ربات و دانلود محتوا، لطفاً در کانال‌(های) زیر عضو شده و سپس روی دکمه تایید عضویت کلیک کنید:",
                'reply_markup' => json_encode(['inline_keyboard' => $join_data['buttons']])
            ]);
            exit;
        }

        $tiktok_url = $matches[1];

        setReaction($chat_id, $message_id, '❤️‍🔥');
        sendChatAction($chat_id, 'upload_video');

        $media_data = getTikTokMedia($tiktok_url);

        if (!$media_data) {
            logError("Failed to extract media for URL: " . $tiktok_url);
            request('sendMessage', [
                'chat_id' => $chat_id,
                'reply_to_message_id' => $message_id,
                'text' => "❌ دریافت مدیا از این لینک ناموفق بود. ممکن است لینک منقضی شده باشد یا ویدیو در حالت خصوصی (Private) قرار داشته باشد."
            ]);
            exit;
        }

        $caption_orig = trim($media_data['caption'] ?? '');
        $safe_caption = htmlspecialchars($caption_orig, ENT_QUOTES, 'UTF-8');
        $author_name = htmlspecialchars($media_data['author'] ?? 'TikTok', ENT_QUOTES, 'UTF-8');
        $target_url = htmlspecialchars($media_data['orig_url'] ?? ($media_data['url'] ?? $tiktok_url), ENT_QUOTES, 'UTF-8');

        $final_caption = "";
        if (!empty($safe_caption)) {
            $final_caption .= $safe_caption . "\n\n";
        }
        $final_caption .= "🔗 <a href=\"{$target_url}\">{$author_name}</a>\n\n";
        $final_caption .= "🔍 <a href=\"https://t.me/" . BOT_USERNAME . "\">Download by @" . BOT_USERNAME . "</a>";

        $inline_keyboard = null;
        if (!empty($media_data['music'])) {
            $audio_cache_id = saveAudioUrlToCache($media_data['music']);
            $inline_keyboard = json_encode([
                'inline_keyboard' => [
                    [['text' => '🎧 Download Audio', 'callback_data' => 'aud_' . $audio_cache_id]]
                ]
            ]);
        }

        if (!file_exists(__DIR__ . '/temp')) {
            mkdir(__DIR__ . '/temp', 0777, true);
        }

        // Album (Multiple photos)
        if ($media_data['type'] === 'album') {
            sendChatAction($chat_id, 'upload_photo');
            $images = $media_data['images'];
            $chunks = array_chunk($images, 10);
            $album_sent = false;

            foreach ($chunks as $chunk) {
                $media_batch = [];
                $post_files = [];
                $downloaded_paths = [];

                foreach ($chunk as $idx => $img_url) {
                    $tmp_img = __DIR__ . '/temp/img_' . time() . '_' . $from_id . '_' . $idx . '.jpg';
                    if (downloadDirectToFile($img_url, $tmp_img)) {
                        $downloaded_paths[] = $tmp_img;
                        $attach_key = 'photo_' . $idx;
                        $item = ['type' => 'photo', 'media' => "attach://{$attach_key}"];
                        if ($idx === 0) {
                            $item['caption'] = $final_caption;
                            $item['parse_mode'] = 'HTML';
                        }
                        $media_batch[] = $item;
                        $post_files[$attach_key] = new CURLFile($tmp_img);
                    }
                }

                if (!empty($media_batch)) {
                    $post_data = array_merge([
                        'chat_id' => $chat_id,
                        'media' => json_encode($media_batch),
                        'reply_to_message_id' => $message_id
                    ], $post_files);

                    $res_group = request('sendMediaGroup', $post_data);
                    
                    if (isset($res_group['ok']) && $res_group['ok']) {
                        $album_sent = true;
                    } else {
                        logError("sendMediaGroup Fallback triggered. Reason: " . json_encode($res_group));
                        
                        foreach ($downloaded_paths as $k => $dp) {
                            $cap = ($k === 0) ? $final_caption : "";
                            $photo_res = request('sendPhoto', [
                                'chat_id' => $chat_id,
                                'photo' => new CURLFile($dp),
                                'caption' => $cap,
                                'parse_mode' => 'HTML',
                                'reply_to_message_id' => ($k === 0 ? $message_id : null)
                            ]);

                            if (!isset($photo_res['ok']) || !$photo_res['ok']) {
                                request('sendDocument', [
                                    'chat_id' => $chat_id,
                                    'document' => new CURLFile($dp),
                                    'caption' => $cap,
                                    'parse_mode' => 'HTML',
                                    'reply_to_message_id' => ($k === 0 ? $message_id : null)
                                ]);
                            }
                        }
                        $album_sent = true;
                    }
                }

                foreach ($downloaded_paths as $dp) {
                    @unlink($dp);
                }
            }

            if ($inline_keyboard) {
                request('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "👇 برای دریافت فایل صوتی از دکمه زیر استفاده کنید:",
                    'reply_markup' => $inline_keyboard
                ]);
            }

            if ($album_sent) {
                sendAdAfterSuccess($chat_id);
                incrementStats($from_id);
            }
            exit;
        }

        // Single Video
        if ($media_data['type'] === 'video') {
            sendChatAction($chat_id, 'upload_video');
            $tmp_vid = __DIR__ . '/temp/tt_' . time() . '_' . $from_id . '.mp4';
            $video_sent = false;

            if (downloadDirectToFile($media_data['url'], $tmp_vid)) {
                $sent = request('sendVideo', [
                    'chat_id' => $chat_id,
                    'video' => new CURLFile($tmp_vid),
                    'caption' => $final_caption,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $message_id,
                    'reply_markup' => $inline_keyboard
                ]);
                @unlink($tmp_vid);

                if (isset($sent['ok']) && $sent['ok']) {
                    $video_sent = true;
                }
            }

            if (!$video_sent) {
                $sent_direct = request('sendVideo', [
                    'chat_id' => $chat_id,
                    'video' => $media_data['url'],
                    'caption' => $final_caption,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $message_id,
                    'reply_markup' => $inline_keyboard
                ]);

                if (isset($sent_direct['ok']) && $sent_direct['ok']) {
                    $video_sent = true;
                }
            }

            if ($video_sent) {
                sendAdAfterSuccess($chat_id);
                incrementStats($from_id);
            } else {
                request('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "❌ در ارسال ویدیو خطایی رخ داد. لطفاً مجدداً امتحان کنید."
                ]);
            }
            exit;
        }
    }
}
?>