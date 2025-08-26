<?php
//error_reporting(E_ALL);
//ini_set('display_errors', 1);

define("CONFIG", 1);

// Подключаем конфигурационный файл
$config_file = __DIR__ . '/../admin/config.php';
if (!file_exists($config_file)) {
    die("Configuration file not found: $config_file");
}

$servers = require $config_file;

// Проверяем авторизацию Apache
$is_authenticated = false;
$username = '';

// Получаем username из Apache auth или из GET/POST
if (isset($_SERVER['PHP_AUTH_USER'])) {
    // Авторизация через Apache Basic Auth
    $is_authenticated = true;
    $username = $_SERVER['PHP_AUTH_USER'];
} elseif (isset($_SERVER['REMOTE_USER'])) {
    // Альтернативный способ получения username
    $is_authenticated = true;
    $username = $_SERVER['REMOTE_USER'];
} else {
        showApacheAuthRequired();
}

$server_name = $_GET['server'] ?? $_POST['server'] ?? 'server1';

// Если авторизованы через Apache - генерируем конфиг
if ($is_authenticated && !empty($username)) {
    $server_name = $_GET['server'] ?? $_POST['server'] ?? 'server1';
    generateConfig($username, $server_name, $servers);
    exit;
}

// Если не авторизованы
showApacheAuthRequired();

function generateConfig($username, $server_name, $servers) {
    if (empty($username) || !isset($servers[$server_name])) {
        die('Invalid parameters');
    }

    $server = $servers[$server_name];
    $script_path = SHOW_CERT_SCRIPT;
    $pki_dir = dirname($server['cert_index']);
    $template_path = '../admin/'.$server['cfg_template'] ?? '';

    $output = [];
    if (!empty($pki_dir)) {
        // Безопасное выполнение скрипта
        $command = sprintf(
            'sudo %s %s %s 2>&1',
            escapeshellcmd($script_path),
            escapeshellarg($username),
            escapeshellarg($pki_dir)
        );
        exec($command, $output, $return_var);
        if ($return_var !== 0) {
            die('Failed to generate config: ' . implode("\n", $output));
        }
    }

    // Формируем контент
    $template_content = file_exists($template_path) && is_readable($template_path)
        ? file_get_contents($template_path)
        : die ('Error: Neither template: '.$template_path);

    // Получаем вывод скрипта
    $script_output = !empty($output) ? implode("\n", $output) : null;

    // Формируем итоговый контент по приоритетам
    if ($template_content !== null && $script_output !== null) {
        // Оба источника доступны - объединяем
        $config_content = $template_content . "\n" . $script_output;
    } elseif ($template_content !== null) {
        // Только шаблон доступен
        $config_content = $template_content;
    } elseif ($script_output !== null) {
        // Только вывод скрипта доступен
        $config_content = $script_output;
    } else {
        // Ничего не доступно - ошибка
        die('Error: Neither template nor script output available');
    }

    // Прямая отдача контента
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $server['name'].'-'.$username . '.ovpn"');
    header('Content-Length: ' . strlen($config_content));
    echo $config_content;
    exit;
}

function showApacheAuthRequired() {
    header('WWW-Authenticate: Basic realm="OpenVPN Config Download"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Authentication Required</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
            .info { background: #f8f9fa; border-left: 4px solid #007bff; padding: 15px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <h2>Authentication Required</h2>
        <div class="info">
            <p>This site uses Apache Basic Authentication. Please enter your credentials in the browser authentication dialog.</p>
            <p>If you don\'t see the login prompt, try refreshing the page or check your browser settings.</p>
        </div>
    </body>
    </html>';
    exit;
}
