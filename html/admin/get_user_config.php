<?php
session_start();

function dieAjaxError($message) {
    header('HTTP/1.0 403 Forbidden');
    header('Content-Type: application/json');
    die(json_encode(['error' => $message]));
}

// Проверка AJAX-запроса
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    dieAjaxError('Direct access not allowed');
}

// Опционально: CSRF проверка
// if (empty($_GET['csrf']) || $_GET['csrf'] !== $_SESSION['csrf_token']) {
//     dieAjaxError('Invalid CSRF token');
// }

define("CONFIG", 1);

$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    dieAjaxError("Configuration file not found: $config_file");
}

$servers = require $config_file;

$server_name = $_GET['server'] ?? '';
$username = $_GET['username'] ?? '';

if (!isset($servers[$server_name]) || empty($username)) {
    http_response_code(400);
    die('Invalid parameters');
}

// CCD-файл
$ccd_file = $servers[$server_name]['ccd'] . "/" . $username;

// Путь к скрипту
$script_path = '/etc/openvpn/server/cmd/show_user_config.sh'; // GET_USER_CCD

$command = sprintf(
    'sudo %s %s 2>&1',
    escapeshellcmd($script_path),
    escapeshellarg($ccd_file)
);

exec($command, $output, $return_var);

if ($return_var !== 0) {
    http_response_code(500);
    echo json_encode(['error' => implode("\n", $output)]);
    exit;
}

// Вывод конфига
header('Content-Type: text/plain');
echo implode("\n", $output);
