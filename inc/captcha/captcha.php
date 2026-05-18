<?php
chdir(__DIR__ . '/../../');
require_once __DIR__ . '/../bootstrap.php';
vichan_db_session_start();

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// If ?refresh=1 is set, force a new captcha
if (isset($_GET['refresh']) && $_GET['refresh'] == '1') {
    unset($_SESSION['captcha']);
}

// Generate a random 5-character alphanumeric string if not set
if (!isset($_SESSION['captcha'])) {
    $chars = 'ABCDEFGHJKLMNPRSTUVWXYZabcdefghjkmnprstuvwxyz23456789';
    $captcha_text = substr(str_shuffle($chars), 0, 5);
    $_SESSION['captcha'] = $captcha_text;
} else {
    $captcha_text = $_SESSION['captcha'];
}

// Create image
$img = imagecreatetruecolor(120, 40);
$bg = imagecolorallocate($img, 255, 255, 255); // white
$fg = imagecolorallocate($img, 0, 0, 0);       // black

imagefilledrectangle($img, 0, 0, 120, 40, $bg);
imagettftext($img, 18, rand(-5, 5), 10, 30, $fg, __DIR__ . '/monofont.ttf', $captcha_text);

// Add noise lines
for ($i = 0; $i < 5; $i++) {
    $line_color = imagecolorallocate($img, rand(150,255), rand(150,255), rand(150,255));
    imageline($img, 0, rand(0,40), 120, rand(0,40), $line_color);
}

header('Content-Type: image/png');
imagepng($img);
if (PHP_VERSION_ID < 80500 && function_exists('imagedestroy')) {
    @imagedestroy($img);
}