<?php
// Bot configuration - Get token from environment variable
define('BOT_TOKEN', $_ENV['BOT_TOKEN'] ?? 'Place_Your_Token_Here');
define('BOT_USERNAME', 'codezis_bot'); // Replace with your bot's username (without @)
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');

// Verification channels/groups
define('VERIFY_CHANNEL', '@codezcool');
define('VERIFY_GROUP', '@codexfusion');

// Error logging function
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
}

// Telegram API caller
function callTelegramAPI($method, $params) {
    $url = API_URL . $method . '?' . http_build_query($params);
    $response = @file_get_contents($url);
    if ($response === false) {
        logError("API call failed for method $method");
        return null;
    }
    return json_decode($response, true);
}

// Check user membership in both channel and group
function checkUserSubscription($user_id) {
    $chats = [VERIFY_CHANNEL, VERIFY_GROUP];
    foreach ($chats as $chat) {
        $result = callTelegramAPI('getChatMember', [
            'chat_id' => $chat,
            'user_id' => $user_id
        ]);
        if (!$result || !isset($result['result']['status'])) {
            return false;
        }
        $status = $result['result']['status'];
        if (!in_array($status, ['creator', 'administrator', 'member'])) {
            return false;
        }
    }
    return true;
}

// Load and save user data
function loadUsers() {
    try {
        if (!file_exists(USERS_FILE)) {
            file_put_contents(USERS_FILE, json_encode([]));
        }
        return json_decode(file_get_contents(USERS_FILE), true) ?: [];
    } catch (Exception $e) {
        logError("Load users failed: " . $e->getMessage());
        return [];
    }
}

function saveUsers($users) {
    try {
        file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
        return true;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage());
        return false;
    }
}

// Send message
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }
        $url = API_URL . 'sendMessage?' . http_build_query($params);
        file_get_contents($url);
        return true;
    } catch (Exception $e) {
        logError("Send message failed: " . $e->getMessage());
        return false;
    }
}

// Main keyboard
function getMainKeyboard() {
    return [
        [
            ['text' => '💰 Earn', 'callback_data' => 'earn'],
            ['text' => '💳 Balance', 'callback_data' => 'balance']
        ],
        [
            ['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'],
            ['text' => '👥 Referrals', 'callback_data' => 'referrals']
        ],
        [
            ['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'],
            ['text' => '❓ Help', 'callback_data' => 'help']
        ]
    ];
}

// Process user update
function processUpdate($update) {
    $users = loadUsers();

    // Get chat_id
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
    } elseif (isset($update['callback_query'])) {
        $chat_id = $update['callback_query']['message']['chat']['id'];
    } else {
        return;
    }

    // If not subscribed, ask to join
    if (!checkUserSubscription($chat_id)) {
        $verificationMsg = "🚫 To use this bot, you must join our channel and group first:";
        $keyboard = [
            [
                ['text' => 'Join Channel', 'url' => 'https://t.me/' . ltrim(VERIFY_CHANNEL, '@')],
                ['text' => 'Join Group', 'url' => 'https://t.me/' . ltrim(VERIFY_GROUP, '@')]
            ],
            [
                ['text' => '✅ I Joined', 'callback_data' => 'check_subscription']
            ]
        ];
        sendMessage($chat_id, $verificationMsg, $keyboard);
        return;
    }

    // Create new user if needed
    if (!isset($users[$chat_id])) {
        $users[$chat_id] = [
            'balance' => 0,
            'last_earn' => 0,
            'referrals' => 0,
            'ref_code' => substr(md5($chat_id . time()), 0, 8),
            'referred_by' => null
        ];
    }

    if (isset($update['message'])) {
        $text = trim($update['message']['text'] ?? '');
        if (strpos($text, '/start') === 0) {
            $parts = explode(' ', $text);
            $ref = $parts[1] ?? null;
            if ($ref && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50;
                        sendMessage($id, "🎉 New referral! +50 points bonus!");
                        break;
                    }
                }
            }

            $msg = "Welcome to Earning Bot!\nEarn points, invite friends, and withdraw your earnings!\nYour referral code: <b>{$users[$chat_id]['ref_code']}</b>";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }

    } elseif (isset($update['callback_query'])) {
        $data = $update['callback_query']['data'];
        switch ($data) {
            case 'check_subscription':
                if (!checkUserSubscription($chat_id)) {
                    $msg = "❌ You haven't joined both channel and group yet. Please join and try again.";
                    $keyboard = [
                        [
                            ['text' => 'Join Channel', 'url' => 'https://t.me/' . ltrim(VERIFY_CHANNEL, '@')],
                            ['text' => 'Join Group', 'url' => 'https://t.me/' . ltrim(VERIFY_GROUP, '@')]
                        ],
                        [
                            ['text' => '✅ I Joined', 'callback_data' => 'check_subscription']
                        ]
                    ];
                    sendMessage($chat_id, $msg, $keyboard);
                    return;
                } else {
                    $msg = "✅ Thank you for joining! Now you can use the bot.";
                    sendMessage($chat_id, $msg, getMainKeyboard());
                }
                break;

            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Please wait $remaining seconds before earning again!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ You earned $earn points!\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;

            case 'balance':
                $msg = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;

            case 'leaderboard':
                $sorted = array_column($users, 'balance');
                arsort($sorted);
                $top = array_slice($sorted, 0, 5, true);
                $msg = "🏆 Top Earners\n";
                $i = 1;
                foreach ($top as $id => $bal) {
                    $msg .= "$i. User $id: $bal points\n";
                    $i++;
                }
                break;

            case 'referrals':
                $msg = "👥 Referral System\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\nReferrals: {$users[$chat_id]['referrals']}\nInvite link: t.me/" . BOT_USERNAME . "?start={$users[$chat_id]['ref_code']}\n50 points per referral!";
                break;

            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $msg = "🏧 Withdrawal\nMinimum: $min points\nYour balance: {$users[$chat_id]['balance']}\nNeed " . ($min - $users[$chat_id]['balance']) . " more points!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
                }
                break;

            case 'help':
                $msg = "❓ Help\n💰 Earn: Get 10 points/min\n👥 Refer: 50 points/ref\n🏧 Withdraw: Min 100 points\nUse buttons below to navigate!";
                break;
        }

        sendMessage($chat_id, $msg, getMainKeyboard());
    }

    saveUsers($users);
}

// Webhook handler
try {
    $content = file_get_contents("php://input");
    $update = json_decode($content, true);

    if ($update) {
        processUpdate($update);
        http_response_code(200);
        echo "OK";
    } else {
        http_response_code(200);
        echo "Telegram Bot is running";
    }
} catch (Exception $e) {
    logError("Fatal error: " . $e->getMessage());
    http_response_code(500);
    echo "ERROR";
}
?>
