<?php
/*
 *  Copyright (c) 2010-2014 Tinyboard Development Group
 */

require_once 'inc/bootstrap.php';

use Vichan\{Context, WebDependencyFactory};
use Vichan\Data\Driver\{LogDriver, HttpDriver};
use Vichan\Functions\Format;
use GeoIp2\Database\Reader;

/**
 * Utility functions
 */

/**
 * Get the md5 hash of the file.
 *
 * @param array $config instance configuration.
 * @param string $file file to the the md5 of.
 * @return string|false
 */
function md5_hash_of_file($config, $path) {
    $cmd = false;
    if ($config['bsd_md5']) {
        $cmd = '/sbin/md5 -r';
    }
    if ($config['gnu_md5']) {
        $cmd = 'md5sum';
    }

    if ($cmd) {
        $output = shell_exec_error($cmd . " " . escapeshellarg($path));
        $output = explode(' ', $output);
        return $output[0];
    } else {
        return md5_file($path);
    }
}

function getThreadPage($thread_id) {
    global $board, $config;

    $query = prepare("SELECT `id` FROM `posts` WHERE `board` = :board AND `thread` IS NULL ORDER BY `bump` DESC");
    $query->bindValue(':board', $board['uri'], PDO::PARAM_STR);
    $query->execute() or error(db_error());
    $position = 0;

    while ($th = $query->fetch(PDO::FETCH_ASSOC)) {
        if ($th['id'] == $thread_id) {
            return floor($position / $config['threads_per_page']) + 1;
        }
        $position++;
    }

    return 1; // fallback
}

/**
 * Download a remote file via URL into a temp file, validating size and extension.
 *
 * @param string $url            The remote file URL.
 * @param array  $config         Global configuration array.
 * @param bool   $is_op_post     True if this is an op post (to check allowed_ext_op).
 *
 * @return array Returns an array suitable for $_FILES['file']:
 *               [
 *                 'name'     => string,  // original filename
 *                 'tmp_name' => string,  // local temp filename
 *                 'size'     => int,     // file size
 *               ]
 *
 * @throws RuntimeException on any validation or download error.
 */
function downloadFileByUrl(string $url, array $config, bool $is_op_post = false): array {
    // Basic URL format check
    if (!preg_match('~^https?://~i', $url)) {
        throw new RuntimeException($config['error']['invalidimg']);
    }

    // Strip query string for extension & basename
    $urlNoParams = strtok($url, '?');
    $extension   = strtolower(pathinfo($urlNoParams, PATHINFO_EXTENSION));

    // Validate extension
    $allowed = $is_op_post && !empty($config['allowed_ext_op'])
        ? $config['allowed_ext_op']
        : array_merge($config['allowed_ext'], $config['allowed_ext_files']);
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException($config['error']['unknownext']);
    }

    // Create a temporary filename
    $tmpFile = tempnam($config['tmp'], 'url_');
    if (!$tmpFile) {
        throw new RuntimeException('Unable to create temporary file.');
    }
    register_shutdown_function(function() use ($tmpFile) {
        @unlink($tmpFile);
    });

    // Prepare cURL
    $fp   = fopen($tmpFile, 'w');
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $config['upload_by_url_timeout'],
        CURLOPT_USERAGENT      => 'Tinyboard',
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        // Uncomment these only if you really cannot configure a CA bundle:
        // CURLOPT_SSL_VERIFYPEER => false,
        // CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    // Download
    if (!curl_exec($curl)) {
        $err = curl_error($curl);
        curl_close($curl);
        fclose($fp);

        if (stripos($err, 'SSL certificate problem') !== false) {
            throw new RuntimeException('SSL verification failed; please check your CA bundle.');
        }
        throw new RuntimeException($config['error']['nomove'] . '<br/>Curl says: ' . $err);
    }

    curl_close($curl);
    fclose($fp);

    // Enforce max filesize
    $size = filesize($tmpFile);
    if ($size === false || $size > $config['max_filesize']) {
        @unlink($tmpFile);
        throw new RuntimeException($config['error']['toobig']);
    }

    return [
        'name'     => basename($urlNoParams),
        'tmp_name' => $tmpFile,
        'size'     => $size,
    ];
}

/**
 * Strip the symbols incompatible with the current database version.
 *
 * @param string @input The input string.
 * @return string The value stripped of incompatible symbols.
 */
function strip_symbols($input) {
    if (mysql_version() >= 50503) {
        return $input; // Assume we're using the utf8mb4 charset
    } else {
        // MySQL's `utf8` charset only supports up to 3-byte symbols
        // Remove anything >= 0x010000

        $chars = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY);
        $ret = '';
        foreach ($chars as $char) {
            $o = 0;
            $ord = ordutf8($char, $o);
            if ($ord >= 0x010000) {
                continue;
            }
            $ret .= $char;
        }
        return $ret;
    }
}

/**
 * Try extract text from the given image.
 *
 * @param array $config Instance configuration.
 * @param string $img_path The file path to the image.
 * @return string|false Returns a string with the extracted text on success (if any).
 * @throws RuntimeException Throws if executing tesseract fails.
 */
function ocr_image(array $config, string $img_path): string {
    // The default preprocess command is an ImageMagick b/w quantization.
    $ret = shell_exec_error(
        sprintf($config['tesseract_preprocess_command'], escapeshellarg($img_path))
         . ' | tesseract stdin stdout 2>/dev/null'
         . $config['tesseract_params']
    );
    if ($ret === false) {
        throw new RuntimeException('Unable to run tesseract');
    }

    return trim($ret);
}


/**
 * Trim an image's EXIF metadata
 *
 * @param string $img_path The file path to the image.
 * @return int The size of the stripped file.
 * @throws RuntimeException Throws on IO errors.
 */
function strip_image_metadata(string $img_path): int {
    $err = shell_exec_error('exiftool -overwrite_original -ignoreMinorErrors -q -q -all= -Orientation ' . escapeshellarg($img_path));
    if ($err === false) {
        throw new RuntimeException('Could not strip EXIF metadata!');
    }
    clearstatcache(true, $img_path);
    $ret = filesize($img_path);
    if ($ret === false) {
        throw new RuntimeException('Could not calculate file size!');
    }
    return $ret;
}

/**
 * Delete posts in a cyclical thread.
 *
 * @param string $boardUri The URI of the board.
 * @param int $threadId The ID of the thread.
 * @param int $cycleLimit The number of most recent posts to retain.
 */
function delete_cyclical_posts(string $boardUri, int $threadId, int $cycleLimit): void
{
    $query = prepare('
        SELECT p.`id`
        FROM `posts` p
        LEFT JOIN (
            SELECT `id`
            FROM `posts`
            WHERE `board` = :board AND `thread` = :thread
            ORDER BY `id` DESC
            LIMIT :limit
        ) recent_posts ON p.id = recent_posts.id
        WHERE p.board = :board AND p.thread = :thread
        AND recent_posts.id IS NULL
    ');

    $query->bindValue(':board', $boardUri, PDO::PARAM_STR);
    $query->bindValue(':thread', $threadId, PDO::PARAM_INT);
    $query->bindValue(':limit', $cycleLimit, PDO::PARAM_INT);

    $query->execute() or error(db_error($query));
    $ids = $query->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $id) {
        deletePost($id, false);
    }
}

