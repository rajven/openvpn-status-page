<?php
// Настройки
$page_title = 'OpenVPN Status';

// Ограничение частоты запросов (в секундах)
define('REQUEST_INTERVAL', 60);

// Подключаем конфигурационный файл
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    die("Configuration file not found: $config_file");
}
$servers = require $config_file;

session_start();

// Проверяем и инициализируем массив, если его нет
if (!isset($_SESSION['last_request_time']) || !is_array($_SESSION['last_request_time'])) {
    $_SESSION['last_request_time'] = []; // Создаем пустой массив
}

function canRequestStatus($server) {
    if (!isset($_SESSION['last_request_time'][$server['name']])) { return true; }
    if (time() - $_SESSION['last_request_time'][$server['name']] >= REQUEST_INTERVAL) { return true; }
    return false;
}

function updateLastRequestTime($server) {
    $_SESSION['last_request_time'][$server['name']] = time();
}

function openvpnManagementCommand($server, $command) {
    $mgmt_host = $server['host'];
    $mgmt_port = $server['port'];
    $mgmt_pass = $server['password'];

    $timeout = 5;
    $socket = @fsockopen($mgmt_host, $mgmt_port, $errno, $errstr, $timeout);
    
    if (!$socket) {
        error_log("OpenVPN management connection failed to {$server['name']}: $errstr ($errno)");
        return false;
    }

    stream_set_timeout($socket, $timeout);
    
    try {
        // Читаем приветственное сообщение
        $welcome = '';
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) break;
            $welcome .= $line;
            if (strpos($welcome, 'ENTER PASSWORD:') !== false) break;
        }

        // Отправляем пароль
        if (@fwrite($socket, "$mgmt_pass\n") === false) {
            throw new Exception("Failed to send password");
        }

        // Ждем подтверждения аутентификации
        $authResponse = '';
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) break;
            $authResponse .= $line;
            if (strpos($authResponse, 'SUCCESS:') !== false || strpos($authResponse, '>INFO:') !== false) break;
        }

        // Отправляем команду
        if (@fwrite($socket, "$command\n") === false) {
            throw new Exception("Failed to send command");
        }

        // Читаем ответ
        $response = '';
        $expectedEnd = strpos($command, 'status') !== false ? "END\r\n" : ">";
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) break;
            $response .= $line;
            if (strpos($response, $expectedEnd) !== false) break;
        }

        return $response;

    } catch (Exception $e) {
        error_log("OpenVPN management error ({$server['name']}): " . $e->getMessage());
        return false;
    } finally {
        @fwrite($socket, "quit\n");
        @fclose($socket);
    }
}

function getOpenVPNStatus($server) {
    // Проверяем, можно ли делать запрос
    if (!canRequestStatus($server)) {
        // Возвращаем кэшированные данные или пустой массив
        return $_SESSION['cached_status'][$server['name']] ?? [];
    }

    // Обновляем время последнего запроса
    updateLastRequestTime($server);

    $response = openvpnManagementCommand($server, "status 2");
    if (!$response) return $_SESSION['cached_status'][$server['name']] ?? [];

    $clients = [];
    $lines = explode("\n", $response);
    $in_client_list = false;

    foreach ($lines as $line) {
        $line = trim($line);

        if (strpos($line, 'HEADER,CLIENT_LIST') === 0) {
            $in_client_list = true;
            continue;
        }

        if (strpos($line, 'HEADER,ROUTING_TABLE') === 0) {
            $in_client_list = false;
            continue;
        }

        if ($in_client_list && strpos($line, 'CLIENT_LIST') === 0) {
            $parts = explode(',', $line);
            if (count($parts) >= 9) {
                $clients[] = [
                    'name' => $parts[1],
                    'real_ip' => $parts[2],
                    'virtual_ip' => $parts[3],
                    'bytes_received' => formatBytes($parts[5]),
                    'bytes_sent' => formatBytes($parts[6]),
                    'connected_since' => $parts[7],
                    'username' => $parts[8] ?? $parts[1],
                    'banned' => isClientBanned($server, $parts[1])
                ];
            }
        }
    }

    // Кэшируем результат
    $_SESSION['cached_status'][$server['name']] = $clients;

    return $clients;
}

function getAccountList($server) {
    $accounts = [];
    
    // Получаем список из index.txt (неотозванные сертификаты)
    if (file_exists($server['cert_index'])) {
        $index_content = file_get_contents($server['cert_index']);
        $lines = explode("\n", $index_content);
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 6 && $parts[0] === 'V') { // Только валидные сертификаты
                $username = $parts[5];
                $accounts[$username]["username"] = $username;
                $accounts[$username]["ip"] = $ip;
                $accounts[$username]["banned"] = false;
                if (isClientBanned($server,$username)) { 
                    $accounts[$username]["banned"] = true;
                    }
            }
        }
    }

    // Получаем список выданных IP из ipp.txt
    $assigned_ips = [];
    if (file_exists($server['ipp_file'])) {
        $ipp_content = file_get_contents($server['ipp_file']);
        $lines = explode("\n", $ipp_content);

        foreach ($lines as $line) {
            if (empty(trim($line))) continue;

            $parts = explode(',', $line);
            if (count($parts) >= 2) {
                $username = $parts[0];
                $ip = $parts[1];
                $accounts[$username]["username"] = $username;
                $accounts[$username]["ip"] = $ip;
                $accounts[$username]["banned"] = false;
                if (isClientBanned($server,$username)) { 
                    $accounts[$username]["banned"] = true;
                    }
            }
        }
    }

    return $accounts;
}

function isClientBanned($server, $client_name) {
    $ccd_file = "{$server['ccd']}/$client_name";
    return file_exists($ccd_file) && 
           preg_match('/^disable$/m', file_get_contents($ccd_file));
}

