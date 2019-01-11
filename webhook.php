<?php

$webhook = file_get_contents('php://input');
$update = json_decode($webhook);

if ($update->message->chat->type != ('group' || 'supergroup')) exit;

$app = new TranslateBot($update->message->chat->id);

if (!isset($update->message) || !isset($update->message->text)) exit;
$message = $update->message;

if (!isset($message->reply_to_message->text)) exit;

$text = $message->text;

$explode = explode(' ',$text);
if ($explode[0] != '/translate' && $explode[0] != '/translate@WooMaiTranslateBot' && $explode[0] != '/t' && $explode[0] != '/t@WooMaiTranslateBot') {
    exit;
} else {
    if (!isset($explode[1])) {
        $to = $app->config['default_language'];
    } else {
        $to = $explode[1];
    }
    $result = $app->translate($message->reply_to_message->text,$to);
    $app->reply($message->chat->id,$result,$message->message_id);
    exit;
}


class TranslateBot {
    public $config;
    protected $api_token;
    protected $bot_token;

    function __construct($chat_id) {
        require './config.php';
        $this->config = $Config;
        if (!isset($_GET['key']) || $_GET['key'] != $Config['webhook_key']) exit;
        if (!$this->checkChat($chat_id)) {
            $this->send($chat_id,'本群不在白名单中。');
            $this->leave($chat_id);
            exit;
        }
        $this->api_token = $Config['api_token'];
        $this->bot_token = $Config['bot_token'];
    }

    function checkChat($chat_id) {
        $wl = explode(',',$this->config['whitelist']);
        foreach ($wl as $cid) {
            if ($cid == $chat_id) return true;
        }
        return false;
    }

    /**
     * 使用翻译API进行翻译操作
     * @param string $text 需要被翻译的字符串
     * @param string $to 目标语言 https://cloud.google.com/translate/docs/languages?hl=zh-cn
     * @param string $from (可选)指定被翻译的语言
     * @return string 可以直接返回给用户的信息
     */
    function translate($text,$to,$from = '') {
        $data = array(
            'q' => $text,
            'target' => $to,
            'source' => $from,
            'key' => $this->api_token
        );

        $ch = curl_init('https://translation.googleapis.com/language/translate/v2');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data)
        ));
        $response = curl_exec($ch);
        if (!$response) return 'fail';
        curl_close($ch);

        // 处理API返回值
        $rsp = json_decode($response);
        if (isset($rsp->error)) {
            return $rsp->error->message;
        } else if (isset($rsp->data->translations)) {
            $dt = $rsp->data->translations[0];
            $result = "<code>$dt->translatedText</code>";
            if (isset($dt->detectedSourceLanguage)) {
                $result .= "\r\n源语言: $dt->detectedSourceLanguage";
            }
            return $result;
        } else {
            return 'fail';
        }
    }

    /**
     * 向指定会话发送一条消息
     * @param int $chat_id 会话ID
     * @param int $text 消息内容
     * @return null 没有返回值
     */
    function send($chat_id,$text) {
        $this->TelegramAPI('sendMessage',array(
            'chat_id' => $chat_id,
            'text' => $text,
            'disable_web_page_preview' => true
        ));
    }

    /** 
     * 回复一条消息
     * @param int $chat_id Telegram 会话ID
     * @param string $text 发送的文本
     * @param int $reply_to_message_id 回复的消息id，默认不回复
     * @return null 没有返回值
     */
    function reply($chat_id,$text,$reply_to_message_id) {
        $this->TelegramAPI('sendMessage',array(
            'chat_id'                  => $chat_id,
            'text'                     => $text,
            'reply_to_message_id'      => $reply_to_message_id,
            'parse_mode'               => 'html',
            'disable_web_page_preview' => true,
        ));
    }

    /**
     * 机器人主动退出一个群组
     * @param int $chat_id 群组ID
     * @return null 没有返回值
     */
    function leave($chat_id) {
        $this->TelegramAPI('leaveChat',array(
            'chat_id' => $chat_id
        ));
    }

    /**
     * 调用 Telegram Bot API
     * @param string $method 使用的method
     * @param array $data POST的数组，将以json格式发送
     * @return string API返回的内容
     */
    protected function TelegramAPI($method,array $data) {

        $token = $this->config['bot_token'];

        $data = json_encode($data);
        $url = "https://api.telegram.org/bot$token/$method";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: '.strlen($data)
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        $response = curl_exec($ch);

        curl_close($ch);
        return $response;
    }
}