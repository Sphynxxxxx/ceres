<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$was_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

if ($was_logged_in) {
    header("Location: login.php?logout=success");
} else {
    header("Location: login.php");
}
exit;
?>