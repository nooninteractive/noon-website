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
    $root = str_replace('\\', '/', dirname(__DIR__));
    $publicPos = strpos($root, '/public_html');
    $home = $publicPos === false ? dirname($root) : substr($root, 0, $publicPos);
    $configPath = $home . '/pulse-config.php';
    if (getenv('PULSE_TEST_CONFIG') && PHP_SAPI === 'cli-server' && in_array($_SERVER['SERVER_NAME'] ?? '', ['127.0.0.1', 'localhost'], true)) $configPath = getenv('PULSE_TEST_CONFIG');
    if (!is_file($configPath)) fail(503, 'El acceso de usuarios todavía no está configurado. Inténtalo más tarde.');
    $config = require $configPath;
    $origin = rtrim((string)($config['origin'] ?? ''), '/');
    $https = str_starts_with($origin, 'https://');
    if (!$https && !preg_match('~^http://(?:127\.0\.0\.1|localhost):\d+$~D', $origin)) fail(503, 'El acceso requiere una conexión HTTPS.');
    if ($https && ($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['SERVER_PORT'] ?? '') !== '443') fail(400, 'Utiliza la versión HTTPS de la web.');
    if (strlen((string)($config['app_key'] ?? '')) < 32 || str_contains((string)$config['app_key'], 'CHANGE_ME')) fail(503, 'El acceso de usuarios todavía no está configurado.');
    session_name('pulse_session');
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1'); ini_set('session.gc_maxlifetime', '43200');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (isset($_SESSION['user_id']) && (time() - ($_SESSION['last_seen'] ?? 0) > 7200 || time() - ($_SESSION['signed_in'] ?? 0) > 43200)) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $action = $_GET['action'] ?? 'session';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) { header('Allow: GET, POST'); fail(405, 'Método no permitido.'); }
    if ($method === 'POST') {
        if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $origin) fail(403, 'Origen no permitido.');
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') fail(403, 'Origen no permitido.');
        if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) fail(403, 'La sesión ha cambiado. Recarga la página e inténtalo de nuevo.');
        if ($action !== 'upload' && !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) fail(415, 'Formato no permitido.');
        if ($action === 'upload') { $input = $_POST; } else {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 12000) fail(413, 'El contenido es demasiado largo.');
        $raw = file_get_contents('php://input', false, null, 0, 12001);
        if ($raw === false || strlen($raw) > 12000) fail(413, 'El contenido es demasiado largo.');
        try { $decoded = json_decode($raw, false, 16, JSON_THROW_ON_ERROR); } catch (JsonException) { fail(400, 'Petición no válida.'); }
        if (!is_object($decoded)) fail(400, 'Petición no válida.');
        $input = (array)$decoded;
        }
    } else $input = [];
    $dsn = (string)($config['dsn'] ?? '');
    if (!str_starts_with($dsn, 'mysql:') && !(getenv('PULSE_TEST_CONFIG') && !$https && str_starts_with($dsn, 'sqlite:'))) fail(503, 'La base de datos todavía no está configurada.');
    $db = new PDO($dsn, $config['db_user'] ?? null, $config['db_password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $db->exec("SET time_zone = '+00:00'");
    function statement(string $sql, array $values = []): PDOStatement { global $db; $q = $db->prepare($sql); $q->execute($values); return $q; }
    function user(): ?array {
        if (!isset($_SESSION['user_id'])) return null;
        $row = statement('SELECT id, display_name FROM pulse_users WHERE id = ?', [$_SESSION['user_id']])->fetch();
        if (!$row) { unset($_SESSION['user_id']); return null; }
        $_SESSION['last_seen'] = time(); return ['id' => (int)$row['id'], 'name' => $row['display_name']];
    }
    function signedIn(): array { $u = user(); if (!$u) fail(401, 'Inicia sesión para subir contenido.'); return $u; }
    function loginSession(int $id): void { session_regenerate_id(true); $_SESSION = ['user_id' => $id, 'signed_in' => time(), 'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32))]; }
    function throttle(string $kind, int $limit, int $window, string $identity = ''): void {
        global $db, $config;
        $subject = in_array($kind, ['comment_user','upload_user','login_account'], true) ? $identity : ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $identity;
        $key = hash_hmac('sha256', $kind . '|' . $subject, $config['app_key']);
        $bucket = (int)(floor(time() / $window) * $window);
        $sql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT INTO pulse_rate_limits (rate_key, bucket, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1'
            : 'INSERT INTO pulse_rate_limits (rate_key, bucket, hits) VALUES (?, ?, 1) ON CONFLICT(rate_key, bucket) DO UPDATE SET hits = hits + 1';
        statement($sql, [$key, $bucket]);
        $hits = (int)statement('SELECT hits FROM pulse_rate_limits WHERE rate_key = ? AND bucket = ?', [$key, $bucket])->fetchColumn();
        statement('DELETE FROM pulse_rate_limits WHERE bucket < ?', [time() - 86400]);
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
        try { statement('INSERT INTO pulse_users (display_name, email, password_hash) VALUES (?, ?, ?)', [$name, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])]); } catch (PDOException $e) { if ($e->getCode() === '23000' || $e->getCode() === '19') fail(409, 'Ese correo o nombre de usuario ya está registrado.'); throw $e; }
        loginSession((int)$db->lastInsertId()); reply(201, ['user' => user(), 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'login' && $method === 'POST') {
        throttle('login_ip', 30, 900);
        $email = strtolower(trim(textField($input, 'email'))); $password = textField($input, 'password');
        if (strlen($email) > 254 || strlen($password) > 72 || str_contains($password, "\0")) fail(401, 'Correo o contraseña incorrectos.');
        throttle('login_account', 10, 900, $email);
        $row = statement('SELECT id, password_hash FROM pulse_users WHERE email = ?', [$email])->fetch();
        $hash = $row['password_hash'] ?? '$2y$12$ZCmtCbMhKMkgDFAsVMIEPOfWrIZqbPmYFkUvVMIhdFY00WDjaWtR2';
        $verified = password_verify($password, $hash);
        if (!$row || !$verified) fail(401, 'Correo o contraseña incorrectos.');
        if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) statement('UPDATE pulse_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $row['id']]);
        loginSession((int)$row['id']); reply(200, ['user' => user(), 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'logout' && $method === 'POST') { $_SESSION = []; session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); reply(200, ['user' => null, 'csrf' => $_SESSION['csrf']]); }
    function storage(): string {
        global $config;
        $dir = realpath((string)($config['storage_path'] ?? ''));
        $root = realpath(dirname(__DIR__));
        if (!$dir || !$root || !is_writable($dir)) fail(503, 'El almacenamiento todavía no está configurado.');
        $a = str_replace('\\', '/', $dir) . '/'; $b = str_replace('\\', '/', $root) . '/';
        if (str_starts_with($a, $b) || str_contains($a, '/public_html/')) fail(503, 'Configura el almacenamiento fuera de public_html.');
        return $dir;
    }
    if ($action === 'content' && $method === 'GET') {
        $before = filter_var($_GET['before'] ?? '0', FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($before === false) fail(422, 'Página no válida.');
        $u = user(); $sql = 'SELECT c.id,c.user_id,c.title,c.description,c.genre,c.bpm,c.cover_file,c.created_at,u.display_name FROM pulse_content c JOIN pulse_users u ON u.id=c.user_id';
        $values = []; if ($before) { $sql .= ' WHERE c.id < ?'; $values[] = $before; }
        $rows = statement($sql . ' ORDER BY c.id DESC LIMIT 13', $values)->fetchAll(); $more=count($rows)>12; $rows=array_slice($rows,0,12);
        foreach ($rows as &$row) { $row['canDelete']=$u && (int)$row['user_id']===$u['id']; $row['hasCover']=(bool)$row['cover_file']; unset($row['cover_file'],$row['user_id']); } unset($row);
        reply(200,['content'=>$rows,'nextCursor'=>$more?(int)end($rows)['id']:null]);
    }
    if ($action === 'media' && $method === 'GET') {
        $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id) fail(404,'Contenido no encontrado.');
        $row=statement('SELECT audio_file,audio_mime,cover_file,cover_mime FROM pulse_content WHERE id=?',[$id])->fetch();
        if (!$row) fail(404,'Contenido no encontrado.');
        $type=($_GET['type']??'audio')==='cover'?'cover':'audio'; $file=$row[$type.'_file'];
        if (!$file || !preg_match('/^[a-f0-9]{48}\.[a-z0-9]+$/D',$file)) fail(404,'Archivo no encontrado.');
        $path=storage().DIRECTORY_SEPARATOR.$file; if (!is_file($path)) fail(404,'Archivo no encontrado.');
        session_write_close(); $size=filesize($path); $start=0; $end=$size-1;
        header('Content-Type: '.$row[$type.'_mime']); header('Content-Disposition: inline'); header('Accept-Ranges: bytes');
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$_SERVER['HTTP_RANGE'],$m) || ($m[1]==='' && $m[2]==='')) { header('Content-Range: bytes */'.$size); http_response_code(416); exit; }
            if ($m[1]==='') $start=max(0,$size-(int)$m[2]); else { $start=(int)$m[1]; if ($m[2]!=='') $end=min($end,(int)$m[2]); }
            if ($start>$end || $start>=$size) { header('Content-Range: bytes */'.$size); http_response_code(416); exit; }
            http_response_code(206); header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: '.($end-$start+1)); $handle=fopen($path,'rb'); fseek($handle,$start); $left=$end-$start+1;
        while($left>0 && !feof($handle)) { $chunk=fread($handle,min(65536,$left)); if($chunk===false)break; echo $chunk; $left-=strlen($chunk); } fclose($handle); exit;
    }
    if ($action === 'upload' && $method === 'POST') {
        $u=signedIn(); throttle('upload_user',6,3600,(string)$u['id']); throttle('upload_ip',12,3600);
        $title=trim(textField($input,'title')); $description=trim(textField($input,'description')); $genre=$input['genre']??'';
        $bpm=filter_var($input['bpm']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>40,'max_range'=>300]]);
        if(mb_strlen($title)<2 || mb_strlen($title)>120 || mb_strlen($description)>2000 || !$bpm || !in_array($genre,['Electronic','Chill','Pop','Intense'],true)) fail(422,'Revisa el título, descripción, género y BPM (40–300).');
        if(($input['rights']??'')!=='yes') fail(422,'Confirma que puedes compartir este contenido.');
        $dir=storage(); $stored=[]; $bytes=0;
        try {
            $db->beginTransaction();
            statement('SELECT id FROM pulse_users WHERE id=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$u['id']]);
            $used=(int)statement('SELECT COALESCE(SUM(bytes),0) FROM pulse_content WHERE user_id=?',[$u['id']])->fetchColumn();
            foreach(['audio','cover'] as $kind) {
                $f=$_FILES[$kind]??null; if($kind==='cover' && (!$f || ($f['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE))continue;
                if(!$f || !is_int($f['error']??null) || $f['error']!==UPLOAD_ERR_OK || !is_string($f['tmp_name']??null) || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('La subida no se completó. Comprueba el tamaño del archivo.');
                $size=filesize($f['tmp_name']); $limit=$kind==='audio'?25*1024*1024:5*1024*1024;
                if($size<1 || $size>$limit) throw new RuntimeException($kind==='audio'?'El audio debe ocupar como máximo 25 MB.':'La portada debe ocupar como máximo 5 MB.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                $types=$kind==='audio'?['audio/mpeg'=>'mp3','audio/ogg'=>'ogg','application/ogg'=>'ogg','audio/x-wav'=>'wav','audio/wav'=>'wav','audio/vnd.wave'=>'wav']:['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if(!isset($types[$mime])) throw new RuntimeException('Formato no permitido. Usa MP3, OGG o WAV y una portada JPG, PNG o WebP.');
                if($kind==='cover') { $image=@getimagesize($f['tmp_name']); if(!$image || $image[0]>6000 || $image[1]>6000 || $image['mime']!==$mime) throw new RuntimeException('Portada no válida o demasiado grande (máximo 6000 × 6000).'); }
                $bytes+=$size; if($used+$bytes>250*1024*1024)throw new RuntimeException('Tu cuenta ha alcanzado el límite de 250 MB.');
                $name=bin2hex(random_bytes(24)).'.'.$types[$mime]; if(!move_uploaded_file($f['tmp_name'],$dir.DIRECTORY_SEPARATOR.$name))throw new RuntimeException('No se pudo guardar el archivo.');
                $stored[$kind]=['name'=>$name,'mime'=>$mime]; chmod($dir.DIRECTORY_SEPARATOR.$name,0600);
            }
            statement('INSERT INTO pulse_content(user_id,title,description,genre,bpm,audio_file,audio_mime,cover_file,cover_mime,bytes) VALUES(?,?,?,?,?,?,?,?,?,?)',[$u['id'],$title,$description,$genre,$bpm,$stored['audio']['name'],$stored['audio']['mime'],$stored['cover']['name']??null,$stored['cover']['mime']??null,$bytes]);
            $newId=(int)$db->lastInsertId(); $db->commit();
        } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); foreach($stored as $f) @unlink($dir.DIRECTORY_SEPARATOR.$f['name']); if($e instanceof RuntimeException && !($e instanceof PDOException)) fail(422,$e->getMessage()); throw $e; }
        reply(201,['id'=>$newId,'message'=>'Tu contenido ya está publicado.']);
    }
    if ($action === 'delete_content' && $method === 'POST') {
        $u=signedIn(); $id=filter_var($input['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if(!$id)fail(422,'Contenido no válido.');
        $row=statement('SELECT audio_file,cover_file FROM pulse_content WHERE id=? AND user_id=?',[$id,$u['id']])->fetch(); if(!$row)fail(404,'Contenido propio no encontrado.');
        $dir=storage(); statement('DELETE FROM pulse_content WHERE id=? AND user_id=?',[$id,$u['id']]);
        foreach($row as $file)if($file && preg_match('/^[a-f0-9]{48}\.[a-z0-9]+$/D',$file))@unlink($dir.DIRECTORY_SEPARATOR.$file);
        reply(200,['deleted'=>true]);
    }

    fail(404, 'Petición no encontrada.');
} catch (Throwable $e) {
    // Never expose SQL, connection details, passwords or internal paths to visitors.
    error_log('Pulse request failed: ' . get_class($e));
    fail(503, 'No se pudo completar la petición. Inténtalo más tarde.');
}
