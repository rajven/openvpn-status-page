<?php

define("CONFIG", 1);

require_once 'functions.php';

// Подключаем конфигурационный файл
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    die("Configuration file not found: $config_file");
}

$servers = require $config_file;

// Проверяем AJAX-запрос
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Direct access not allowed']));
}

// Обработка POST-данных
$server_name = $_POST['server'] ?? null;
$action = $_POST['action'] ?? null;
$client_name = $_POST['client'] ?? null;

if (!isset($servers[$server_name])) {
    die(json_encode(['success' => false, 'message' => 'Invalid server']));
}

$server = $servers[$server_name];
$result = false;

try {
    switch ($action) {
        case 'ban':
            $result = banClient($server, $client_name);
            break;
        case 'unban':
            $result = unbanClient($server, $client_name);
            break;
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode(['success' => $result]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
