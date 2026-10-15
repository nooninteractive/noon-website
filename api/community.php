<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
ini_set('display_errors', '0');
function reply(int $status, array $data): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit; }
function fail(int $status, string $message): never { reply($status, ['error' => $message]); }
try {
    // The production config is outside public_html and never included in the ZIP.
    $configPath = dirname(__DIR__, 2) . '/noon-config.php';
    if (getenv('NOON_TEST_CONFIG') && PHP_SAPI === 'cli-server' && in_array($_SERVER['SERVER_NAME'] ?? '', ['127.0.0.1', 'localhost'], true)) $configPath = getenv('NOON_TEST_CONFIG');
    if (!is_file($configPath)) fail(503, 'El acceso de usuarios todavía no está configurado. Inténtalo más tarde.');
    $config = require $configPath;
    $origin = rtrim((string)($config['origin'] ?? ''), '/');
    $https = str_starts_with($origin, 'https://');
    if (!$https && !preg_match('~^http://(?:127\.0\.0\.1|localhost):\d+$~D', $origin)) fail(503, 'El acceso requiere una conexión HTTPS.');
    if ($https && ($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['SERVER_PORT'] ?? '') !== '443') fail(400, 'Utiliza la versión HTTPS de la web.');
    if (strlen((string)($config['app_key'] ?? '')) < 32 || str_contains((string)$config['app_key'], 'CHANGE_ME')) fail(503, 'El acceso de usuarios todavía no está configurado.');
    session_name('noon_session');
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1'); ini_set('session.gc_maxlifetime', '43200');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (isset($_SESSION['user_id']) && (time() - ($_SESSION['last_seen'] ?? 0) > 7200 || time() - ($_SESSION['signed_in'] ?? 0) > 43200)) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) { header('Allow: GET, POST'); fail(405, 'Método no permitido.'); }
    if ($method === 'POST') {
        if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $origin) fail(403, 'Origen no permitido.');
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') fail(403, 'Origen no permitido.');
        if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) fail(403, 'La sesión ha cambiado. Recarga la página e inténtalo de nuevo.');
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) fail(415, 'Formato no permitido.');
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 12000) fail(413, 'El contenido es demasiado largo.');
        $raw = file_get_contents('php://input', false, null, 0, 12001);
        if ($raw === false || strlen($raw) > 12000) fail(413, 'El contenido es demasiado largo.');
        try { $decoded = json_decode($raw, false, 16, JSON_THROW_ON_ERROR); } catch (JsonException) { fail(400, 'Petición no válida.'); }
        if (!is_object($decoded)) fail(400, 'Petición no válida.');
        $input = (array)$decoded;
    } else $input = [];
    $dsn = (string)($config['dsn'] ?? '');
    if (!str_starts_with($dsn, 'mysql:') && !(getenv('NOON_TEST_CONFIG') && !$https && str_starts_with($dsn, 'sqlite:'))) fail(503, 'La base de datos todavía no está configurada.');
    $db = new PDO($dsn, $config['db_user'] ?? null, $config['db_password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $db->exec("SET time_zone = '+00:00'");
    function statement(string $sql, array $values = []): PDOStatement { global $db; $q = $db->prepare($sql); $q->execute($values); return $q; }
    function user(): ?array {
        if (!isset($_SESSION['user_id'])) return null;
        $row = statement('SELECT id, display_name FROM noon_users WHERE id = ?', [$_SESSION['user_id']])->fetch();
        if (!$row) { unset($_SESSION['user_id']); return null; }
        $_SESSION['last_seen'] = time(); return ['id' => (int)$row['id'], 'name' => $row['display_name']];
    }
    function signedIn(): array { $u = user(); if (!$u) fail(401, 'Inicia sesión para comentar.'); return $u; }
    function loginSession(int $id): void { session_regenerate_id(true); $_SESSION = ['user_id' => $id, 'signed_in' => time(), 'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32))]; }
    function throttle(string $kind, int $limit, int $window, string $identity = ''): void {
        global $db, $config;
        $subject = $kind === 'comment_user' ? $identity : ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $identity;
        $key = hash_hmac('sha256', $kind . '|' . $subject, $config['app_key']);
        $bucket = (int)(floor(time() / $window) * $window);
        $sql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT INTO noon_rate_limits (rate_key, bucket, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1'
            : 'INSERT INTO noon_rate_limits (rate_key, bucket, hits) VALUES (?, ?, 1) ON CONFLICT(rate_key, bucket) DO UPDATE SET hits = hits + 1';
        statement($sql, [$key, $bucket]);
        $hits = (int)statement('SELECT hits FROM noon_rate_limits WHERE rate_key = ? AND bucket = ?', [$key, $bucket])->fetchColumn();
        statement('DELETE FROM noon_rate_limits WHERE bucket < ?', [time() - 86400]);
        if ($hits > $limit) { header('Retry-After: ' . ($bucket + $window - time())); fail(429, 'Demasiados intentos. Espera un poco antes de volver a intentarlo.'); }
    }
    function textField(array $input, string $key): string { if (!isset($input[$key]) || !is_string($input[$key])) fail(422, 'Revisa los campos del formulario.'); return $input[$key]; }
    function scope(array $data): array { $game = $data['game'] ?? ''; $area = $data['area'] ?? ''; if (!in_array($game, ['eldervale', 'node'], true) || !in_array($area, ['game', 'roadmap'], true)) fail(422, 'Juego o sección no válidos.'); return [$game, $area]; }
    function commentRow(array $row, ?array $u): array { return ['id' => (int)$row['id'], 'name' => $row['display_name'], 'body' => $row['body'], 'createdAt' => gmdate('c', strtotime($row['created_at'] . ' UTC')), 'canDelete' => $u !== null && (int)$row['user_id'] === $u['id']]; }
    $action = $_GET['action'] ?? 'session';
    if ($action === 'session' && $method === 'GET') reply(200, ['user' => user(), 'csrf' => $_SESSION['csrf']]);
    if ($action === 'register' && $method === 'POST') {
        throttle('register', 5, 3600);
        $name = trim(textField($input, 'name')); $email = strtolower(trim(textField($input, 'email'))); $password = textField($input, 'password');
        if (!preg_match('/^[\p{L}\p{N} _.-]{2,40}$/uD', $name)) fail(422, 'El nombre debe tener entre 2 y 40 caracteres: letras, números, espacios, punto, guion o guion bajo.');
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail(422, 'Introduce un correo válido.');
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) fail(422, 'La contraseña debe tener entre 12 y 72 bytes.');
        try { statement('INSERT INTO noon_users (display_name, email, password_hash) VALUES (?, ?, ?)', [$name, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])]); } catch (PDOException $e) { if ($e->getCode() === '23000' || $e->getCode() === '19') fail(409, 'Ese correo o nombre de usuario ya está registrado.'); throw $e; }
        loginSession((int)$db->lastInsertId()); reply(201, ['user' => user(), 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'login' && $method === 'POST') {
        throttle('login_ip', 30, 900);
        $email = strtolower(trim(textField($input, 'email'))); $password = textField($input, 'password');
        if (strlen($email) > 254 || strlen($password) > 72 || str_contains($password, "\0")) fail(401, 'Correo o contraseña incorrectos.');
        throttle('login_account', 10, 900, $email);
        $row = statement('SELECT id, password_hash FROM noon_users WHERE email = ?', [$email])->fetch();
        $hash = $row['password_hash'] ?? '$2y$12$ZCmtCbMhKMkgDFAsVMIEPOfWrIZqbPmYFkUvVMIhdFY00WDjaWtR2';
        $verified = password_verify($password, $hash);
        if (!$row || !$verified) fail(401, 'Correo o contraseña incorrectos.');
        if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) statement('UPDATE noon_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $row['id']]);
        loginSession((int)$row['id']); reply(200, ['user' => user(), 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'logout' && $method === 'POST') { $_SESSION = []; session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); reply(200, ['user' => null, 'csrf' => $_SESSION['csrf']]); }
    if ($action === 'comments' && $method === 'GET') {
        [$game, $area] = scope($_GET); $before = filter_var($_GET['before'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]); if ($before === false) fail(422, 'Página no válida.');
        $u = user(); $values = [$game, $area]; $sql = 'SELECT c.id, c.user_id, c.body, c.created_at, u.display_name FROM noon_comments c JOIN noon_users u ON c.user_id = u.id WHERE c.game = ? AND c.area = ?';
        if ($before > 0) { $sql .= ' AND c.id < ?'; $values[] = $before; } $sql .= ' ORDER BY c.id DESC LIMIT 21';
        $rows = statement($sql, $values)->fetchAll(); $more = count($rows) > 20; $rows = array_slice($rows, 0, 20);
        reply(200, ['comments' => array_map(fn($r) => commentRow($r, $u), $rows), 'nextCursor' => $more ? (int)$rows[count($rows)-1]['id'] : null]);
    }
    if ($action === 'comments' && $method === 'POST') {
        $u = signedIn(); [$game, $area] = scope($input); $body = trim(str_replace("\r\n", "\n", textField($input, 'body')));
        if (mb_strlen($body, 'UTF-8') < 1 || mb_strlen($body, 'UTF-8') > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) fail(422, 'El comentario debe tener entre 1 y 2.000 caracteres.');
        throttle('comment_user', 5, 60, (string)$u['id']); throttle('comment_ip', 30, 600);
        statement('INSERT INTO noon_comments (user_id, game, area, body) VALUES (?, ?, ?, ?)', [$u['id'], $game, $area, $body]);
        $id = (int)$db->lastInsertId(); $row = statement('SELECT c.id, c.user_id, c.body, c.created_at, u.display_name FROM noon_comments c JOIN noon_users u ON c.user_id = u.id WHERE c.id = ?', [$id])->fetch();
        reply(201, ['comment' => commentRow($row, $u)]);
    }
    if ($action === 'delete_comment' && $method === 'POST') {
        $u = signedIn(); $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); if ($id === false) fail(422, 'Comentario no válido.');
        $q = statement('DELETE FROM noon_comments WHERE id = ? AND user_id = ?', [$id, $u['id']]); if ($q->rowCount() !== 1) fail(404, 'No se encontró un comentario propio con ese identificador.'); reply(200, ['deleted' => true]);
    }
    fail(404, 'Petición no encontrada.');
} catch (Throwable $e) {
    // Never expose SQL, connection details, passwords or internal paths to visitors.
    error_log('Noon community request failed: ' . get_class($e));
    fail(503, 'No se pudo completar la petición. Inténtalo más tarde.');
}