function kickClient($server, $client_name) {
    return openvpnManagementCommand($server, "kill $client_name");
}

function banClient($server, $client_name) {
    $ccd_file = "{$server['ccd']}/$client_name";
    
    // Добавляем директиву disable
    $content = file_exists($ccd_file) ? file_get_contents($ccd_file) : '';
    if (!preg_match('/^disable$/m', $content)) {
        file_put_contents($ccd_file, $content . "\ndisable\n");
    }

    // Кикаем клиента
    kickClient($server, $client_name);
    return true;
}

function unbanClient($server, $client_name) {
    $ccd_file = "{$server['ccd']}/$client_name";
    if (file_exists($ccd_file)) {
        $content = file_get_contents($ccd_file);
        $new_content = preg_replace('/^disable$\n?/m', '', $content);
        file_put_contents($ccd_file, $new_content);
        return true;
    }
    return false;
}

function formatBytes($bytes) {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes)/log(1024));
    return round($bytes/pow(1024,$pow),2).' '.$units[$pow];
}

function getBannedClients($server, $active_clients) {
    $banned = [];
    $active_names = array_column($active_clients, 'name');
    
    if (is_dir($server['ccd'])) {
        foreach (scandir($server['ccd']) as $file) {
            if ($file !== '.' && $file !== '..' && is_file("{$server['ccd']}/$file")) {
                if (isClientBanned($server, $file) && !in_array($file, $active_names)) {
                    $banned[] = $file;
                }
            }
        }
    }
    
    return $banned;
}

function isClientActive($active_clients,$username) {
    $active_names = array_column($active_clients, 'name');
    if (!empty($active_names[$username])) { return true; }
    return false;
}

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($servers as $server_name => $server) {
        if (isset($_POST["ban-$server_name"])) {
            banClient($server, $_POST["ban-$server_name"]);
        } elseif (isset($_POST["unban-$server_name"])) {
            unbanClient($server, $_POST["unban-$server_name"]);
        }
    }
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta http-equiv="refresh" content="<?= REQUEST_INTERVAL ?>">
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
        .spoiler-title:after {
            content: " ▼";
        }
        .spoiler-title.collapsed:after {
            content: " ►";
        }
        .spoiler-content { 
            display: none; 
            padding: 10px; 
            border: 1px solid #ddd; 
            margin-top: 5px; 
            background-color: #f9f9f9; 
            border-radius: 3px;
        }
    </style>
    <script>
        function toggleSpoiler(button) {
            var content = button.nextElementSibling;
            if (content.style.display === "block") {
                content.style.display = "none";
                button.classList.add('collapsed');
            } else {
                content.style.display = "block";
                button.classList.remove('collapsed');
            }
        }
    </script>
</head>
<body>
    <h1><?= htmlspecialchars($page_title) ?></h1>
    
    <form method="post">
    <?php foreach ($servers as $server_name => $server): 
        $clients = getOpenVPNStatus($server);
        $banned_clients = getBannedClients($server, $clients);
        $accounts = getAccountList($server);
    ?>
    <div class="server-section">
        <h2><?= htmlspecialchars($server['title']) ?></h2>
        
        <div class="section">
            <h3>Active Connections</h3>
            <table>
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Real IP</th>
                        <th>Virtual IP</th>
                        <th>Traffic</th>
                        <th>Connected</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client): ?>
                    <tr class="<?= $client['banned'] ? 'banned' : '' ?>">
                        <td><?= htmlspecialchars($client['name']) ?></td>
                        <td><?= htmlspecialchars($client['real_ip']) ?></td>
                        <td><?= htmlspecialchars($client['virtual_ip']) ?></td>
                        <td>↓<?= $client['bytes_received'] ?> ↑<?= $client['bytes_sent'] ?></td>
                        <td><?= htmlspecialchars($client['connected_since']) ?></td>
                        <td>
                            <span class="status-badge <?= $client['banned'] ? 'status-banned' : 'status-active' ?>">
                                <?= $client['banned'] ? 'BANNED' : 'Active' ?>
                            </span>
                        </td>
                        <td class="actions">
                            <?php if ($client['banned']): ?>
                                <button type="submit" name="unban-<?= $server_name ?>" value="<?= htmlspecialchars($client['name']) ?>" 
                                        class="btn unban-btn">Unban</button>
                            <?php else: ?>
                                <button type="submit" name="ban-<?= $server_name ?>" value="<?= htmlspecialchars($client['name']) ?>" 
                                        class="btn ban-btn">Ban</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <div class="spoiler">
                <div class="spoiler-title collapsed" onclick="toggleSpoiler(this)">
                    Account List (<?= count($accounts) ?>)
                </div>
                <div class="spoiler-content">
                    <table>
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Assigned IP</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td><?= htmlspecialchars($account["username"]) ?></td>
                                <td><?= htmlspecialchars($account['ip'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="status-badge <?= $account['banned'] ? 'status-banned' : 'status-active' ?>">
                                        <?= $account['banned'] ? 'BANNED' : 'ENABLED' ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <?php if ($account['banned']): ?>
                                        <button type="submit" name="unban-<?= $server_name ?>" value="<?= htmlspecialchars($account['username']) ?>" 
                                                class="btn unban-btn">Unban</button>
                                    <?php else: ?>
                                        <button type="submit" name="ban-<?= $server_name ?>" value="<?= htmlspecialchars($account['username']) ?>" 
                                                class="btn ban-btn">Ban</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <p>Next update in: <?= REQUEST_INTERVAL - (time() - $_SESSION['last_request_time'][$server['name']]) ?> seconds</p>

    <?php endforeach; ?>
    </form>
</body>
</html>

