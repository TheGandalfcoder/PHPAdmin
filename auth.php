<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// shorthand for escaping output — used everywhere in templates
if (!function_exists('e')) {
    function e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function current_user(): ?array {
    return $_SESSION['auth_user'] ?? null;
}

function is_logged_in(): bool {
    return !empty($_SESSION['auth_user']);
}

function is_admin(): bool {
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login(): void {
    if (!is_logged_in()) {
        $next = isset($_SERVER['REQUEST_URI']) ? '?next=' . urlencode($_SERVER['REQUEST_URI']) : '';
        header('Location: login.php' . $next);
        exit;
    }
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Access denied.');
    }
}

// regenerate the session id on login to prevent session fixation attacks
function login_set_user(array $row): void {
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'id'    => (int)$row['id'],
        'name'  => $row['name'],
        'email' => $row['email'],
        'role'  => $row['role'],
    ];
}

function logout_destroy(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
    $t = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t);
}
