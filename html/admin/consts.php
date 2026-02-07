<?php

defined('CONFIG') or die('Direct access not allowed');

function get_user_ip() {
    $portShareDir = '/var/spool/openvpn';
    // Получаем IP и порт клиента, который подключился к Apache
    $clientAddr = "127.0.0.1";
    $clientPort = $_SERVER['REMOTE_PORT'];  // Порт клиента
    $fileName = '[AF_INET]' . $clientAddr . ':' . $clientPort;
    $filePath = $portShareDir . '/' . $fileName;
    // Проверяем существование файла
    if (file_exists($filePath)) {
        // Читаем содержимое файла
        $content = file_get_contents($filePath);
        if (preg_match('/\[AF_INET\]([\d\.]+):(\d+)/', $content, $matches)) {
            $realIP = $matches[1];
            return $realIP;
        }
    }
    if (!empty(getenv("HTTP_CLIENT_IP"))) { return getenv("HTTP_CLIENT_IP"); }
    if (!empty(getenv("HTTP_X_FORWARDED_FOR"))) { return getenv("HTTP_X_FORWARDED_FOR"); }
    if (!empty(getenv("REMOTE_ADDR"))) { return getenv("REMOTE_ADDR"); }
    if (!empty($_SERVER['REMOTE_ADDR'])) { return $_SERVER['REMOTE_ADDR']; }
    return 'Не удалось определить';
}

$ip = get_user_ip();
//if (!preg_match('/^127\.0\.0\./',$ip)) { die('Access forbidden!'); }

define('REQUEST_INTERVAL', 30);

define('SHOW_CERT_SCRIPT','/etc/openvpn/server/cmd/show_client_crt.sh');
define('SHOW_PKI_INDEX','/etc/openvpn/server/cmd/show_index.sh');
define('CREATE_CRT','/etc/openvpn/server/cmd/create_client.sh');
define('REVOKE_CRT','/etc/openvpn/server/cmd/revoke_client.sh');
define('SHOW_SERVERS_CRT','/etc/openvpn/server/cmd/show_servers_crt.sh');
define('BAN_CLIENT','/etc/openvpn/server/cmd/ban_client.sh');
define('SHOW_BANNED','/etc/openvpn/server/cmd/show_banned.sh');
define('GET_IPS_FROM_CCD','/etc/openvpn/server/cmd/show_client_ccd.sh');
define('GET_IPS_FROM_IPP','/etc/openvpn/server/cmd/show_client_ipp.sh');
define('REMOVE_CCD','/etc/openvpn/server/cmd/remove_ccd.sh');
define('GET_USER_CCD','/etc/openvpn/server/cmd/show_user_config.sh');
define('PUT_USER_CCD','/etc/openvpn/server/cmd/write_user_config.sh');