function verify_captcha() {
    global $config;

    vichan_db_session_start();

    if (!isset($_POST['captcha']) || $_POST['captcha'] === '') {
        error($config['error']['captcha']);
    }

    if (!isset($_SESSION['captcha']) ||
        strcasecmp($_SESSION['captcha'], $_POST['captcha']) !== 0
    ) {
        error($config['error']['captcha']);
    }
}

/**
 * Method handling functions
 */
//These 2 lines might get removed now that nntpchan is gone.
$dropped_post = false;
$context = Vichan\build_context($config);

if (isset($_POST['delete'])) {
    // Delete

    if (!isset($_POST['board'], $_POST['password']))
        error($config['error']['bot']);

    if (empty($_POST['password'])){
        error($config['error']['invalidpassword']);
    }

    $password = hashPassword($_POST['password']);

    $delete = array();
    foreach ($_POST as $post => $value) {
        if (preg_match('/^delete_(\d+)$/', $post, $m)) {
            $delete[] = (int)$m[1];
        }
    }

    $delete = ids_from_postdata($_POST);

    checkDNSBL();

    // Check if board exists
    if (!openBoard($_POST['board']))
        error($config['error']['noboard']);

    if ((!isset($_POST['mod']) || !$_POST['mod']) && $config['board_locked']) {
        error("Board is locked");
    }

    // Check if banned
    checkBan($board['uri']);

    // Check if deletion enabled
    if (!$config['allow_delete'])
        error(_('Post deletion is not allowed!'));

    if (empty($delete))
        error($config['error']['nodelete']);

    foreach ($delete as &$id) {
        $query = prepare("SELECT `id`,`thread`,`time`,`password`,`live_date_path` FROM `posts` WHERE `board` = :board AND `id` = :id");
        $query->bindValue(':board', $board['uri'], PDO::PARAM_STR);
        $query->bindValue(':id', $id, PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        if ($post = $query->fetch(PDO::FETCH_ASSOC)) {
            $thread = false;
            if ($config['user_moderation'] && $post['thread']) {
                $thread_query = prepare("SELECT `time`,`password` FROM `posts` WHERE `board` = :board AND `id` = :id");
                $thread_query->bindValue(':board', $board['uri'], PDO::PARAM_STR);
                $thread_query->bindValue(':id', $post['thread'], PDO::PARAM_INT);
                $thread_query->execute() or error(db_error($query));

                $thread = $thread_query->fetch(PDO::FETCH_ASSOC);
            }

            if ($post['time'] < time() - $config['max_delete_time'] && $config['max_delete_time'] != false) {
                error(sprintf($config['error']['delete_too_late'], Format\until($post['time'] + $config['max_delete_time'])));
            }

            if (!hash_equals($post['password'], $password) && (!$thread || !hash_equals($thread['password'], $password))) {
                error($config['error']['invalidpassword']);
            }


            if ($post['time'] > time() - $config['delete_time'] && (!$thread || !hash_equals($thread['password'], $password))) {
                error(sprintf($config['error']['delete_too_soon'], Format\until($post['time'] + $config['delete_time'])));
            }

            $ip = $_SERVER['REMOTE_ADDR'];
            if (isset($_POST['file'])) {
                // Delete just the file
                deleteFile($id);
                modLog("User at $ip deleted file from their own post #$id");
            } else {
                // Delete entire post
                deletePost($id);
                modLog("User at $ip deleted their own post #$id");
            }

            $context->get(LogDriver::class)->log(
                LogDriver::INFO,
                'Deleted post: /' . $board['dir'] . $config['dir']['res'] . $post['live_date_path'] . '/' . link_for($post) . ($post['thread'] ? '#' . $id : '')
            );
        }
    }

    buildIndex();

    $is_mod = isset($_POST['mod']) && $_POST['mod'];
    $root = $is_mod ? $config['root'] . $config['file_mod'] . '?/' : $config['root'];

    if (!isset($_POST['json_response'])) {
        header('Location: ' . $root . $board['dir'] . $config['file_index'], true, $config['redirect_http']);
    } else {
        header('Content-Type: text/json');
        echo json_encode(array('success' => true));
    }

    // We are already done, let's continue our heavy-lifting work in the background (if we run off FastCGI)
    if (function_exists('fastcgi_finish_request'))
        @fastcgi_finish_request();

    Vichan\Functions\Theme\rebuild_themes('post-delete', $board['uri']);

} else if (isset($_POST['edit'])) {
    if (!$config['allow_edit'])
        error('Post editing is not allowed!');

    checkDNSBL();

    // Check if board exists
    if (!openBoard($_POST['board']))
        error($config['error']['noboard']);

    // Check if banned
    checkBan($board['uri']);

    if (!isset($_POST['board'], $_POST['password']))
        error($config['error']['bot']);

    if (empty($_POST['password']))
        error($config['error']['invalidpassword']);

    $password = hashPassword($_POST['password']);

    // Check if user is coming from edit template
    $view_base = isset($_POST['body']);

    if (!$view_base) {
        // Fetch id list from $_POST
        $ids = ids_from_postdata($_POST);
        if (count($ids) == 0) {
            error('You must select one post to edit.');
        } else if (count($ids) > 1) {
            error('You must select only one post to edit.');
        }        
        // First and only id from ids array
        $id = $ids[0];
    } else { 
        $id = (int)$_POST['id'];
    }

    $query = prepare("SELECT * FROM `posts` WHERE `board` = :board AND `id` = :id");
    $query->bindValue(':board', $board['uri'], PDO::PARAM_STR);
    $query->bindValue(':id', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ($post = $query->fetch(PDO::FETCH_ASSOC)) {
        // Use hash_equals for secure password comparison, like in delete
        if (!hash_equals($post['password'], $password)) {
            error($config['error']['invalidpassword']);
        }

        if (!$view_base) {
            // Removes modifiers for showing 
            $post['body_nomarkup'] = remove_modifiers($post['body_nomarkup']);
            $post['body_nomarkup'] = html_entity_decode($post['body_nomarkup'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            echo Element('page.html', array(
                'config' => $config,
                'mod' => false,
                'title' => 'Edit post',
                'subtitle' => '',
                'boardlist' => array(),
                'body' => Element('edit_post_form.html',
                        array_merge(
                            array('config' => $config, 'mod' => false), 
                            array('post' => array_merge($post, array('board' => $board['uri'])))
                        )
                    )
                )
            );
        } else {
            // Remove any modifiers they may have put in
            $_POST['body'] = remove_modifiers($_POST['body']);

            // Add back modifiers from the original post
            $modifiers = extract_modifiers($post['body_nomarkup']);

            // If post was previously edited, it should have a history modifier
            // then, we want to add the actual post to it.
            $history_html = Element('post/history_item.html', array(
                'post' => $post,
                'config' => $config,
                'edited_at' => time()
            ));
            if (isset($modifiers['history'])) {
                $modifiers['history'] = $history_html.$modifiers['history'];
            } else {
                $modifiers['history'] = $history_html;
            }

            foreach ($modifiers as $key => $value) {
                $_POST['body'] .= "<tinyboard $key>$value</tinyboard>";
            }

            $query = prepare('UPDATE `posts` SET `body_nomarkup` = :body_nomarkup WHERE `board` = :board AND `id` = :id');
            $query->bindValue(':board', $board['uri'], PDO::PARAM_STR);
            $query->bindValue(':id', $id, PDO::PARAM_INT);
            $query->bindValue(':body_nomarkup', $_POST['body']);
            $query->execute() or error(db_error($query));    

            rebuildPost($id);

            //buildIndex();
            //rebuildThemes('post', $board['uri']);

            header('Location: ' . $config['root'] . $board['dir'] . $config['file_index'], true, $config['redirect_http']);
        }
    } else {
        // Invalid post
        error($config['error']['404']);
    }

} elseif (isset($_POST['report'])) {
    if (!isset($_POST['board'], $_POST['reason']))
        error($config['error']['bot']);

    $report = array();
    foreach ($_POST as $post => $value) {
        if (preg_match('/^delete_(\d+)$/', $post, $m)) {
            $report[] = (int)$m[1];
        }
    }

    checkDNSBL();

    // Check if board exists
    if (!openBoard($_POST['board']))
        error($config['error']['noboard']);

    if ((!isset($_POST['mod']) || !$_POST['mod']) && $config['board_locked']) {
        error("Board is locked");
    }

    // Check if banned
    checkBan($board['uri']);

    if (empty($report))
        error($config['error']['noreport']);

    if (count($report) > $config['report_limit'])
        error($config['error']['toomanyreports']);


    if ($config['report_captcha']) {
        if ($config['captcha']['provider'] === 'native') {
            verify_captcha();
        } elseif ($config['captcha']['provider'] === 'hcaptcha') {
            if (empty($_POST['h-captcha-response'])) {
                error($config['error']['captcha']);
            }
            $hcaptcha_secret = $config['captcha']['hcaptcha_secret'];
            $hcaptcha_response = $_POST['h-captcha-response'];
            $verify_response = file_get_contents('https://hcaptcha.com/siteverify', false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query([
                        'secret' => $hcaptcha_secret,
                        'response' => $hcaptcha_response,
                        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null
                    ])
                ]
            ]));
            $response_data = json_decode($verify_response, true);
            if (empty($response_data['success'])) {
                error($config['error']['captcha']);
            }
        } elseif ($config['captcha']['provider'] === 'recaptcha') {
            if (empty($_POST['g-recaptcha-response'])) {
                error($config['error']['captcha']);
            }
            $recaptcha_secret = $config['captcha']['recaptcha']['secret'];
            $recaptcha_response = $_POST['g-recaptcha-response'];
            $verify_response = file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query([
                        'secret' => $recaptcha_secret,
                        'response' => $recaptcha_response,
                        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null
                    ])
                ]
            ]));
            $response_data = json_decode($verify_response, true);
            if (empty($response_data['success'])) {
                error($config['error']['captcha']);
            }
        }
    }

    $reason = escape_markup_modifiers($_POST['reason']);
    markup($reason);

    if (mb_strlen($reason) > $config['report_max_length']) {
        error($config['error']['toolongreport']);
    }

    foreach ($report as &$id) {
        $query = prepare("SELECT `id`, `thread`, `live_date_path` FROM `posts` WHERE `board` = :board AND `id` = :id");
        $query->bindValue(':board', $board['uri'], PDO::PARAM_STR);
        $query->bindValue(':id', $id, PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        $post = $query->fetch(PDO::FETCH_ASSOC);
        if ($post === false) {
            $context->get(LogDriver::class)->log(LogDriver::INFO, "Failed to report non-existing post #{$id} in {$board['dir']}");
            error($config['error']['nopost']);
        }

        $error = event('report', array('ip' => $_SERVER['REMOTE_ADDR'], 'board' => $board['uri'], 'post' => $post, 'reason' => $reason, 'link' => link_for($post)));
        if ($error) {
            error($error);
        }

        $context->get(LogDriver::class)->log(
            LogDriver::INFO,
            'Reported post: /'
                 . $board['dir'] . $config['dir']['res'] . $post['live_date_path'] . '/' . link_for($post) . ($post['thread'] ? '#' . $id : '')
                 . " for \"$reason\""
        );
        $query = prepare("INSERT INTO ``reports`` VALUES (NULL, :time, :ip, :board, :post, :reason)");
        $query->bindValue(':time', time(), PDO::PARAM_INT);
        $query->bindValue(':ip', $_SERVER['REMOTE_ADDR'], PDO::PARAM_STR);
        $query->bindValue(':board', $board['uri'], PDO::PARAM_STR);
        $query->bindValue(':post', $id, PDO::PARAM_INT);
        $query->bindValue(':reason', $reason, PDO::PARAM_STR);
        $query->execute() or error(db_error($query));
    }

    $is_mod = isset($_POST['mod']) && $_POST['mod'];
    $root = $is_mod ? $config['root'] . $config['file_mod'] . '?/' : $config['root'];

    if (!isset($_POST['json_response'])) {
        $index = $root . $board['dir'] . $config['file_index'];
        echo Element($config['file_page_template'], array('config' => $config, 'body' => '<div style="text-align:center"><a href="javascript:window.close()">[ ' . _('Close window') ." ]</a> <a href='$index'>[ " . _('Return') . ' ]</a></div>', 'title' => _('Report submitted!')));
    } else {
        header('Content-Type: text/json');
        echo json_encode(array('success' => true));
    }
} elseif (isset($_POST['post']) || $dropped_post) {
    if (!isset($_POST['body'], $_POST['board']) && !$dropped_post)
        error($config['error']['bot']);

    $post = array('board' => $_POST['board'], 'files' => array());

    // Check if board exists
    if (!openBoard($post['board']))
        error($config['error']['noboard']);

    if ((!isset($_POST['mod']) || !$_POST['mod']) && $config['board_locked']) {
        error("Board is locked");
    }

    if (!isset($_POST['name']))
        $_POST['name'] = $config['anonymous'];

    if (!isset($_POST['email']))
        $_POST['email'] = '';

    if (!isset($_POST['subject']))
        $_POST['subject'] = '';

    if (!isset($_POST['password']))
        $_POST['password'] = '';

    if (isset($_POST['thread'])) {
        $post['op'] = false;
        $post['thread'] = round($_POST['thread']);
    } else
        $post['op'] = true;


    if (!$dropped_post) {
        if ($config['simple_spam'] && $post['op']) {
            if (!isset($_POST['simple_spam']) || strtolower($config['simple_spam']['answer']) != strtolower($_POST['simple_spam'])) {
                error($config['error']['simple_spam']);
            }
        }

        // Check if banned
        checkBan($board['uri']);

        // Check for CAPTCHA right after opening the board so the "return" link is in there.
        if ($config['captcha']['provider'] === 'hcaptcha') {
            if (!$dropped_post) {
                if (!isset($_POST['h-captcha-response'])) {
                    error($config['error']['captcha']);
                }

                $hcaptcha_secret = $config['captcha']['hcaptcha_secret']; // Your hCaptcha secret key from config
                $hcaptcha_response = $_POST['h-captcha-response'];

                $verify_response = file_get_contents('https://hcaptcha.com/siteverify', false, stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                        'content' => http_build_query([
                            'secret' => $hcaptcha_secret,
                            'response' => $hcaptcha_response,
                            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null
                        ])
                    ]
                ]));

                if ($verify_response === false) {
                    error($config['error']['remote_io_error']);
                }

                $response_data = json_decode($verify_response, true);

                if (empty($response_data['success'])) {
                    error($config['error']['captcha']);
                }
            }
        }

        if ($config['captcha']['provider'] === 'native' && !$dropped_post) {
            verify_captcha();
        }
        


        if (!(($post['op'] && $_POST['post'] == $config['button_newtopic']) ||
            (!$post['op'] && $_POST['post'] == $config['button_reply'])))
            error($config['error']['bot']);

        // Check the referrer
        if ($config['referer_match'] !== false &&
            (!isset($_SERVER['HTTP_REFERER']) || !preg_match($config['referer_match'], rawurldecode($_SERVER['HTTP_REFERER']))))
            error($config['error']['referer']);

        checkDNSBL();


        if ($post['mod'] = isset($_POST['mod']) && $_POST['mod']) {
            check_login($context, false);
            if (!$mod) {
                // Liar. You're not a mod.
                error($config['error']['notamod']);
            }

            $post['sticky'] = $post['op'] && isset($_POST['sticky']);
            $post['locked'] = $post['op'] && isset($_POST['lock']);
            $post['raw'] = isset($_POST['raw']);

            if ($post['sticky'] && !hasPermission($config['mod']['sticky'], $board['uri']))
                error($config['error']['noaccess']);
            if ($post['locked'] && !hasPermission($config['mod']['lock'], $board['uri']))
                error($config['error']['noaccess']);
            if ($post['raw'] && !hasPermission($config['mod']['rawhtml'], $board['uri']))
                error($config['error']['noaccess']);
        }

        if ($config['robot_enable'] && $config['robot_mute']) {
            checkMute();
        }
    }
    else {
        $mod = $post['mod'] = false;
    }

    //Check if thread exists
    if (!$post['op']) {
        $query = prepare("SELECT `sticky`,`locked`,`cycle`,`sage`,`slug` FROM `posts` WHERE `board` = :board AND `id` = :id AND `thread` IS NULL LIMIT 1");
        $query->bindValue(':board', $board['uri'], PDO::PARAM_STR);
        $query->bindValue(':id', $post['thread'], PDO::PARAM_INT);
        $query->execute() or error(db_error());

        if (!$thread = $query->fetch(PDO::FETCH_ASSOC)) {
            // Non-existant
            error($config['error']['nonexistant']);
        }
    }
    else {
        $thread = false;
    }

	// Check for an embed field
	if ($config['enable_embedding'] && isset($_POST['embed']) && !empty($_POST['embed'])) {
		// yep; validate it
		$value = $_POST['embed'];
		foreach ($config['embedding'] as &$embed) {
			if (preg_match($embed[0], $value)) {
				// Valid link
				$post['embed'] = $value;
				// This is bad, lol.
				$post['no_longer_require_an_image_for_op'] = true;
				break;
			}
		}
		if (!isset($post['embed'])) {
			error($config['error']['invalid_embed']);
		}
	}

	if (!hasPermission($config['mod']['bypass_field_disable'], $board['uri'])) {
		if ($config['field_disable_name'])
			$_POST['name'] = $config['anonymous']; // "forced anonymous"

		if ($config['field_disable_email'])
			$_POST['email'] = '';

		if ($config['field_disable_password'])
			$_POST['password'] = '';

		if ($config['field_disable_subject'] || (!$post['op'] && $config['field_disable_reply_subject']))
			$_POST['subject'] = '';
	}

	try {
		if ($config['allow_upload_by_url'] && !empty($_POST['file_url'])) {
			$isOp = !empty($post['op']);
			$file  = downloadFileByUrl($_POST['file_url'], $config, $isOp);

			$_FILES['file'] = [
				'name'     => $file['name'],
				'tmp_name' => $file['tmp_name'],
				'file_tmp' => true,
				'error'    => 0,
				'size'     => $file['size'],
			];
		}
	} catch (RuntimeException $e) {
		error($e->getMessage());
	}

	$post['name'] = $_POST['name'] != '' ? $_POST['name'] : $config['anonymous'];
	$post['subject'] = $_POST['subject'];
	$post['email'] = str_replace(' ', '%20', htmlspecialchars($_POST['email']));
	$post['body'] = $_POST['body'];
	$post['password'] = hashPassword($_POST['password']);
	$post['has_file'] = (!isset($post['embed']) && (($post['op'] && !isset($post['no_longer_require_an_image_for_op']) && $config['force_image_op']) || count($_FILES) > 0));
	// Set the live date path ONCE for this post
	if ($post['op']) {
        // For OP, use current date
        $live_date_path = date('Y/m/d');
    } else {
        // For replies, fetch the OP's live_date_path
        $query = prepare("SELECT `live_date_path` FROM `posts` WHERE `board` = :board AND `id` = :id AND `thread` IS NULL LIMIT 1");
        $query->bindValue(':board', $board['uri'], PDO::PARAM_STR);
        $query->bindValue(':id', $post['thread'], PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        $live_date_path = $query->fetchColumn();
        if (!$live_date_path) {
            error('Thread not found or OP missing.');
        }
    }
    $post['live_date_path'] = $live_date_path;

	// Ensure image directory for this date exists
	$live_img_dir = $board['dir'] . $config['dir']['img'] . $live_date_path . '/';
	if (!file_exists($live_img_dir)) {
		mkdir($live_img_dir, 0777, true);
	}
	$live_thumb_dir = $board['dir'] . $config['dir']['thumb'] . $live_date_path . '/';
	if (!file_exists($live_thumb_dir)) {
		mkdir($live_thumb_dir, 0777, true);
	}
	$live_res_dir = $board['dir'] . $config['dir']['res'] . $live_date_path . '/';
	if (!file_exists($live_res_dir)) {
		mkdir($live_res_dir, 0777, true);
	}

	if (!$dropped_post) {

		if (!($post['has_file'] || isset($post['embed'])) || (($post['op'] && $config['force_body_op']) || (!$post['op'] && $config['force_body']))) {
			$stripped_whitespace = preg_replace('/[\s]/u', '', $post['body']);
			if ($stripped_whitespace == '') {
				error($config['error']['tooshort_body']);
			}
		}

		if (!$post['op']) {
			// Check if thread is locked
			// but allow mods to post
			if ($thread['locked'] && !hasPermission($config['mod']['postinlocked'], $board['uri']))
				error($config['error']['locked']);

			$numposts = numPosts($post['thread']);

			if ($config['reply_hard_limit'] != 0 && $config['reply_hard_limit'] <= $numposts['replies'])
				error($config['error']['reply_hard_limit']);

			if ($post['has_file'] && $config['image_hard_limit'] != 0 && $config['image_hard_limit'] <= $numposts['images'])
				error($config['error']['image_hard_limit']);
		}
	}
	else {
		if (!$post['op']) {
			$numposts = numPosts($post['thread']);
		}
	}

	if ($post['has_file']) {
		// Determine size sanity
		$size = 0;
		if ($config['multiimage_method'] == 'split') {
			foreach ($_FILES as $key => $file) {
				$size += $file['size'];
			}
		} elseif ($config['multiimage_method'] == 'each') {
			foreach ($_FILES as $key => $file) {
				if ($file['size'] > $size) {
					$size = $file['size'];
				}
			}
		} else {
			error(_('Unrecognized file size determination method.'));
		}

		if ($size > $config['max_filesize'])
			error(sprintf3($config['error']['filesize'], array(
				'sz' => number_format($size),
				'filesz' => number_format($size),
				'maxsz' => number_format($config['max_filesize'])
			)));
		$post['filesize'] = $size;
	}


	$post['capcode'] = false;

	if ($mod && preg_match('/^((.+) )?## (.+)$/', $post['name'], $matches)) {
		$name = $matches[2] != '' ? $matches[2] : $config['anonymous'];
		$cap = $matches[3];

		if (isset($config['mod']['capcode'][$mod['type']])) {
			if (	$config['mod']['capcode'][$mod['type']] === true ||
				(is_array($config['mod']['capcode'][$mod['type']]) &&
					in_array($cap, $config['mod']['capcode'][$mod['type']])
				)) {

				$post['capcode'] = utf8tohtml($cap);
				$post['name'] = $name;
			}
		}
	}

	$trip = generate_tripcode($post['name']);
	$post['name'] = $trip[0];
	if ($config['disable_tripcodes'] && !$mod) {
		$post['trip'] = '';
	}
	else {
		$post['trip'] = isset($trip[1]) ? $trip[1] : ''; // XX: Dropped posts and tripcodes
	}

	$noko = false;
	if (strtolower($post['email']) == 'noko') {
		$noko = true;
		$post['email'] = '';
	} elseif (strtolower($post['email']) == 'nonoko'){
		$noko = false;
		$post['email'] = '';
	} else $noko = $config['always_noko'];
	
	if ($config['enable_voice'] && isset($_POST['voice_data']) && !empty($_POST['voice_data'])) {
		if (!empty($_POST['voice_data'])) {
			$voice_data = $_POST['voice_data'];

			// Only accept data:audio/webm;base64,...
			if (preg_match('/^data:audio\/webm;base64,/', $voice_data)) {
				// Strip prefix and decode
				$voice_data = substr($voice_data, strpos($voice_data, ',') + 1);
				$voice_data = base64_decode($voice_data);

				// Write to a temp file
				$tmpname = tempnam(sys_get_temp_dir(), 'voice_') . '.webm';
				if (file_put_contents($tmpname, $voice_data) === false) {
					error($config['error']['nomove']);
				}

				// Final filename + paths (include live_date_path)
				$filename = time() . rand(1000, 9999) . '.webm';
				$path     = $board['dir'] . $config['dir']['img'] . $live_date_path . '/' . $filename;

				// Thumbnail icon + size (generic file icon)
				$icon      = isset($config['file_icons']['webm'])
							? $config['file_icons']['webm']
							: $config['file_icons']['default'];
				$icon_path = sprintf($config['file_thumb'], $icon);
				$thumbsize = @getimagesize($icon_path);
				$thumb_w   = $thumbsize[0] ?? 128;
				$thumb_h   = $thumbsize[1] ?? 128;

				// Inject into $post['files']
				$post['files'][] = array(
					'name'        => 'voice.webm',
					'tmp_name'    => $tmpname,
					'file_tmp'    => true,
					'filename'    => 'voice.webm',
					'extension'   => 'webm',
					'type'        => 'audio/webm',
					'size'        => strlen($voice_data),
					'file'        => $path,
					'thumb'       => 'file',
					'thumbwidth'  => $thumb_w,
					'thumbheight' => $thumb_h,
					'is_an_image' => false
				);

				$post['has_file'] = true;
			}
		}
	}
	if ($post['has_file']) {
		$i = 0;
		foreach ($_FILES as $key => $file) {
			if (!in_array($file['error'], array(UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK))) {
				error(sprintf3($config['error']['phpfileserror'], array(
					'index' => $i+1,
					'code' => $file['error']
				)));
			}

			if ($file['size'] && $file['tmp_name']) {
				$file['filename'] = urldecode($file['name']);
				$file['extension'] = strtolower(mb_substr($file['filename'], mb_strrpos($file['filename'], '.') + 1));
				if (isset($config['filename_func']))
					$file['file_id'] = $config['filename_func']($file);
				else
					$file['file_id'] = time() . substr(microtime(), 2, 3);

				if (sizeof($_FILES) > 1)
					$file['file_id'] .= "-$i";
				$file['file'] = $board['dir'] . $config['dir']['img'] . $live_date_path . '/' . $file['file_id'] . '.' . $file['extension'];
				$file['thumb'] = $board['dir'] . $config['dir']['thumb'] . $live_date_path . '/' . $file['file_id'] . '.' . ($config['thumb_ext'] ? $config['thumb_ext'] : $file['extension']);
				$post['files'][] = $file;
				$i++;
			}
		}
	}

	if (empty($post['files'])) $post['has_file'] = false;

	if (!$dropped_post) {
		// Check for a file
		if ($post['op'] && !isset($post['no_longer_require_an_image_for_op'])) {
			if (!$post['has_file'] && $config['force_image_op'])
				error($config['error']['noimage']);
		}

		// Check for too many files
		if (sizeof($post['files']) > $config['max_images'])
			error($config['error']['toomanyimages']);
	}

	if ($config['strip_combining_chars']) {
		$post['name'] = strip_combining_chars($post['name']);
		$post['email'] = strip_combining_chars($post['email']);
		$post['subject'] = strip_combining_chars($post['subject']);
		$post['body'] = strip_combining_chars($post['body']);
	}

	if (!$dropped_post) {
		// Check string lengths
		if (mb_strlen($post['name']) > 35)
			error(sprintf($config['error']['toolong'], 'name'));
		if (mb_strlen($post['email']) > 40)
			error(sprintf($config['error']['toolong'], 'email'));
		if (mb_strlen($post['subject']) > 100)
			error(sprintf($config['error']['toolong'], 'subject'));
		if (!$mod && mb_strlen($post['body']) > $config['max_body'])
			error($config['error']['toolong_body']);
		if (!$mod && substr_count($post['body'], "\n") >= $config['maximum_lines'])
			error($config['error']['toomanylines']);
	}
	wordfilters($post['body']);

	$post['body'] = escape_markup_modifiers($post['body']);

	if ($mod && isset($post['raw']) && $post['raw']) {
		$post['body'] .= "\n<tinyboard raw html>1</tinyboard>";
	}

	if (!$dropped_post)
	if (
        ($config['country_flags'] && !$config['allow_no_country']) ||
        ($config['country_flags'] && $config['allow_no_country'] && !isset($_POST['no_country']))
    ) {
        $geoip_db_path = __DIR__ . '/inc/lib/geoip/GeoLite2-Country.mmdb';
        $reader = new Reader($geoip_db_path);

        $ip = $_SERVER['REMOTE_ADDR'];

        try {
            $record = $reader->country($ip);
            $country_code = strtolower($record->country->isoCode);
            $country_name = $record->country->name;

            if (!in_array($country_code, ['eu', 'ap', 'o1', 'a1', 'a2'])) {
                $post['body'] .= "\n<tinyboard flag>{$country_code}</tinyboard>" .
                                 "\n<tinyboard flag alt>{$country_name}</tinyboard>";
            }
        } catch (\GeoIp2\Exception\AddressNotFoundException $e) {
            // IP not found in database, do nothing or handle as needed
        }
    }

	if ($config['user_flag'] && isset($_POST['user_flag']) && !empty($_POST['user_flag'])) {
		$user_flag = $_POST['user_flag'];

		if (!isset($config['user_flags'][$user_flag])) {
			error(_('Invalid flag selection!'));
		}

		$flag_alt = isset($user_flag_alt) ? $user_flag_alt : $config['user_flags'][$user_flag];

		$post['body'] .= "\n<tinyboard flag>" . strtolower($user_flag) . "</tinyboard>" .
			"\n<tinyboard flag alt>" . $flag_alt . "</tinyboard>";
	}

	if ($config['allowed_tags'] && $post['op'] && isset($_POST['tag']) && isset($config['allowed_tags'][$_POST['tag']])) {
		$post['body'] .= "\n<tinyboard tag>" . $_POST['tag'] . "</tinyboard>";
	}

	if (!$dropped_post)
		if ($config['proxy_save'] && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$proxy = preg_replace("/[^0-9a-fA-F.,: ]/", '', $_SERVER['HTTP_X_FORWARDED_FOR']);
		$post['body'] .= "\n<tinyboard proxy>".$proxy."</tinyboard>";
	}

	$post['body_nomarkup'] = strip_symbols($post['body']);

	$post['tracked_cites'] = markup($post['body'], true);


	if ($post['has_file']) {
		$allhashes = '';

		foreach ($post['files'] as $key => &$file) {
			if ($post['op'] && $config['allowed_ext_op']) {
				if (!in_array($file['extension'], $config['allowed_ext_op']))
					error($config['error']['unknownext']);
			}
			elseif (!in_array($file['extension'], $config['allowed_ext']) && !in_array($file['extension'], $config['allowed_ext_files']))
				error($config['error']['unknownext']);

			$file['is_an_image'] = !in_array($file['extension'], $config['allowed_ext_files']);

			// Truncate filename if it is too long
			$file['filename'] = mb_substr($file['filename'], 0, $config['max_filename_len']);

			$upload = $file['tmp_name'];

			if (!is_readable($upload))
				error($config['error']['nomove']);

			$hash = md5_hash_of_file($config, $upload);

			$file['hash'] = $hash;
			$allhashes .= $hash;
		}

		if (count ($post['files']) == 1) {
			$post['filehash'] = $hash;
		}
		else {
			$post['filehash'] = md5($allhashes);
		}
	}

	if (!hasPermission($config['mod']['bypass_filters'], $board['uri']) && !$dropped_post) {
		require_once 'inc/filters.php';

		do_filters($post);
	}

	if ($post['has_file']) {
        foreach ($post['files'] as $key => &$file) {
            if ($file['is_an_image']) {
                if ($config['ie_mime_type_detection'] !== false) {
                    // Check IE MIME type detection XSS exploit
                    $buffer = file_get_contents($file['tmp_name'], false, null, 0, 255);
                    if (preg_match($config['ie_mime_type_detection'], $buffer)) {
                        error($config['error']['mime_exploit']);
                    }
                }

                require_once 'inc/image.php';

                // Find dimensions of an image using GD
                if (!$size = @getimagesize($file['tmp_name'])) {
                    error($config['error']['invalidimg']);
                }
                if (!in_array($size[2], array(IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_BMP, IMAGETYPE_WEBP))) {
                    error($config['error']['invalidimg']);
                }
                if ($size[0] > $config['max_width'] || $size[1] > $config['max_height']) {
                    error($config['error']['maxsize']);
                }

                $file['exif_stripped'] = false;

                if ($file_image_has_operable_metadata && $config['convert_auto_orient']) {
                    // Auto-orient image if needed
                    if (!($config['redraw_image'] || (($config['strip_exif'] && !$config['use_exiftool'])))) {
                        if (in_array($config['thumb_method'], array('convert', 'convert+gifsicle', 'gm', 'gm+gifsicle'))) {
                            $exif = @exif_read_data($file['tmp_name']);
                            $gm = in_array($config['thumb_method'], array('gm', 'gm+gifsicle'));
                            if (isset($exif['Orientation']) && $exif['Orientation'] != 1) {
                                $error = shell_exec_error(($gm ? 'gm ' : '') . 'convert ' .
                                        escapeshellarg($file['tmp_name']) . ' -auto-orient ' . escapeshellarg($file['tmp_name']));
                                if ($error)
                                    error(_('Could not auto-orient image!'), null, $error);
                                $size = @getimagesize($file['tmp_name']);
                                if ($config['strip_exif'])
                                    $file['exif_stripped'] = true;
                            }
                        }
                    }
                }

                // Create image object
                $image = new Image($file['tmp_name'], $file['extension'], $size);
                if ($image->size->width > $config['max_width'] || $image->size->height > $config['max_height']) {
                    $image->delete();
                    error($config['error']['maxsize']);
                }

                $file['width'] = $image->size->width;
                $file['height'] = $image->size->height;

                if ($config['spoiler_images'] && isset($_POST['spoiler'])) {
                    $file['thumb'] = 'spoiler';
                    $size = @getimagesize($config['spoiler_image']);
                    $file['thumbwidth'] = $size[0];
                    $file['thumbheight'] = $size[1];
                } elseif ($config['minimum_copy_resize'] &&
                    $image->size->width <= $config['thumb_width'] &&
                    $image->size->height <= $config['thumb_height'] &&
                    $file['extension'] == ($config['thumb_ext'] ? $config['thumb_ext'] : $file['extension'])) {
                    // Copy, because there's nothing to resize
                    copy($file['tmp_name'], $file['thumb']);
                    $file['thumbwidth'] = $image->size->width;
                    $file['thumbheight'] = $image->size->height;
                } else {
                    $thumb = $image->resize(
                        $config['thumb_ext'] ? $config['thumb_ext'] : $file['extension'],
                        $post['op'] ? $config['thumb_op_width'] : $config['thumb_width'],
                        $post['op'] ? $config['thumb_op_height'] : $config['thumb_height']
                    );
                    $thumb->to($file['thumb']);
                    $file['thumbwidth'] = $thumb->width;
                    $file['thumbheight'] = $thumb->height;
                    $thumb->_destroy();
                }

                // Add this after the thumbnail logic, inside the foreach ($_FILES...) loop:
                $file['adult'] = ($config['enable_adult_posts'] && isset($_POST['adult'])) ? 1 : 0;

                $dont_copy_file = false;

                if ($config['redraw_image'] || ($file_image_has_operable_metadata && !$file['exif_stripped'] && $config['strip_exif'])) {
                    if (!$config['redraw_image'] && $config['use_exiftool']) {
                        try {
                            $file['size'] = strip_image_metadata($file['tmp_name']);
                        } catch (RuntimeException $e) {
                            $context->get(LogDriver::class)->log(LogDriver::ERROR, "Could not strip image metadata: {$e->getMessage()}");
                            error(_('Could not strip EXIF metadata!'), null, $e->getMessage());
                        }
                    } else {
                        $image->to($file['file']);
                        $dont_copy_file = true;
                    }
                }
                $image->destroy();
            } else {
                // Not an image
                $file['thumb'] = 'file';
                $size = @getimagesize(sprintf($config['file_thumb'],
                    isset($config['file_icons'][$file['extension']]) ?
                        $config['file_icons'][$file['extension']] : $config['file_icons']['default']));
                $file['thumbwidth'] = $size[0];
                $file['thumbheight'] = $size[1];
                $dont_copy_file = false;
            }

            if ($config['tesseract_ocr'] && $file['thumb'] != 'file') {
                $fname = $file['tmp_name'];
                if ($file['height'] > 500 || $file['width'] > 500) {
                    $fname = $file['thumb'];
                }
                if ($fname !== 'spoiler') {
                    try {
                        $txt = ocr_image($config, $fname);
                        if ($txt !== '') {
                            $post['body_nomarkup'] .= "<tinyboard ocr image $key>" . htmlspecialchars($txt) . "</tinyboard>";
                        }
                    } catch (RuntimeException $e) {
                        $context->get(LogDriver::class)->log(LogDriver::ERROR, "Could not OCR image: {$e->getMessage()}");
                    }
                }
            }

            // Check for duplicates before uploading the file
            if ($config['image_reject_repost']) {
                if ($p = getPostByHash($post['filehash'])) {
                    if ($file['is_an_image'] && $file['thumb'] != 'spoiler' && file_exists($file['thumb'])) {
                        @unlink($file['thumb']); // Clean up thumbnail if it was created
                    }
                    error(sprintf($config['error']['fileexists'],
                        ($post['mod'] ? $config['root'] . $config['file_mod'] . '?/' : $config['root']) .
                        $board['dir'] . $config['dir']['res'] . $p['live_date_path'] . '/' .
                        ($p['thread'] ?
                            $p['thread'] . '.html#' . $p['id']
                        :
                            $p['id'] . '.html'
                        )
                    ));
                }
            } else if (!$post['op'] && $config['image_reject_repost_in_thread']) {
                if ($p = getPostByHashInThread($post['filehash'], $post['thread'])) {
                    if ($file['is_an_image'] && $file['thumb'] != 'spoiler' && file_exists($file['thumb'])) {
                        @unlink($file['thumb']); // Clean up thumbnail if it was created
                    }
                    error(sprintf($config['error']['fileexistsinthread'],
                        ($post['mod'] ? $config['root'] . $config['file_mod'] . '?/' : $config['root']) .
                        $board['dir'] . $config['dir']['res'] . $p['live_date_path'] . '/' .
                        ($p['thread'] ?
                            $p['thread'] . '.html#' . $p['id']
                        :
                            $p['id'] . '.html'
                        )
                    ));
                }
            }

            // If no duplicates, proceed with file upload
            if (!$dont_copy_file) {
                if (isset($file['file_tmp'])) {
                    if (!@rename($file['tmp_name'], $file['file'])) {
                        if ($file['is_an_image'] && $file['thumb'] != 'spoiler' && file_exists($file['thumb'])) {
                            @unlink($file['thumb']); // Clean up thumbnail on failure
                        }
                        error($config['error']['nomove']);
                    }
                    chmod($file['file'], 0644);
                } elseif (!@move_uploaded_file($file['tmp_name'], $file['file'])) {
                    if ($file['is_an_image'] && $file['thumb'] != 'spoiler' && file_exists($file['thumb'])) {
                        @unlink($file['thumb']); // Clean up thumbnail on failure
                    }
                    error($config['error']['nomove']);
                }
            }
        }
    }

	// Do filters again if OCRing
	if ($config['tesseract_ocr'] && !hasPermission($config['mod']['bypass_filters'], $board['uri']) && !$dropped_post) {
		do_filters($post);
	}

	if (!hasPermission($config['mod']['postunoriginal'], $board['uri']) && $config['robot_enable'] && checkRobot($post['body_nomarkup']) && !$dropped_post) {
		undoImage($post);
		if ($config['robot_mute']) {
			error(sprintf($config['error']['muted'], mute()));
		} else {
			error($config['error']['unoriginal']);
		}
	}

	// Remove board directories before inserting them into the database.
	if ($post['has_file']) {
		foreach ($post['files'] as $key => &$file) {
			$file['file_path'] = $file['file'];
			$file['thumb_path'] = $file['thumb'];
			$file['file'] = mb_substr($file['file'], mb_strlen($board['dir'] . $config['dir']['img']));
			if ($file['is_an_image'] && $file['thumb'] != 'spoiler')
				$file['thumb'] = mb_substr($file['thumb'], mb_strlen($board['dir'] . $config['dir']['thumb']));
		}
	}
	

	$post = (object)$post;
	$post->files = array_map(function($a) { return (object)$a; }, $post->files);

	$error = event('post', $post);
	$post->files = array_map(function($a) { return (array)$a; }, $post->files);

	if ($error) {
		undoImage((array)$post);
		error($error);
	}
	$post = (array)$post;

	$post['num_files'] = sizeof($post['files']);

	$query = prepare("INSERT INTO `board_counters` (`board`, `last_board_id`) VALUES (:board, 1)
		ON DUPLICATE KEY UPDATE `last_board_id` = `last_board_id` + 1");
	$query->bindValue(':board', $board['uri']);
	$query->execute() or error(db_error($query));

	// Now fetch the current last_board_id
	$query = prepare("SELECT last_board_id FROM board_counters WHERE board = :board");
	$query->bindValue(':board', $board['uri']);
	$query->execute() or error(db_error($query));
	$next_board_id = (int)$query->fetchColumn();

	$post['board_id'] = $next_board_id;

	// Now insert the post
	$post['id'] = $id = post($post);
	$post['slug'] = slugify($post);

	// Now fetch live_date_path for the new post
	$query = prepare("SELECT `live_date_path` FROM `posts` WHERE `board` = :board AND `id` = :id");
	$query->bindValue(':board', $board['uri']);
	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	$post['live_date_path'] = $query->fetchColumn();

	insertFloodPost($post);

	// ─── Handle poll creation ────────────────────────────────────────────
	if ($config['enable_poll'] && $post['op'] && !empty($_POST['poll_question']) &&
		is_array($_POST['poll_options']) &&
		count($_POST['poll_options']) >= 2) {
		
		$question   = trim($_POST['poll_question']);
		$options    = array_filter($_POST['poll_options'], function($v){ return trim($v) !== ''; });
		$max_votes  = intval($_POST['poll_max_votes']);
		$expires    = !empty($_POST['poll_expires']) ? strtotime($_POST['poll_expires']) : null;

		create_poll($post['id'], $question, $options, $max_votes, $expires);
	}
	// ─────────────────────────────────────────────────────────────────────


	// Handle cyclical threads
	if (!$post['op'] && isset($thread['cycle']) && $thread['cycle']) {
		delete_cyclical_posts($board['uri'], $post['thread'], $config['cycle_limit']);
	}

	if (isset($post['antispam_hash'])) {
		incrementSpamHash($post['antispam_hash']);
	}

	if (isset($post['tracked_cites']) && !empty($post['tracked_cites'])) {
		$insert_rows = array();
		foreach ($post['tracked_cites'] as $cite) {
			$insert_rows[] = '(' .
				$pdo->quote($board['uri']) . ', ' . (int)$id . ', ' .
				$pdo->quote($cite[0]) . ', ' . (int)$cite[1] . ')';
		}
		query('INSERT INTO ``cites`` VALUES ' . implode(', ', $insert_rows)) or error(db_error());
	}

	if (!$post['op'] && strtolower($post['email']) != 'sage' && !$thread['sage'] && ($config['reply_limit'] == 0 || $numposts['replies']+1 < $config['reply_limit'])) {
		bumpThread($post['thread']);
	}

	if (isset($_SERVER['HTTP_REFERER'])) {
		// Tell Javascript that we posted successfully
		if (isset($_COOKIE[$config['cookies']['js']])) {
			$js = json_decode($_COOKIE[$config['cookies']['js']]);
		} else {
			$js = (object)array();
		}
		// Tell it to delete the cached post for referer
		$js->{$_SERVER['HTTP_REFERER']} = true;

		// Encode and set cookie.
		$options = [
			'expires' => 0,
			'path' => $config['cookies']['jail'] ? $config['cookies']['path'] : '/',
			'httponly' => false,
			'samesite' => 'Strict'
		];
		setcookie($config['cookies']['js'], json_encode($js), $options);
	}

	$root = $post['mod'] ? $config['root'] . $config['file_mod'] . '?/' : $config['root'];

	if ($post['op']) {
		// Redirect to the newly created thread
		$redirect = $root . $board['dir'] . $config['dir']['res'] . $post['live_date_path'] . '/' . link_for($post, false, false, $thread);
    } else if ($noko) {
        $redirect = $root . $board['dir'] . $config['dir']['res'] . $post['live_date_path'] . '/' .
            link_for($post, false, false, $thread) . (!$post['op'] ? '#' . $id : '');
	
		if (!$post['op'] && isset($_SERVER['HTTP_REFERER'])) {
			$regex = array(
				'board' => str_replace('%s', '(\w{1,8})', preg_quote($config['board_path'], '/')),
				'page' => str_replace('%d', '(\d+)', preg_quote($config['file_page'], '/')),
				'page50' => '(' . str_replace('%d', '(\d+)', preg_quote($config['file_page50'], '/')) . '|' .
						  str_replace(array('%d', '%s'), array('(\d+)', '[a-z0-9-]+'), preg_quote($config['file_page50_slug'], '/')) . ')',
				'res' => preg_quote($config['dir']['res'], '/'),
			);
	
			if (preg_match('/\/' . $regex['board'] . $regex['res'] . $regex['page50'] . '([?&].*)?$/', $_SERVER['HTTP_REFERER'])) {
				$redirect = $root . $board['dir'] . $config['dir']['res'] . $post['live_date_path'] . '/' .
					link_for($post, true, false, $thread) . (!$post['op'] ? '#' . $id : '');
			}
		}
	} else {
		$redirect = $root . $board['dir'] . $config['file_index'];
	}

	buildThread($post['op'] ? $id : $post['thread']);

	$context->get(LogDriver::class)->log(
		LogDriver::INFO,
		'New post: /' . $board['dir'] . $config['dir']['res'] . $post['live_date_path'] . '/' . link_for($post) . (!$post['op'] ? '#' . $id : '')
    );

	if (!$post['mod']) header('X-Associated-Content: "' . $redirect . '"');


	if (!isset($_POST['json_response'])) {
		header('Location: ' . $redirect, true, $config['redirect_http']);
	} else {
		header('Content-Type: text/json; charset=utf-8');
		echo json_encode(array(
			'redirect' => $redirect,
			'noko' => $noko,
			'id' => $id
		));
	}

	//if ($config['try_smarter'] && $post['op'])
	//$build_pages = range(1, $config['max_pages']);

	if ($config['try_smarter']){
		// ✅ BEGIN SMART BUILD LOGIC
		global $build_pages;
		$build_pages = [1];

		if ($post['op']) {
			$build_pages[] = 2;
		} else {
			$build_pages[] = getThreadPage($post['thread']);
		}

		$build_pages = array_unique($build_pages);
		// ✅ END SMART BUILD LOGIC
	}

	if ($post['op'])
		clean($id);

	event('post-after', $post);

	buildIndex();

	// We are already done, let's continue our heavy-lifting work in the background (if we run off FastCGI)
	if (function_exists('fastcgi_finish_request'))
		@fastcgi_finish_request();

	if ($post['op'])
		Vichan\Functions\Theme\rebuild_themes('post-thread', $board['uri']);
	else
		Vichan\Functions\Theme\rebuild_themes('post', $board['uri']);

} elseif (isset($_POST['appeal'])) {
	if (!isset($_POST['ban_id']))
		error($config['error']['bot']);

	$ban_id = (int)$_POST['ban_id'];

	$ban = Bans::findSingle($_SERVER['REMOTE_ADDR'], $ban_id, $config['require_ban_view'], $config['auto_maintenance']);

	if (empty($ban)) {
		error($config['error']['noban']);
	}

	if ($ban['expires'] && $ban['expires'] - $ban['created'] <= $config['ban_appeals_min_length']) {
		error($config['error']['tooshortban']);
	}

	$query = query("SELECT `denied` FROM ``ban_appeals`` WHERE `ban_id` = $ban_id") or error(db_error());
	$ban_appeals = $query->fetchAll(PDO::FETCH_COLUMN);

	if (count($ban_appeals) >= $config['ban_appeals_max']) {
		error($config['error']['toomanyappeals']);
	}

	foreach ($ban_appeals as $is_denied) {
		if (!$is_denied) {
			error($config['error']['pendingappeal']);
		}
	}

	if (strlen($_POST['appeal']) > $config['ban_appeal_max_chars']) {
		error($config['error']['toolongappeal']);
	}

	$query = prepare("INSERT INTO ``ban_appeals`` VALUES (NULL, :ban_id, :time, :message, 0)");
	$query->bindValue(':ban_id', $ban_id, PDO::PARAM_INT);
	$query->bindValue(':time', time(), PDO::PARAM_INT);
	$query->bindValue(':message', $_POST['appeal']);
	$query->execute() or error(db_error($query));

	displayBan($ban);

} elseif (isset($_POST['archive_vote'])) {
	if (!isset($_POST['board'], $_POST['thread_id']))
		error($config['error']['bot']);
	
	// Check if board exists
	if (!openBoard($_POST['board']))
		error($config['error']['noboard']);

    // Fetch channel for the board
    $query = prepare('SELECT `channel` FROM `boards` WHERE `uri` = :uri');
    $query->bindValue(':uri', $_POST['board']);
    $query->execute() or error(db_error($query));
    $channel = $query->fetchColumn();

    if ($channel === false) {
        error($config['error']['noboard']);
    }

	// Add Vote
    Archive::addVote($_POST['board'], $_POST['thread_id']);

    // Determine page and pagination group
    $page = isset($_POST['current_page']) && is_numeric($_POST['current_page']) ? (int)$_POST['current_page'] : 1;
    $pagination_group = isset($_POST['pagination_group']) && is_numeric($_POST['pagination_group']) ? (int)$_POST['pagination_group'] : 1;

    // Build archive URL for the correct page
    if ($page > 1) {
        $archive_url = $config['root'] . sprintf($config['board_path'], $channel, $_POST['board']) . $config['dir']['archive'] . 'pagination/' . $pagination_group . '/' . $page . '.html';
    } else {
        $archive_url = $config['root'] . sprintf($config['board_path'], $channel, $_POST['board']) . $config['dir']['archive'];
    }

    header('Location: ' . $archive_url, true, $config['redirect_http']);

} else {
	if (!file_exists($config['has_installed'])) {
		header('Location: install.php', true, $config['redirect_http']);
	} else {
		// They opened post.php in their browser manually.
		error($config['error']['nopost']);
	}
}
