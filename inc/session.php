<?php

if (!function_exists('vichan_db_session_start')) {
    function vichan_session_cookie_name(): string {
        return 'vichan_session';
    }

    function vichan_session_table(): string {
        // This table is created by install.sql and is intentionally fixed.
        return 'sessions';
    }

    function vichan_db_session_load_config(): void {
        if (!function_exists('loadConfig')) {
            require_once __DIR__ . '/functions.php';
        }

        if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
            loadConfig();
        }
    }

    function vichan_db_session_start(): void {
        global $_SESSION;

        if (!empty($GLOBALS['_VICHAN_SESSION_STARTED'])) {
            return;
        }

        vichan_db_session_load_config();

        $cookie_name = vichan_session_cookie_name();
        $session_id = $_COOKIE[$cookie_name] ?? '';

        if (!preg_match('/^[a-f0-9]{40}$/', $session_id)) {
            $session_id = '';
        }

        if ($session_id !== '') {
            $table = vichan_session_table();
            $stmt = prepare("SELECT `session_data` FROM `{$table}` WHERE `session_id` = :session_id");
            $stmt->bindValue(':session_id', $session_id, PDO::PARAM_STR);
            $stmt->execute() or error(db_error($stmt));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row !== false) {
                $data = @unserialize($row['session_data']);
                $_SESSION = is_array($data) ? $data : [];
            } else {
                $session_id = '';
            }
        }

        if ($session_id === '') {
            $session_id = bin2hex(random_bytes(20));
            $set_cookie = true;
            $_SESSION = [];
        }

        $GLOBALS['_VICHAN_SESSION_ID'] = $session_id;

        if (!isset($_COOKIE[$cookie_name]) || !empty($set_cookie)) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

            setcookie($cookie_name, $session_id, [
                'expires' => time() + 60 * 60 * 24 * 30,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            $_COOKIE[$cookie_name] = $session_id;
        }

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        $GLOBALS['_VICHAN_SESSION_STARTED'] = true;
        register_shutdown_function('vichan_db_session_write');
    }

    function vichan_db_session_write(): void {
        global $_SESSION;

        if (empty($GLOBALS['_VICHAN_SESSION_STARTED'])) {
            return;
        }

        $session_id = $GLOBALS['_VICHAN_SESSION_ID'] ?? '';
        if ($session_id === '') {
            return;
        }

        $table = vichan_session_table();
        $stmt = prepare("REPLACE INTO `{$table}` (`session_id`, `session_data`, `last_access`) VALUES (:session_id, :session_data, :last_access)");
        $stmt->bindValue(':session_id', $session_id, PDO::PARAM_STR);
        $stmt->bindValue(':session_data', serialize($_SESSION), PDO::PARAM_STR);
        $stmt->bindValue(':last_access', time(), PDO::PARAM_INT);
        $stmt->execute() or error(db_error($stmt));
    }

    function vichan_db_session_destroy(): void {
        global $_SESSION;

        if (empty($GLOBALS['_VICHAN_SESSION_STARTED'])) {
            return;
        }

        $session_id = $GLOBALS['_VICHAN_SESSION_ID'] ?? '';
        if ($session_id !== '') {
            $table = vichan_session_table();
            $stmt = prepare("DELETE FROM `{$table}` WHERE `session_id` = :session_id");
            $stmt->bindValue(':session_id', $session_id, PDO::PARAM_STR);
            $stmt->execute() or error(db_error($stmt));
        }

        setcookie(vichan_session_cookie_name(), '', time() - 31536000, '/');
        unset($_COOKIE[vichan_session_cookie_name()]);
        $_SESSION = [];
        $GLOBALS['_VICHAN_SESSION_STARTED'] = false;
    }
}
