<?php

define("CONFIG", 1);

// Настройки
$page_title = 'OpenVPN Status';

// Подключаем конфигурационный файл
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    die("Configuration file not found: $config_file");
}

$servers = require $config_file;

session_start();

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Проверяем и инициализируем массив, если его нет
if (!isset($_SESSION['last_request_time']) || !is_array($_SESSION['last_request_time'])) {
    $_SESSION['last_request_time'] = []; // Создаем пустой массив
}

if (isset($_GET['action']) && $_GET['action'] === 'generate_config') {
    session_start();
    
    // Проверка CSRF
//    if (empty($_GET['csrf']) || $_GET['csrf'] !== $_SESSION['csrf_token']) {
//        header('HTTP/1.0 403 Forbidden');
//        die('Invalid CSRF token');
//    }

    $server_name = $_GET['server'] ?? '';
    $username = $_GET['username'] ?? '';
    
    if (empty($username) || !isset($servers[$server_name])) {
        die('Invalid parameters');
    }
    
    $server = $servers[$server_name];
    $script_path = SHOW_CERT_SCRIPT;
    $pki_dir = dirname($server['cert_index']);
    $template_path = $server['cfg_template'] ?? '';
    
    $output =[];
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
        : null;
    
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
    $clean_url = strtok($_SERVER['REQUEST_URI'], '?');
    header("Refresh:0; url=" . $clean_url);
    exit;
    }

?>

<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="csrf_token" content="<?= $_SESSION['csrf_token'] ?>">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .banned { background-color: #ffeeee; }
        .actions { white-space: nowrap; }
        .btn { padding: 3px 8px; margin: 2px; cursor: pointer; border: 1px solid #ccc; border-radius: 3px; }
        .kick-btn { background-color: #ffcccc; }
        .ban-btn { background-color: #ff9999; }
        .unban-btn { background-color: #ccffcc; }
        .section { margin-bottom: 30px; }
        .status-badge { padding: 2px 5px; border-radius: 3px; font-size: 0.8em; }
        .status-active { background-color: #ccffcc; }
        .status-banned { background-color: #ff9999; }
        .server-section { border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .spoiler { margin-top: 10px; }
        .spoiler-title { 
            cursor: pointer; 
            color: #0066cc; 
            padding: 5px;
            background-color: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 3px;
            display: inline-block;
            margin-bottom: 5px;
        }
        .spoiler-title:after { content: " ▼"; }
        .spoiler-title.collapsed:after { content: " ►"; }
        .spoiler-content { 
            display: none; 
            padding: 10px; 
            border: 1px solid #ddd; 
            margin-top: 5px; 
            background-color: #f9f9f9; 
            border-radius: 3px;
        }
        .loading { color: #666; font-style: italic; }
        .last-update { font-size: 0.8em; color: #666; margin-top: 5px; }
        .spinner {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 40px;
            height: 40px;
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-radius: 50%;
            border-left-color: #09f;
            animation: spin 1s linear infinite;
            z-index: 1000;
        }
        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($page_title) ?></h1>
    
    <div id="server-container">
        <?php foreach ($servers as $server_name => $server): ?>
        <div class="server-section" id="server-<?= htmlspecialchars($server_name) ?>">
            <h2><?= htmlspecialchars($server['title']) ?></h2>
            <div class="loading">Loading data...</div>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
        // Функция для загрузки данных сервера
        function loadServerData(serverName) {
            const serverElement = document.getElementById(`server-${serverName}`);
            
            fetch(`get_server_data.php?server=${serverName}&csrf=<?= $_SESSION['csrf_token'] ?>`,{
		    headers: {
			'X-Requested-With': 'XMLHttpRequest'
		    }
		})
                .then(response => response.text())
                .then(html => {
                    serverElement.innerHTML = html;
                    // Обновляем данные каждые 60 секунд
                    setTimeout(() => loadServerData(serverName), 60000);
                })
                .catch(error => {
                    serverElement.querySelector('.loading').textContent = 'Error loading data';
                    console.error('Error:', error);
                    // Повторяем попытку через 10 секунд при ошибке
                    setTimeout(() => loadServerData(serverName), 10000);
                });
        }

        // Загружаем данные для всех серверов
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($servers as $server_name => $server): ?>
                loadServerData('<?= $server_name ?>');
            <?php endforeach; ?>
        });

        // Функция для обработки действий (ban/unban)
        function handleAction(serverName, action, clientName) {

            const params = new URLSearchParams();
            params.append('server', serverName);
            params.append('action', action);
            params.append('client', clientName);

            fetch('handle_action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: params
            })
            .then(response => {
                // 2. Проверяем статус ответа
                if (!response.ok) {
                    throw new Error(`Server returned ${response.status} status`);
                }
                return response.json();
            })
            .then(data => {
                // 3. Проверяем структуру ответа
                if (!data || typeof data.success === 'undefined') {
                    throw new Error('Invalid server response');
                }
                if (data.success) {
                    loadServerData(serverName);
                } else {
                    console.error('Server error:', data.message);
                    alert(`Error: ${data.message || 'Operation failed'}`);
                }
            })
            .catch(error => {
                // 4. Правильное отображение ошибки
                console.error('Request failed:', error);
                alert(`Request failed: ${error.message}`);
            });
        }

        // Функция для переключения спойлера
        function toggleSpoiler(button) {
            const content = button.nextElementSibling;
            if (content.style.display === "block") {
                content.style.display = "none";
                button.classList.add('collapsed');
            } else {
                content.style.display = "block";
                button.classList.remove('collapsed');
            }
        }

        function generateConfig(server, username, event) {
            event.preventDefault();
            
            if (!confirm('Сгенерировать конфигурацию для ' + username + '?')) {
                return false;
            }
            
            // Индикатор загрузки
            const spinner = document.createElement('div');
            spinner.className = 'spinner';
            document.body.appendChild(spinner);
            
            const csrf = document.querySelector('meta[name="csrf_token"]').content;
            const params = new URLSearchParams({
                server: server,
                action: 'generate_config',
                username: username,
                csrf: csrf
            });
            
            // Вариант 1: Простое открытие (рекомендуется)
            window.open(`?${params.toString()}`, '_blank');
            document.body.removeChild(spinner);
            
            /* 
            // Вариант 2: Через fetch (если нужно строго AJAX)
            fetch(`?${params.toString()}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(response => response.blob())
            .then(blob => {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `${username}.ovpn`;
                a.click();
                URL.revokeObjectURL(url);
            })
            .catch(console.error)
            .finally(() => document.body.removeChild(spinner));
            */
            
            return false;
        }

    </script>

</body>
</html>
