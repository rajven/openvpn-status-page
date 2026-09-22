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
require_once 'functions.php';

session_start();

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Проверяем и инициализируем массив, если его нет
if (!isset($_SESSION['last_request_time']) || !is_array($_SESSION['last_request_time'])) {
    $_SESSION['last_request_time'] = []; // Создаем пустой массив
}

if (isset($_GET['action']) && $_GET['action'] === 'generate_config') {
    
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

// Обработка создания пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    process_create_user($servers);
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
        .expired { background-color: #ccffcc; }
        .actions { white-space: nowrap; }
        .btn { padding: 3px 8px; margin: 2px; cursor: pointer; border: 1px solid #ccc; border-radius: 3px; }
        .kick-btn { background-color: #ffcccc; }
        .ban-btn { background-color: #ff9999; }
        .unban-btn { background-color: #ccffcc; }
        .section { margin-bottom: 30px; }
        .status-badge { padding: 2px 5px; border-radius: 3px; font-size: 0.8em; }
        .status-active { background-color: #ccffcc; }
        .status-banned { background-color: #ff9999; }
        .status-expired { background-color: #ffb02e; }
        .cert-date {
            font-size: 0.85em;
            font-family: monospace;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .cert-date.valid {
            color: #28a745;
            background-color: #e8f5e9;
        }
        .cert-date.expiring-soon {
            color: #ff9800;
            background-color: #fff3e0;
        }
        .cert-date.expires-today {
            color: #ff5722;
            background-color: #fbe9e7;
            font-weight: bold;
        }
        .cert-date.expired {
            color: #f44336;
            background-color: #ffebee;
            text-decoration: line-through;
        }
        .cert-date.error {
            color: #9e9e9e;
            background-color: #f5f5f5;
        }
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
        .create-user-form {
            margin: 15px 0;
            padding: 10px;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .create-user-form input[type="text"],
        .create-user-form select {
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 3px;
            margin-right: 5px;
        }
        
        .create-user-form button {
            padding: 5px 10px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .create-user-form button:hover {
            background-color: #45a049;
        }
        
        .create-user-form .error {
            color: #d9534f;
            margin-top: 5px;
        }
        
        .create-user-form .success {
            color: #5cb85c;
            margin-top: 5px;
        }
        
        .user-creation-section {
            margin-bottom: 30px;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
        }

        /* Стили для временных сообщений */
        .temp-message {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            border-radius: 5px;
            color: white;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .temp-message.success {
            background: green;
        }
        
        .temp-message.error {
            background: red;
        }
        
        /* Стили для disabled кнопок */
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .revoked-text {
            color: #999;
            font-style: italic;
        }
        .revoke-btn {
            background-color: #ff9999;
        }
        .revoke-btn:hover {
            background-color: #e65c00;
        }

        .remove-text {
            color: #999;
            font-style: italic;
        }
        .remove-btn {
            background-color: #ff9999;
        }
        .remove-btn:hover {
            background-color: #e65c00;
        }

        .ban-btn:hover {
            background-color: #e65c00;
        }

        .unban-btn:hover {
            background-color: #499E24;
        }

        /* Скрытие revoked-строк по умолчанию */
        .revoked-row {
            display: none;
        }
        /* Показ при снятом чекбоксе */
        .show-revoked .revoked-row {
            display: table-row;
        }
        /* Оформление чекбокса */
        .hide-revoked-label {
            display: inline-block;
            margin-bottom: 10px;
            padding: 4px 10px;
            background-color: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.9em;
            user-select: none;
        }
        .hide-revoked-label:hover {
            background-color: #e8e8e8;
        }

    </style>

</head>
<body>
    <h1><?= htmlspecialchars($page_title) ?></h1>

    <!-- Секция создания пользователей -->
    <div class="user-creation-section">
        <h2>Create User</h2>
        <div class="create-user-form">
            <form onsubmit="return createUser(event)">
                <select name="server" required>
                    <option value="">Select Server</option>
                    <?php foreach ($servers as $server_name => $server): ?>
                        <?php if (!empty($server['cert_index'])): ?>
                            <option value="<?= htmlspecialchars($server_name) ?>">
                                <?= htmlspecialchars($server['title']) ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                
                <input type="text" name="username" placeholder="Username" required 
                       pattern="[^\s]+" title="Username cannot contain spaces">
                
                <button type="submit">Create User</button>
                <div class="message"></div>
            </form>
        </div>
    </div>

    <div id="server-container">
        <?php foreach ($servers as $server_name => $server): ?>
        <div class="server-section" id="server-<?= htmlspecialchars($server_name) ?>">
            <h2><?= htmlspecialchars($server['title']) ?></h2>
            <div class="loading">Loading data...</div>
        </div>
        <?php endforeach; ?>
    </div>

<script>

// ============================================
// Динамическая загрузка SweetAlert2 с fallback
// ============================================
const SweetAlertLoader = {
    loaded: false,
    loading: null,
    
    load() {
        if (this.loaded && window.Swal) {
            return Promise.resolve(true);
        }
        
        if (this.loading) {
            return this.loading;
        }
        
        this.loading = new Promise((resolve) => {
            // Пробуем CDN
            this.loadScript('https://cdn.jsdelivr.net/npm/sweetalert2@11', 5000)
                .then(() => {
                    console.log('✓ SweetAlert2 loaded from CDN');
                    this.loaded = true;
                    resolve(true);
                })
                .catch(() => {
                    console.warn('CDN failed, trying local file...');
                    // Fallback на локальный файл
                    this.loadScript('js/sweetalert2.min.js', 3000)
                        .then(() => {
                            console.log('✓ SweetAlert2 loaded from local cache');
                            this.loaded = true;
                            resolve(true);
                        })
                        .catch(() => {
                            console.error('✗ SweetAlert2 failed to load completely');
                            resolve(false);
                        });
                });
        });
        
        return this.loading;
    },
    
    loadScript(src, timeout) {
        return new Promise((resolve, reject) => {
            if (window.Swal) {
                resolve();
                return;
            }
            
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            
            const timer = setTimeout(() => {
                script.remove();
                reject(new Error(`Timeout loading ${src}`));
            }, timeout);
            
            script.onload = () => {
                clearTimeout(timer);
                if (window.Swal) {
                    resolve();
                } else {
                    script.remove();
                    reject(new Error('Script loaded but Swal not found'));
                }
            };
            
            script.onerror = () => {
                clearTimeout(timer);
                script.remove();
                reject(new Error(`Failed to load ${src}`));
            };
            
            document.head.appendChild(script);
        });
    }
};

// Переключение видимости revoked-строк
function toggleRevokedRows(checkbox) {
    const serverName = checkbox.dataset.server;
    const section = document.getElementById('server-' + serverName);
    if (!section) return;

    if (checkbox.checked) {
        section.classList.remove('show-revoked');   // скрыть
    } else {
        section.classList.add('show-revoked');       // показать
    }
}

function editCCD(server, username) {
    const width = 800;
    const height = 600;
    const left = (screen.width/2) - (width/2);
    const top  = (screen.height/2) - (height/2);

    const win = window.open('', 'editCCD', `width=${width},height=${height},top=${top},left=${left},resizable=yes,scrollbars=yes`);

    win.document.write('<h3>Edit CCD for ' + username + ' on ' + server + '</h3>');
    win.document.write('<textarea id="ccd-textarea" style="width:100%; height:80%; font-family: monospace;">Loading...</textarea><br>');
    win.document.write('<button onclick="saveCCD()">Save</button> <span id="save-status"></span>');

    // Функция для сохранения
    win.saveCCD = function() {
        const textarea = win.document.getElementById('ccd-textarea');
        const status = win.document.getElementById('save-status');
        status.textContent = 'Saving...';

        fetch('save_user_config.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                server: server,
                username: username,
                config: textarea.value
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                status.textContent = 'Saved ✅';
                setTimeout(() => win.close(), 1000); // закрываем окно через секунду
            } else {
                status.textContent = 'Error: ' + (data.message || 'Unknown');
            }
        })
        .catch(err => {
            status.textContent = 'Request failed: ' + err.message;
        });
    };

    // Загружаем текущее содержимое CCD
    fetch(`get_user_config.php?server=${encodeURIComponent(server)}&username=${encodeURIComponent(username)}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.text())
    .then(data => { win.document.getElementById('ccd-textarea').value = data; })
    .catch(err => { win.document.getElementById('ccd-textarea').value = 'Error loading: ' + err.message; });
}

// Функция для загрузки данных сервера
function loadServerData(serverName,force = false) {
    const serverElement = document.getElementById(`server-${serverName}`);

    if (!serverElement) {
        console.error('serverElement not found for:', serverName);
        return;
    }

    let url = `get_server_data.php?server=${encodeURIComponent(serverName)}&csrf=<?= $_SESSION['csrf_token'] ?>`;
    if (force) {
        url += '&force=1';
    }

    fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            serverElement.innerHTML = html;
            const cb = serverElement.querySelector('.hide-revoked-checkbox');
            if (cb) {
                cb.checked = !serverElement.classList.contains('show-revoked');
            }
            setTimeout(() => loadServerData(serverName, force), 60000);
            })
        .catch(error => {
            serverElement.querySelector('.loading').textContent = 'Error loading data';
            setTimeout(() => loadServerData(serverName,force), 10000);
        });
}

// Загружаем данные для всех серверов
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach ($servers as $server_name => $server): ?>
        loadServerData('<?= $server_name ?>',false);
    <?php endforeach; ?>
});

// Функция для обработки действий (ban/unban)
function handleAction(serverName, action, clientName) {
    console.log('=== handleAction START ===');
    console.log('Server:', serverName, 'Action:', action, 'Client:', clientName);
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
                if (!response.ok) {
                    throw new Error(`Server returned ${response.status} status`);
                }
                return response.text();
            })
            .then(text => {
                try {
                    const data = JSON.parse(text);
                    return data;
                } catch (e) {
                    console.error('JSON parse error:', e.message);
                    console.error('Raw text that failed to parse:', text);
                    throw new Error('Invalid JSON response');
                }
            })
            .then(data => {
                if (data.success) {
                    loadServerData(serverName, true);
                } else {
                    console.error('Server error:', data.message);
                    alert(`Error: ${data.message || 'Operation failed'}`);
                }
            })
            .catch(error => {
                console.error('Request failed:', error);
                alert(`Request failed: ${error.message}`);
            });
        
            console.log('=== handleAction END ===');
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

            window.open(`?${params.toString()}`, '_blank');
            document.body.removeChild(spinner);
            
            return false;
        }

        // Функция для создания пользователя
        function createUser(event) {
            event.preventDefault();
            
            const form = event.target;
            const serverSelect = form.querySelector('select[name="server"]');
            const usernameInput = form.querySelector('input[name="username"]');
            const messageDiv = form.querySelector('.message');
            const button = form.querySelector('button');
            
            const serverName = serverSelect.value;
            const username = usernameInput.value.trim();
            
            // Валидация
            if (!serverName) {
                messageDiv.textContent = 'Please select a server';
                messageDiv.className = 'message error';
                return false;
            }
            
            if (!username) {
                messageDiv.textContent = 'Please enter username';
                messageDiv.className = 'message error';
                return false;
            }
            
            if (/\s/.test(username)) {
                messageDiv.textContent = 'Username cannot contain spaces';
                messageDiv.className = 'message error';
                return false;
            }
            
            // Блокируем кнопку на время выполнения
            button.disabled = true;
            messageDiv.textContent = 'Creating user...';
            messageDiv.className = 'message';
            
            const csrf = document.querySelector('meta[name="csrf_token"]').content;
            
            const formData = new FormData();
            formData.append('server', serverName);
            formData.append('action', 'create_user');
            formData.append('username', username);
            formData.append('csrf', csrf);
            
            fetch('', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageDiv.textContent = data.message || 'User created successfully';
                    messageDiv.className = 'message success';
                    usernameInput.value = '';
                    // Перезагружаем данные выбранного сервера
                    loadServerData(serverName, true);
                } else {
                    messageDiv.textContent = data.message || 'Error creating user';
                    messageDiv.className = 'message error';
                }
            })
            .catch(error => {
                messageDiv.textContent = 'Request failed: ' + error.message;
                messageDiv.className = 'message error';
            })
            .finally(() => {
                button.disabled = false;
                
                // Очищаем сообщение через 5 секунд
                setTimeout(() => {
                    messageDiv.textContent = '';
                    messageDiv.className = 'message';
                }, 5000);
            });
            
            return false;
        }

        function confirmAction(action, username, serverName, event) {
            event.preventDefault();
    
            console.log('confirmAction called for:', action, username);
    
            SweetAlertLoader.load().then((swalAvailable) => {
                if (swalAvailable && window.Swal) {
                    console.log('Calling showSweetAlertConfirm...');
                    showSweetAlertConfirm(action, username, serverName);
                } else {
                    console.log('Falling back to native confirm');
                    showNativeConfirm(action, username, serverName);
                }
            }).catch(err => {
                console.error('SweetAlertLoader error:', err);
            });
    
            return false;
        }

        // Красивые диалоги через SweetAlert2
        function showSweetAlertConfirm(action, username, serverName) {
            let config = {};
    
            switch(action) {
                case 'ban':
                    config = {
                        title: 'Ban User',
                        text: `Are you sure you want to ban user ${username}?`,
                        icon: 'warning',
                        confirmButtonText: 'Yes, ban!',
                        confirmButtonColor: '#ff9999'
                    };
                    break;
                case 'unban':
                    config = {
                        title: 'Unban User',
                        text: `Unban user ${username}?`,
                        icon: 'question',
                        confirmButtonText: 'Yes, unban!',
                        confirmButtonColor: '#5cb85c'
                    };
                    break;
                case 'renew':
                    config = {
                        title: 'Renew Certificate',
                        text: 'This will revoke the current certificate and issue a new one. The old certificate will stop working.',
                        icon: 'warning',
                        confirmButtonText: 'Yes, renew!',
                        confirmButtonColor: '#ff9800'
                    };
                    break;
                case 'revoke':
                    config = {
                        title: 'Revoke Certificate',
                        text: `This action is IRREVERSIBLE! Certificate for ${username} will be permanently disabled.`,
                        icon: 'error',
                        confirmButtonText: 'Yes, revoke!',
                        confirmButtonColor: '#d33',
                        input: 'text',
                        inputPlaceholder: 'Type "REVOKE" to confirm',
                        inputValidator: (value) => {
                            if (value !== 'REVOKE') {
                                return 'You must type "REVOKE" to confirm';
                            }
                        }
                    };
                    break;
                case 'remove':
                    config = {
                        title: 'Remove Config',
                        text: `Remove user ${username} config file?`,
                        icon: 'warning',
                        confirmButtonText: 'Yes, remove!',
                        confirmButtonColor: '#ff9999'
                    };
                    break;
                default:
                    config = {
                        title: 'Confirm Action',
                        text: `Perform ${action} on ${username}?`,
                        icon: 'question',
                        confirmButtonText: 'Yes'
                        };
            }
    
            window.Swal.fire({
                ...config,
                showCancelButton: true,
                cancelButtonText: 'Cancel',
                cancelButtonColor: '#aaa'
            }).then((result) => {
                if (result.isConfirmed) {
                    handleAction(serverName, action, username);
                }
            });
        }

        // Нативный confirm как последний запасной вариант
        function showNativeConfirm(action, username, serverName) {
            let message;
    
            switch(action) {
                case 'ban':
                    message = `Ban user ${username}?`;
                    break;
                case 'unban':
                    message = `Unban user ${username}?`;
                    break;
                case 'renew':
                    message = `WARNING: Renew certificate for ${username}?\n\nThis will revoke the current certificate and issue a new one.\nThe old certificate will stop working.\n\nContinue?`;
                    break;
                case 'revoke':
                    message = `WARNING: Revoke certificate for ${username}?\n\nThis action is irreversible and will permanently disable the certificate!\n\nAre you ABSOLUTELY sure?`;
                    break;
                case 'remove':
                    message = `Remove user ${username} config file?`;
                    break;
                default:
                    message = `Perform ${action} on ${username}?`;
            }
        
            if (confirm(message)) {
                handleAction(serverName, action, username);
            }
        }

    </script>

&copy; 2024–<?= date('Y') ?> — OpenVPN Status Monitoring.  
Based on <a href="https://github.com/rajven/openvpn-status-page" target="_blank">openvpn-status-page</a> by rajven. All rights reserved.

</body>
</html>
