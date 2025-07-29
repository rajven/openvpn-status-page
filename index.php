<?php

define("CONFIG", 1);

// Настройки
$page_title = 'OpenVPN Status';

// Подключаем конфигурационный файл
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    die("Configuration file not found: $config_file");
}
$servers = require_once $config_file;

session_start();

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Проверяем и инициализируем массив, если его нет
if (!isset($_SESSION['last_request_time']) || !is_array($_SESSION['last_request_time'])) {
    $_SESSION['last_request_time'] = []; // Создаем пустой массив
}

?>

<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($page_title) ?></title>
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
    </script>

</body>
</html>
