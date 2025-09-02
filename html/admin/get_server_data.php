<?php

session_start();

// 1. Проверяем AJAX-запрос
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    dieAjaxError('Direct access not allowed');
}

// 2. Проверяем CSRF-токен
//if (empty($_GET['csrf']) || $_GET['csrf'] !== $_SESSION['csrf_token']) {
//    dieAjaxError('Invalid CSRF token');
//}

// Если все проверки пройдены, выполняем основной код
function dieAjaxError($message) {
    header('HTTP/1.0 403 Forbidden');
    header('Content-Type: application/json');
    die(json_encode(['error' => $message]));
}

define("CONFIG", 1);

require_once 'functions.php';

$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    die("Configuration file not found: $config_file");
}

$servers = require_once $config_file;

$server_name = $_GET['server'] ?? '';
$action = $_GET['action'] ?? '';
$username = $_GET['username'] ?? '';

if (!isset($servers[$server_name])) {
    die("Invalid server name");
}

$server = $servers[$server_name];
$clients = getOpenVPNStatus($server);
$banned_clients = getBannedClients($server);
$accounts = getAccountList($server);

// Генерируем HTML для этого сервера
ob_start();
?>
<h2><?= htmlspecialchars($server['title']) ?></h2>


<div class="section">
    <h3>Active Connections</h3>
    <?php if (!empty($clients)): ?>
    <table>
        <thead>
            <tr>
                <th>Client</th>
                <th>Real IP</th>
                <th>Virtual IP</th>
                <th>Traffic</th>
                <th>Connected</th>
                <th>Cipher</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($clients as $client): ?>
            <tr class="<?= $client['banned'] ? 'banned' : '' ?>">
                <td>
                    <a href="#" onclick="return generateConfig('<?= $server_name ?>', '<?= htmlspecialchars($client['name']) ?>', event)">
                        <?= htmlspecialchars($client['name']) ?>
                    </a>
                </td>
                <td><?= htmlspecialchars($client['real_ip']) ?></td>
                <td><?= htmlspecialchars($client['virtual_ip']) ?></td>
                <td>↓<?= $client['bytes_received'] ?> ↑<?= $client['bytes_sent'] ?></td>
                <td><?= htmlspecialchars($client['connected_since']) ?></td>
                <td><?= htmlspecialchars($client['cipher']) ?></td>
                <td>
                    <span class="status-badge <?= $client['banned'] ? 'status-banned' : 'status-active' ?>">
                        <?= $client['banned'] ? 'BANNED' : 'Active' ?>
                    </span>
                </td>
                <td class="actions">
                    <?php if ($client['banned']): ?>
                        <button onclick="handleAction('<?= $server_name ?>', 'unban', '<?= htmlspecialchars($client['name']) ?>')" 
                                class="btn unban-btn">Unban</button>
                    <?php else: ?>
                        <button onclick="handleAction('<?= $server_name ?>', 'ban', '<?= htmlspecialchars($client['name']) ?>')" 
                                class="btn ban-btn">Ban</button>
                    <?php endif; ?>
		    <?php if (!empty($server['cert_index'])): ?>
                        <button onclick="handleAction('<?= $server_name ?>', 'revoke', '<?= htmlspecialchars($client['name']) ?>')" 
                	        class="btn ban-btn">Revoke</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p>No active connections</p>
    <?php endif; ?>
</div>

<div class="section">
    <div class="spoiler">
        <div class="spoiler-title collapsed" onclick="toggleSpoiler(this)">
            Configured Account List (<?= count($accounts) ?>)
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
                    <?php foreach ($accounts as $account):
                    if (isClientActive($clients,$account["username"])) { continue; }
                    ?>
                    <tr>
                        <td>
                            <a href="#" onclick="return generateConfig('<?= $server_name ?>', '<?= htmlspecialchars($account['username']) ?>', event)">
                                <?= htmlspecialchars($account['username']) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($account['ip'] ?? 'N/A') ?></td>
                        <?php
                        $is_revoked = $account['revoked'];
                        $is_banned = $account['banned'];
                        $status_class = $is_revoked ? 'status-banned' : ($is_banned ? 'status-banned' : 'status-active');
                        $status_text = $is_revoked ? 'REVOKED' : ($is_banned ? 'BANNED' : 'ENABLED');
                        ?>
                        
                        <td>
                            <span class="status-badge <?= $status_class ?>">
                                <?= $status_text ?>
                            </span>
                        </td>
                        <td class="actions">
                            <?php if ($is_revoked): ?>
                                <span class="revoked-text">Certificate revoked</span>
                            <?php else: ?>
                                <?php if ($is_banned): ?>
		        	    <button onclick="return confirmAction('unban', '<?= htmlspecialchars($account['username']) ?>', '<?= $server_name ?>', event)"
                                            class="btn unban-btn">Unban</button>
                                <?php else: ?>
			            <button onclick="return confirmAction('ban', '<?= htmlspecialchars($account['username']) ?>', '<?= $server_name ?>', event)"
                                            class="btn ban-btn">Ban</button>
                                <?php endif; ?>
				<?php if (!empty($server['cert_index'])): ?>
			        <button onclick="return confirmAction('revoke', '<?= htmlspecialchars($account['username']) ?>', '<?= $server_name ?>', event)"
                                        class="btn revoke-btn">Revoke</button>
                                <?php else: ?>
			        <button onclick="return confirmAction('remove', '<?= htmlspecialchars($account['username']) ?>', '<?= $server_name ?>', event)"
                                        class="btn remove-btn">Remove CCD</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="last-update">
    Last update: <?= date('Y-m-d H:i:s') ?>
</div>
<?php
echo ob_get_clean();
