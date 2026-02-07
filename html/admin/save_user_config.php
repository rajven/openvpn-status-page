<?php
session_start();

function dieAjaxError($message) {
    header('HTTP/1.0 403 Forbidden');
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'message' => $message]));
}

// Проверка AJAX-запроса
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    dieAjaxError('Direct access not allowed');
}

// Опционально: CSRF проверка
// if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf_token']) {
//     dieAjaxError('Invalid CSRF token');
// }

define("CONFIG", 1);

$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    dieAjaxError("Configuration file not found: $config_file");
}

$servers = require $config_file;

// Получаем данные POST в JSON
$input = json_decode(file_get_contents('php://input'), true);

$server_name = $input['server'] ?? '';
$username    = $input['username'] ?? '';
$config      = $input['config'] ?? '';

if (!isset($servers[$server_name]) || empty($username) || $config === null) {
    dieAjaxError('Invalid parameters');
}

// CCD-файл
$ccd_file = $servers[$server_name]['ccd'] . "/" . $username;

// Путь к скрипту
$script_path = PUT_USER_CCD;

// Команда для записи через stdin
$command = sprintf(
    'echo %s | sudo %s %s - 2>&1',
    escapeshellarg($config),
    escapeshellcmd($script_path),
    escapeshellarg($ccd_file)
);

exec($command, $output, $return_var);

if ($return_var !== 0) {
    dieAjaxError(implode("\n", $output));
}

// Успешно
header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Config saved successfully']);
