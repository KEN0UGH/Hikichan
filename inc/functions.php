<?php

/*
 *  Copyright (c) 2010-2014 Tinyboard Development Group
 */


if (realpath($_SERVER['SCRIPT_FILENAME']) == str_replace('\\', '/', __FILE__)) {
	// You cannot request this file directly.
	exit;
}

$microtime_start = microtime(true);

// the user is not currently logged in as a moderator
$mod = false;

register_shutdown_function('fatal_error_handler');
mb_internal_encoding('UTF-8');
loadConfig();

function init_locale($locale, $error='error') {
	if (extension_loaded('gettext')) {
		setlocale(LC_ALL, $locale);
		bindtextdomain('tinyboard', './inc/locale');
		bind_textdomain_codeset('tinyboard', 'UTF-8');
		textdomain('tinyboard');
	} else {
		if (_setlocale(LC_ALL, $locale) === false) {
			$error('The specified locale (' . $locale . ') does not exist on your platform!');
		}
		_bindtextdomain('tinyboard', './inc/locale');
		_bind_textdomain_codeset('tinyboard', 'UTF-8');
		_textdomain('tinyboard');
	}
}
$current_locale = 'en';


function loadConfig() {
	global $board, $config, $__ip, $debug, $__version, $microtime_start, $current_locale, $events;

	$error = function_exists('error') ? 'error' : 'basic_error_function_because_the_other_isnt_loaded_yet';

	$boardsuffix = isset($board['uri']) ? $board['uri'] : '';

	if (!isset($_SERVER['REMOTE_ADDR']))
		$_SERVER['REMOTE_ADDR'] = '0.0.0.0';

	if (file_exists('tmp/cache/cache_config.php')) {
		require_once('tmp/cache/cache_config.php');
	}


	if (isset($config['cache_config']) &&
		$config['cache_config'] &&
		$config = Cache::get('config_' . $boardsuffix))
	{
		$events = Cache::get('events_' . $boardsuffix );

		define_groups();

		if (file_exists('inc/instance-functions.php')) {
			require_once('inc/instance-functions.php');
		}

		if ($config['locale'] != $current_locale) {
					$current_locale = $config['locale'];
					init_locale($config['locale'], $error);
			}
	} else {
		$config = array();

		reset_events();

		$arrays = array(
			'db',
			'api',
			'cache',
			'lock',
			'queue',
			'cookies',
			'error',
			'dir',
			'mod',
			'spam',
			'filters',
			'wordfilters',
			'custom_capcode',
			'custom_tripcode',
			'dnsbl',
			'dnsbl_exceptions',
			'remote',
			'allowed_ext',
			'allowed_ext_files',
			'file_icons',
			'footer',
			'stylesheets',
			'additional_javascript',
			'markup',
			'custom_pages',
			'dashboard_links'
		);

		foreach ($arrays as $key) {
			$config[$key] = array();
		}

		if (!file_exists('inc/instance-config.php'))
			$error('vichan is not configured! Create inc/instance-config.php.');

		// Initialize locale as early as possible

		// Those calls are expensive. Unfortunately, our cache system is not initialized at this point.
		// So, we may store the locale in a tmp/ filesystem.

		if (file_exists($fn = 'tmp/cache/locale_' . $boardsuffix ) ) {
			$config['locale'] = @file_get_contents($fn);
		}
		else {
			$config['locale'] = 'en';

			$configstr = file_get_contents('inc/secrets.php');

			if (isset($board['dir']) && file_exists($board['dir'] . '/config.php')) {
				$configstr .= file_get_contents($board['dir'] . '/config.php');
			}
			$matches = array();
			preg_match_all('/[^\/#*]\$config\s*\[\s*[\'"]locale[\'"]\s*\]\s*=\s*([\'"])(.*?)\1/', $configstr, $matches);
			if ($matches && isset ($matches[2]) && $matches[2]) {
				$matches = $matches[2];
				$config['locale'] = $matches[count($matches)-1];
			}

			@file_put_contents($fn, $config['locale']);
		}

		if ($config['locale'] != $current_locale) {
			$current_locale = $config['locale'];
			init_locale($config['locale'], $error);
		}

		require 'inc/config.php';

		require 'inc/instance-config.php';

		if (isset($board['dir']) && file_exists($board['dir'] . '/config.php')) {
			require $board['dir'] . '/config.php';
		}

		if ($config['locale'] != $current_locale) {
			$current_locale = $config['locale'];
			init_locale($config['locale'], $error);
		}

		if (!isset($config['global_message']))
			$config['global_message'] = false;

		if (!isset($config['post_url']))
			$config['post_url'] = $config['root'] . $config['file_post'];


		if (!isset($config['referer_match']))
			if (isset($_SERVER['HTTP_HOST'])) {
				$config['referer_match'] = '/^' .
					(preg_match('@^https?://@', $config['root']) ? '' :
						'https?:\/\/' . $_SERVER['HTTP_HOST']) .
						preg_quote($config['root'], '/') .
					'(' .
							str_replace('%s', $config['board_regex'], preg_quote($config['board_path'], '/')) .
							'(' .
								preg_quote($config['file_index'], '/') . '|' .
								str_replace('%d', '\d+', preg_quote($config['file_page'])) .
							')?' .
						'|' .
							str_replace('%s', $config['board_regex'], preg_quote($config['board_path'], '/')) .
							preg_quote($config['dir']['res'], '/') .
							'(' .
								str_replace('%d', '\d+', preg_quote($config['file_page'], '/')) . '|' .
								str_replace('%d', '\d+', preg_quote($config['file_page50'], '/')) . '|' .
								str_replace(array('%d', '%s'), array('\d+', '[a-z0-9-]+'), preg_quote($config['file_page_slug'], '/')) . '|' .
								str_replace(array('%d', '%s'), array('\d+', '[a-z0-9-]+'), preg_quote($config['file_page50_slug'], '/')) .
							')' .
						'|' .
							preg_quote($config['file_mod'], '/') . '\?\/.+' .
					')([#?](.+)?)?$/ui';
			} else {
				// CLI mode
				$config['referer_match'] = '//';
			}
		if (!isset($config['cookies']['path']))
			$config['cookies']['path'] = &$config['root'];

		if (!isset($config['dir']['static']))
			$config['dir']['static'] = $config['root'] . 'static/';

		if (!isset($config['image_blank']))
			$config['image_blank'] = $config['dir']['static'] . 'blank.gif';

		if (!isset($config['image_sticky']))
			$config['image_sticky'] = $config['dir']['static'] . 'sticky.gif';
		if (!isset($config['image_locked']))
			$config['image_locked'] = $config['dir']['static'] . 'locked.gif';
		if (!isset($config['image_bumplocked']))
			$config['image_bumplocked'] = $config['dir']['static'] . 'sage.gif';
		if (!isset($config['image_deleted']))
			$config['image_deleted'] = $config['dir']['static'] . 'deleted.png';
		if (!isset($config['image_cyclical']))
			$config['image_cyclical'] = $config['dir']['static'] . 'cycle.png';

		if (isset($board)) {
			if (!isset($config['uri_thumb']))
				$config['uri_thumb'] = $config['root'] . $board['dir'] . $config['dir']['thumb'];
			elseif (isset($board['dir']))
				$config['uri_thumb'] = sprintf($config['uri_thumb'], $board['dir']);

			if (!isset($config['uri_img']))
				$config['uri_img'] = $config['root'] . $board['dir'] . $config['dir']['img'];
			elseif (isset($board['dir']))
				$config['uri_img'] = sprintf($config['uri_img'], $board['dir']);
		}

		if (!isset($config['uri_stylesheets']))
			$config['uri_stylesheets'] = $config['root'] . 'stylesheets/';

		if (!isset($config['url_stylesheet']))
			$config['url_stylesheet'] = $config['uri_stylesheets'] . 'style.css';
		if (!isset($config['url_javascript']))
			$config['url_javascript'] = $config['root'] . $config['file_script'];
		if (!isset($config['additional_javascript_url']))
			$config['additional_javascript_url'] = $config['root'];
		if (!isset($config['uri_flags']))
			$config['uri_flags'] = $config['root'] . 'static/flags/%s.png';
		if (!isset($config['user_flag']))
			$config['user_flag'] = false;
		if (!isset($config['user_flags']))
			$config['user_flags'] = array();

		if (!isset($__version))
			$__version = file_exists('.installed') ? trim(file_get_contents('.installed')) : false;
		$config['version'] = $__version;

		if ($config['allow_roll']) {
			event_handler('post', 'email_dice_roll');
		}

		if (in_array('webm', $config['allowed_ext_files']) || in_array('mp4',  $config['allowed_ext_files'])) {
			event_handler('post', 'postHandler');
		}
	}
	// Effectful config processing below:

	date_default_timezone_set($config['timezone']);

	if ($config['root_file']) {
		chdir($config['root_file']);
	}

	// Keep the original address to properly comply with other board configurations
	if (!isset($__ip))
		$__ip = $_SERVER['REMOTE_ADDR'];

	// ::ffff:0.0.0.0
	if (preg_match('/^\:\:(ffff\:)?(\d+\.\d+\.\d+\.\d+)$/', $__ip, $m))
		$_SERVER['REMOTE_ADDR'] = $m[2];

	if ($config['verbose_errors']) {
		set_error_handler('verbose_error_handler');
		error_reporting($config['deprecation_errors'] ? E_ALL : E_ALL & ~E_DEPRECATED);
		ini_set('display_errors', true);
		ini_set('html_errors', false);
	} else {
		ini_set('display_errors', false);
	}

	if ($config['syslog'])
		openlog('tinyboard', LOG_ODELAY, LOG_SYSLOG); // open a connection to sysem logger

	if ($config['cache']['enabled'])
		require_once 'inc/cache.php';

	if (in_array('webm', $config['allowed_ext_files']) || in_array('mp4',  $config['allowed_ext_files']))
		require_once 'inc/lib/webm/posthandler.php';

	event('load-config');

	if ($config['cache_config'] && !isset ($config['cache_config_loaded'])) {
		file_put_contents('tmp/cache/cache_config.php', '<?php '.
			'$config = array();'.
			'$config[\'cache\'] = '.var_export($config['cache'], true).';'.
			'$config[\'cache_config\'] = true;'.
			'$config[\'debug\'] = '.var_export($config['debug'], true).';'.
			'require_once(\'inc/cache.php\');'
		);

		$config['cache_config_loaded'] = true;

		Cache::set('config_'.$boardsuffix, $config);
		Cache::set('events_'.$boardsuffix, $events);
	}

	if (is_array($config['anonymous']))
		$config['anonymous'] = $config['anonymous'][array_rand($config['anonymous'])];

	if ($config['debug']) {
		if (!isset($debug)) {
			$debug = array(
				'sql' => array(),
				'exec' => array(),
				'purge' => array(),
				'cached' => array(),
				'write' => array(),
				'time' => array(
					'db_queries' => 0,
					'exec' => 0,
				),
				'start' => $microtime_start,
				'start_debug' => microtime(true)
			);
			$debug['start'] = $microtime_start;
		}
	}
}

function basic_error_function_because_the_other_isnt_loaded_yet($message, $priority = true) {
	global $config;

	if ($config['syslog'] && $priority !== false) {
		// Use LOG_NOTICE instead of LOG_ERR or LOG_WARNING because most error message are not significant.
		_syslog($priority !== true ? $priority : LOG_NOTICE, $message);
	}

	// Yes, this is horrible.
	die('<!DOCTYPE html><html><head><title>Error</title>' .
		'<style type="text/css">' .
			'body{text-align:center;font-family:arial, helvetica, sans-serif;font-size:10pt;}' .
			'p{padding:0;margin:20px 0;}' .
			'p.c{font-size:11px;}' .
		'</style></head>' .
		'<body><h2>Error</h2>' . $message . '<hr/>' .
		'<p class="c">This alternative error page is being displayed because the other couldn\'t be found or hasn\'t loaded yet.</p></body></html>');
}

function fatal_error_handler() {
	if ($error = error_get_last()) {
		if ($error['type'] == E_ERROR) {
			if (function_exists('error')) {
				error('Caught fatal error: ' . $error['message'] . ' in <strong>' . $error['file'] . '</strong> on line ' . $error['line'], LOG_ERR);
			} else {
				basic_error_function_because_the_other_isnt_loaded_yet('Caught fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'], LOG_ERR);
			}
		}
	}
}

function _syslog($priority, $message) {
	if (isset($_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])) {
		// CGI
		syslog($priority, $message . ' - client: ' . $_SERVER['REMOTE_ADDR'] . ', request: "' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . '"');
	} else {
		syslog($priority, $message);
	}
}

function verbose_error_handler($errno, $errstr, $errfile, $errline) {
	global $config;

	if (error_reporting() == 0)
		return false; // Looks like this warning was suppressed by the @ operator.
	if ($errno == E_DEPRECATED && !$config['deprecation_errors'])
		return false;

	error(utf8tohtml($errstr), true, array(
		'file' => $errfile . ':' . $errline,
		'errno' => $errno,
		'error' => $errstr,
		'backtrace' => array_slice(debug_backtrace(), 1)
	));
}

function define_groups() {
	global $config;

	foreach ($config['mod']['groups'] as $group_value => $group_name) {
		$group_name = strtoupper($group_name);
		if(!defined($group_name)) {
			define($group_name, $group_value);
		}
	}

	ksort($config['mod']['groups']);
}

function sprintf3($str, $vars, $delim = '%') {
	$replaces = array();
	foreach ($vars as $k => $v) {
		$replaces[$delim . $k . $delim] = $v;
	}
	return str_replace(array_keys($replaces),
					   array_values($replaces), $str);
}

function mb_substr_replace($string, $replacement, $start, $length) {
	return mb_substr($string, 0, $start) . $replacement . mb_substr($string, $start + $length);
}

function setupBoard($array) {
    global $board, $config;

    // Copy all fields from $array into $board
    $board = $array;

    // Older versions compatibility
    $board['name'] = &$board['title'];

    // Calculate channel number (already set in $board['channel'] from getBoardInfo or mod_new_board)
    $channel = isset($board['channel']) ? $board['channel'] : ceil($board['id'] / $config['boards_per_channel']);

    // Set board directory with channel number
    $board['dir'] = sprintf($config['board_path'], $channel, $board['uri']);
    $board['url'] = sprintf($config['board_abbreviation'], $board['uri']);
    $board['dir_no_slash'] = rtrim(sprintf($config['board_path'], $channel, $board['uri']), '/');
    $board['prefix'] = rtrim(dirname(sprintf($config['board_path'], $channel, '')), '/') . '/'; // Result: 'channel/N/'

    loadConfig();

    // Create channel directory if it doesn't exist
    $channel_dir = rtrim(dirname($board['dir']), '/');
    if (!file_exists($channel_dir)) {
        @mkdir($channel_dir, 0777, true) or error("Couldn't create $channel_dir. Check permissions.", true);
    }

    // Create board directory
    if (!file_exists($board['dir'])) {
        @mkdir($board['dir'], 0777) or error("Couldn't create {$board['dir']}. Check permissions.", true);
    }

    // Create subdirectories (img, thumb, res, archive, featured, mod_archive)
    foreach (['img', 'thumb', 'res', 'archive', 'featured', 'mod_archive'] as $dir) {
        if (!file_exists($board['dir'] . $config['dir'][$dir])) {
            @mkdir($board['dir'] . $config['dir'][$dir], 0777)
                or error("Couldn't create {$board['dir']}{$config['dir'][$dir]}. Check permissions.", true);
        }
    }
}

function deleteBoard($board_uri) {
    global $config;
    
    // Get board info
    $query = prepare("SELECT * FROM ``boards`` WHERE `uri` = :uri LIMIT 1");
    $query->bindValue(':uri', $board_uri);
    $query->execute() or error(db_error($query));
    
    if (!$board = $query->fetch(PDO::FETCH_ASSOC)) {
        return false; // Board doesn't exist
    }
    
    // Delete all posts from this board (get post IDs first for cleanup)
    $query = prepare("SELECT `id` FROM ``posts`` WHERE `board` = :board");
    $query->bindValue(':board', $board_uri);
    $query->execute() or error(db_error($query));
    $post_ids = $query->fetchAll(PDO::FETCH_COLUMN);
    
    // Delete cites (cross-references between posts)
    if (!empty($post_ids)) {
        $query = prepare("DELETE FROM ``cites`` WHERE (`target_board` = :board) OR (`board` = :board AND (`post` = " . implode(' OR `post` = ', $post_ids) . "))");
        $query->bindValue(':board', $board_uri);
        $query->execute() or error(db_error($query));
    }
    
    // Delete antispam entries for this board
    $query = prepare("DELETE FROM ``antispam`` WHERE `board` = :board");
    $query->bindValue(':board', $board_uri);
    $query->execute() or error(db_error($query));
    
    // Delete all posts from this board
    $query = prepare("DELETE FROM ``posts`` WHERE `board` = :board");
    $query->bindValue(':board', $board_uri);
    $query->execute() or error(db_error($query));
    
    // Delete archived threads for this board
    $query = prepare("DELETE FROM ``archive_threads`` WHERE `board_uri` = :board");
    $query->bindValue(':board', $board_uri);
    $query->execute() or error(db_error($query));
    
    // Delete archive votes for this board
    $query = prepare("DELETE FROM ``archive_votes`` WHERE `board` = :board");
    $query->bindValue(':board', $board_uri);
    $query->execute() or error(db_error($query));
    
    // Delete board counter
    $query = prepare("DELETE FROM ``board_counters`` WHERE `board` = :board");
    $query->bindValue(':board', $board_uri);
    $query->execute() or error(db_error($query));
    
    // Delete reports for posts on this board
    $query = prepare("DELETE FROM ``reports`` WHERE `board` = :board");
    $query->bindValue(':board', $board_uri);
    $query->execute() or error(db_error($query));
    
    // Delete flood records for this board
    $query = prepare("DELETE FROM ``flood`` WHERE `board` = :board");
    $query->bindValue(':board', $board_uri);
    $query->execute() or error(db_error($query));
    
    // Delete board-specific bans
    $query = prepare("DELETE FROM ``bans`` WHERE `board` = :board");
    $query->bindValue(':board', $board_uri);
    $query->execute() or error(db_error($query));
    
    // Delete modlog entries for this board
    $query = prepare("DELETE FROM ``modlogs`` WHERE `board` = :board");
    $query->bindValue(':board', $board_uri);
    $query->execute() or error(db_error($query));
    
    // Calculate the board directory
    $channel = isset($board['channel']) ? $board['channel'] : max(1, ceil($board['id'] / $config['boards_per_channel']));
    $board_dir = sprintf($config['board_path'], $channel, $board_uri);
    
    // Delete board directory and all its contents
    if (file_exists($board_dir)) {
        rrmdir($board_dir);
    }
    
    // Delete board from database
    $query = prepare("DELETE FROM ``boards`` WHERE `uri` = :uri");
    $query->bindValue(':uri', $board_uri);
    $query->execute() or error(db_error($query));
    
    // Clear cache
    cache::delete('board_' . $board_uri);
    cache::delete('all_boards');
    
    return true;
}

function openBoard($uri) {
    global $config, $build_pages, $board;

    if ($config['try_smarter']) {
        $build_pages = array();
    }

    if (isset($board) && isset($board['uri']) && $board['uri'] == $uri) {
        return true;
    }

    $b = getBoardInfo($uri);
    if ($b) {
        setupBoard($b);
        if (function_exists('after_open_board')) {
            after_open_board();
        }
        return true;
    }
    error_log("Failed to open board: $uri");
    return false;
}

function getBoardInfo($uri) {
    global $config;

    if ($config['cache']['enabled'] && ($board = cache::get('board_' . $uri))) {
        return $board;
    }

    $query = prepare("SELECT * FROM ``boards`` WHERE `uri` = :uri LIMIT 1");
    $query->bindValue(':uri', $uri);
    $query->execute() or error(db_error($query));

    if ($board = $query->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($board['channel']) || $board['channel'] <= 0) {
            $board['channel'] = max(1, ceil($board['id'] / $config['boards_per_channel']));
            $update_query = prepare("UPDATE ``boards`` SET `channel` = :channel WHERE `uri` = :uri");
            $update_query->bindValue(':channel', $board['channel'], PDO::PARAM_INT);
            $update_query->bindValue(':uri', $uri);
            $update_query->execute() or error(db_error($update_query));
        }
        if ($config['cache']['enabled']) {
            cache::set('board_' . $uri, $board);
        }
        error_log("Retrieved board $uri from database: " . print_r($board, true));
        return $board;
    }

    return false;
}

function boardTitle($uri) {
	$board = getBoardInfo($uri);
	if ($board)
		return $board['title'];
	return false;
}

function purge($uri) {
	global $config, $debug;

	// Fix for Unicode
	$uri = rawurlencode($uri);

	$noescape = "/!~*()+:";
	$noescape = preg_split('//', $noescape);
	$noescape_url = array_map("rawurlencode", $noescape);
	$uri = str_replace($noescape_url, $noescape, $uri);

	if (preg_match($config['referer_match'], $config['root']) && isset($_SERVER['REQUEST_URI'])) {
		$uri = (str_replace('\\', '/', dirname($_SERVER['REQUEST_URI'])) == '/' ? '/' : str_replace('\\', '/', dirname($_SERVER['REQUEST_URI'])) . '/') . $uri;
	} else {
		$uri = $config['root'] . $uri;
	}

	if ($config['debug']) {
		$debug['purge'][] = $uri;
	}

	foreach ($config['purge'] as &$purge) {
		$host = &$purge[0];
		$port = &$purge[1];
		$http_host = isset($purge[2]) ? $purge[2] : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
		$request = "PURGE {$uri} HTTP/1.1\r\nHost: {$http_host}\r\nUser-Agent: Tinyboard\r\nConnection: Close\r\n\r\n";
		if ($fp = fsockopen($host, $port, $errno, $errstr, $config['purge_timeout'])) {
			fwrite($fp, $request);
			fclose($fp);
		} else {
			// Cannot connect?
			error('Could not PURGE for ' . $host);
		}
	}
}

function file_write($path, $data, $simple = false, $skip_purge = false) {
	global $config, $debug;

	if (preg_match('/^remote:\/\/(.+)\:(.+)$/', $path, $m)) {
		if (isset($config['remote'])) {
			error('Remote server support has been removed');
		}
	}

	if (!$fp = fopen($path, $simple ? 'w' : 'c'))
		error('Unable to open file for writing: ' . $path);

	// File locking
	if (!$simple && !flock($fp, LOCK_EX)) {
		error('Unable to lock file: ' . $path);
	}

	// Truncate file
	if (!$simple && !ftruncate($fp, 0))
		error('Unable to truncate file: ' . $path);

	// Write data
	if (($bytes = fwrite($fp, $data)) === false)
		error('Unable to write to file: ' . $path);

	// Unlock
	if (!$simple)
		flock($fp, LOCK_UN);

	// Close
	if (!fclose($fp))
		error('Unable to close file: ' . $path);

	/**
	 * Create gzipped file.
	 *
	 * When writing into a file foo.bar and the size is larger or equal to 1
	 * KiB, this also produces the gzipped version foo.bar.gz
	 *
	 * This is useful with nginx with gzip_static on.
	 */
	if ($config['gzip_static']) {
		$gzpath = "$path.gz";

		if ($bytes & ~0x3ff) {  // if ($bytes >= 1024)
			if (file_put_contents($gzpath, gzencode($data), $simple ? 0 : LOCK_EX) === false)
				error("Unable to write to file: $gzpath");
			//if (!touch($gzpath, filemtime($path), fileatime($path)))
			//	error("Unable to touch file: $gzpath");
		}
		else {
			@unlink($gzpath);
		}
	}

	if (!$skip_purge && isset($config['purge'])) {
		// Purge cache
		if (basename($path) == $config['file_index']) {
			// Index file (/index.html); purge "/" as well
			$uri = dirname($path);
			// root
			if ($uri == '.')
				$uri = '';
			else
				$uri .= '/';
			purge($uri);
		}
		purge($path);
	}

	if ($config['debug']) {
		$debug['write'][] = $path . ': ' . $bytes . ' bytes';
	}

	event('write', $path);
}

function file_unlink($path) {
	global $config, $debug;

	if ($config['debug']) {
		if (!isset($debug['unlink']))
			$debug['unlink'] = array();
		$debug['unlink'][] = $path;
	}

	if (file_exists($path)) {
		$ret = @unlink($path);
	} else {
		$ret = true;
	}

	if ($config['gzip_static']) {
		$gzpath = "$path.gz";

		if (file_exists($gzpath)) {
			@unlink($gzpath);
		}
	}

	if (isset($config['purge']) && $path[0] != '/' && isset($_SERVER['HTTP_HOST'])) {
		// Purge cache
		if (basename($path) == $config['file_index']) {
			// Index file (/index.html); purge "/" as well
			$uri = dirname($path);
			// root
			if ($uri == '.')
				$uri = '';
			else
				$uri .= '/';
			purge($uri);
		}
		purge($path);
	}

	event('unlink', $path);

	return $ret;
}

function hasPermission($action = null, $board = null, $_mod = null) {
	global $config;

	if (isset($_mod))
		$mod = &$_mod;
	else
		global $mod;

	if (!is_array($mod))
		return false;

	if (isset($action) && $mod['type'] < $action)
		return false;

	if (!isset($board) || $config['mod']['skip_per_board'])
		return true;

	if (!isset($mod['boards']))
		return false;

	if (!in_array('*', $mod['boards']) && !in_array($board, $mod['boards']))
		return false;

	return true;
}

function listBoards($just_uri = false) {
	global $config;

	$just_uri ? $cache_name = 'all_boards_uri' : $cache_name = 'all_boards';

	if ($config['cache']['enabled'] && ($boards = cache::get($cache_name)))
		return $boards;

	if (!$just_uri) {
		$query = query("SELECT * FROM ``boards`` ORDER BY `uri`") or error(db_error());
		$boards = $query->fetchAll();
	} else {
		$boards = array();
		$query = query("SELECT `uri` FROM ``boards``") or error(db_error());
		while ($board = $query->fetchColumn()) {
			$boards[] = $board;
		}
	}

	if ($config['cache']['enabled'])
		cache::set($cache_name, $boards);

	return $boards;
}

function displayBan($ban) {
	global $config, $board;

	if (!$ban['seen']) {
		Bans::seen($ban['id']);
	}

	$ban['ip'] = $_SERVER['REMOTE_ADDR'];

	if ($ban['post'] && isset($ban['post']['board'], $ban['post']['id'])) {
		if (openBoard($ban['post']['board'])) {
			$query = prepare("SELECT `files` FROM ``posts`` WHERE `board` = :board AND `id` = :id");
			$query->bindValue(':board', $board['uri']);
			$query->bindValue(':id', (int)$ban['post']['id'], PDO::PARAM_INT);
			$query->execute() or error(db_error($query));
			if ($_post = $query->fetch(PDO::FETCH_ASSOC)) {
				$ban['post'] = array_merge($ban['post'], $_post);
			}
		}
		if ($ban['post']['thread']) {
			$post = new Post($ban['post']);
		} else {
			$post = new Thread($ban['post'], null, false, false);
		}
	}

	$denied_appeals = array();
	$pending_appeal = false;

	if ($config['ban_appeals']) {
		$query = query("SELECT `time`, `denied` FROM ``ban_appeals`` WHERE `ban_id` = " . (int)$ban['id']) or error(db_error());
		while ($ban_appeal = $query->fetch(PDO::FETCH_ASSOC)) {
			if ($ban_appeal['denied']) {
				$denied_appeals[] = $ban_appeal['time'];
			} else {
				$pending_appeal = $ban_appeal['time'];
			}
		}
	}

	// Show banned page and exit
	die(
		Element($config['file_page_template'], array(
			'title' => _('Banned!'),
			'config' => $config,
			'boardlist' => createBoardlist(isset($mod) ? $mod : false),
			'body' => Element($config['file_banned'], array(
				'config' => $config,
				'ban' => $ban,
				'board' => $board,
				'post' => isset($post) ? $post->build(true) : false,
				'denied_appeals' => $denied_appeals,
				'pending_appeal' => $pending_appeal
			)
		))
	));
}

function checkBan($board = false) {
	global $config;

	if (!isset($_SERVER['REMOTE_ADDR'])) {
		// Server misconfiguration
		return;
	}

	if (event('check-ban', $board))
		return true;

	$ips = array();

	$ips[] = $_SERVER['REMOTE_ADDR'];

	if ($config['proxy_check'] && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$ips = array_merge($ips, explode(", ", $_SERVER['HTTP_X_FORWARDED_FOR']));
	}

	foreach ($ips as $ip) {
		$bans = Bans::find($ip, $board, $config['show_modname'], null, $config['auto_maintenance']);

		foreach ($bans as &$ban) {
			if ($ban['expires'] && $ban['expires'] < time()) {
				if ($config['auto_maintenance']) {
					Bans::delete($ban['id']);
				}
				if ($config['require_ban_view'] && !$ban['seen']) {
					if (!isset($_POST['json_response'])) {
						displayBan($ban);
					} else {
						header('Content-Type: text/json');
						die(json_encode(array('error' => true, 'banned' => true)));
					}
				}
			} else {
				if (!isset($_POST['json_response'])) {
					displayBan($ban);
				} else {
					header('Content-Type: text/json');
					die(json_encode(array('error' => true, 'banned' => true)));
				}
			}
		}
	}

	if ($config['auto_maintenance']) {
		// I'm not sure where else to put this. It doesn't really matter where; it just needs to be called every
		// now and then to keep the ban list tidy.
		if ($config['cache']['enabled']) {
			$last_time_purged = cache::get('purged_bans_last');
			if ($last_time_purged !== false && time() - $last_time_purged > $config['purge_bans']) {
				Bans::purge($config['require_ban_view'], $config['purge_bans']);
				cache::set('purged_bans_last', time());
			}
		} else {
			// Purge every time.
			Bans::purge($config['require_ban_view'], $config['purge_bans']);
		}
	}
}

function threadLocked($id) {
    global $board;

    if (event('check-locked', $id))
        return true;

    $query = prepare("SELECT `locked` FROM ``posts`` WHERE `board` = :board AND `id` = :id AND `thread` IS NULL LIMIT 1");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':id', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if (($locked = $query->fetchColumn()) === false) {
        // Non-existant, so it can't be locked...
        return false;
    }

    return (bool)$locked;
}

function threadSageLocked($id) {
    global $board;

    if (event('check-sage-locked', $id))
        return true;

    $query = prepare("SELECT `sage` FROM ``posts`` WHERE `board` = :board AND `id` = :id AND `thread` IS NULL LIMIT 1");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':id', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if (($sagelocked = $query->fetchColumn()) === false) {
        // Non-existant, so it can't be locked...
        return false;
    }

    return (bool)$sagelocked;
}

function threadExists($id) {
    global $board;

    $query = prepare("SELECT 1 FROM ``posts`` WHERE `board` = :board AND `id` = :id AND `thread` IS NULL LIMIT 1");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':id', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ($query->rowCount()) {
        return true;
    }

    return false;
}

function insertFloodPost(array $post) {
	global $board;

	$query = prepare("INSERT INTO ``flood`` VALUES (NULL, :ip, :board, :time, :posthash, :filehash, :isreply)");
	$query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
	$query->bindValue(':board', $board['uri']);
	$query->bindValue(':time', time());
	$query->bindValue(':posthash', make_comment_hex($post['body_nomarkup']));
	if ($post['has_file'])
		$query->bindValue(':filehash', $post['filehash']);
	else
		$query->bindValue(':filehash', null, PDO::PARAM_NULL);
	$query->bindValue(':isreply', !$post['op'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
}

function post(array $post) {
    global $pdo, $board, $config;

     // Use the value passed in from post.php
    $live_date_path = $post['live_date_path'];

    $query = prepare("INSERT INTO ``posts`` (`board`, `board_id`, `thread`, `subject`, `email`, `name`, `trip`, `capcode`, `body`, `body_nomarkup`, `time`, `bump`, `live_date_path`, `files`, `num_files`, `filehash`, `password`, `ip`, `sticky`, `locked`, `cycle`, `sage`, `embed`, `slug`, `mod`) VALUES (:board, :board_id, :thread, :subject, :email, :name, :trip, :capcode, :body, :body_nomarkup, :time, :time, :live_date_path, :files, :num_files, :filehash, :password, :ip, :sticky, :locked, :cycle, 0, :embed, :slug, :mod)");

    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':board_id', $post['board_id'], PDO::PARAM_INT);

	// Basic stuff
	if (!empty($post['subject'])) {
		$query->bindValue(':subject', $post['subject']);
	} else {
		$query->bindValue(':subject', null, PDO::PARAM_NULL);
	}

	if (!empty($post['email'])) {
		$query->bindValue(':email', $post['email']);
	} else {
		$query->bindValue(':email', null, PDO::PARAM_NULL);
	}

	if (!empty($post['trip'])) {
		$query->bindValue(':trip', $post['trip']);
	} else {
		$query->bindValue(':trip', null, PDO::PARAM_NULL);
	}

	$query->bindValue(':name', $post['name']);
	$query->bindValue(':body', $post['body']);
	$query->bindValue(':body_nomarkup', $post['body_nomarkup']);
	$query->bindValue(':time', isset($post['time']) ? $post['time'] : time(), PDO::PARAM_INT);
	$query->bindValue(':password', $post['password']);
	$query->bindValue(':ip', isset($post['ip']) ? $post['ip'] : $_SERVER['REMOTE_ADDR']);
	$query->bindValue(':live_date_path', $live_date_path);

	if ($post['op'] && $post['mod'] && isset($post['sticky']) && $post['sticky']) {
		$query->bindValue(':sticky', true, PDO::PARAM_INT);
	} else {
		$query->bindValue(':sticky', false, PDO::PARAM_INT);
	}

	if ($post['op'] && $post['mod'] && isset($post['locked']) && $post['locked']) {
		$query->bindValue(':locked', true, PDO::PARAM_INT);
	} else {
		$query->bindValue(':locked', false, PDO::PARAM_INT);
	}

	if ($post['op'] && $post['mod'] && isset($post['cycle']) && $post['cycle']) {
		$query->bindValue(':cycle', true, PDO::PARAM_INT);
	} else {
		$query->bindValue(':cycle', false, PDO::PARAM_INT);
	}

	if ($post['mod'] && isset($post['capcode']) && $post['capcode']) {
		$query->bindValue(':capcode', $post['capcode'], PDO::PARAM_STR);
	} else {
		$query->bindValue(':capcode', null, PDO::PARAM_NULL);
	}

	if (!empty($post['embed'])) {
		$query->bindValue(':embed', $post['embed']);
	} else {
		$query->bindValue(':embed', null, PDO::PARAM_NULL);
	}

	if ($post['op']) {
		// No parent thread, image
		$query->bindValue(':thread', null, PDO::PARAM_NULL);
	} else {
		$query->bindValue(':thread', $post['thread'], PDO::PARAM_INT);
	}

	if ($post['has_file']) {
		$query->bindValue(':files', json_encode($post['files']));
		$query->bindValue(':num_files', $post['num_files']);
		$query->bindValue(':filehash', $post['filehash']);
	} else {
		$query->bindValue(':files', null, PDO::PARAM_NULL);
		$query->bindValue(':num_files', 0);
		$query->bindValue(':filehash', null, PDO::PARAM_NULL);
	}

	if ($post['op']) {
		$query->bindValue(':slug', slugify($post));
	}
	else {
		$query->bindValue(':slug', NULL);
	}

	$query->bindValue(':mod', isset($post['mod']) && $post['mod'] ? 1 : 0, PDO::PARAM_INT);

	if (!$query->execute()) {
		undoImage($post);
		error(db_error($query));
	}

	return $pdo->lastInsertId();
}

function bumpThread($id) {
    global $config, $board, $build_pages;

    if (event('bump', $id))
        return true;

    if ($config['try_smarter']) {
        $build_pages = array_merge(range(1, thread_find_page($id)), $build_pages);
    }

    $query = prepare("UPDATE ``posts`` SET `bump` = :time WHERE `board` = :board AND `id` = :id AND `thread` IS NULL");
    $query->bindValue(':time', time(), PDO::PARAM_INT);
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':id', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));
}

// Remove file from post
function deleteFile($id, $remove_entirely_if_already = true, $file = null) {
    global $board, $config;

    // Fetch post info including live_date_path
    $query = prepare("SELECT `thread`, `files`, `num_files`, `live_date_path` FROM ``posts`` WHERE `board` = :board AND `id` = :id LIMIT 1");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':id', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));
    if (!$post = $query->fetch(PDO::FETCH_ASSOC))
        error($config['error']['invalidpost']);

    $files = json_decode($post['files']);
    $file_to_delete = $file !== false ? $files[(int)$file] : (object)array('file' => false);

    if (!$files[0]) error(_('That post has no files.'));

    if ($files[0]->file == 'deleted' && $post['num_files'] == 1 && !$post['thread'])
        return; // Can't delete OP's image completely.

    $query = prepare("UPDATE ``posts`` SET `files` = :file WHERE `board` = :board AND `id` = :id");
    if (($file && $file_to_delete->file == 'deleted') && $remove_entirely_if_already) {
        // Already deleted; remove file fully
        $files[$file] = null;
    } else {
        foreach ($files as $i => $f) {
            if (($file !== false && $i == $file) || $file === null) {
                // Delete thumbnail
                if (isset($f->thumb) && $f->thumb) {
                    file_unlink($board['dir'] . $config['dir']['thumb'] . $f->thumb);
                    unset($files[$i]->thumb);
                }

                // Delete file
                if ($f->file !== 'deleted') {
                    file_unlink($board['dir'] . $config['dir']['img'] . $f->file);
                    $files[$i]->file = 'deleted';
                }
            }
        }
    }

    $query->bindValue(':file', json_encode($files), PDO::PARAM_STR);
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':id', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ($post['thread'])
        buildThread($post['thread']);
    else
        buildThread($id);
}

// rebuild post (markup)
function rebuildPost($id) {
    global $board, $mod;

    // CHANGED: Use unified posts table and filter by board
    $query = prepare("SELECT * FROM ``posts`` WHERE `board` = :board AND `id` = :id");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':id', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ((!$post = $query->fetch(PDO::FETCH_ASSOC)) || !$post['body_nomarkup'])
        return false;

    markup($post['body'] = &$post['body_nomarkup']);
    $post = (object)$post;
    event('rebuildpost', $post);
    $post = (array)$post;

    // CHANGED: Use unified posts table and filter by board
    $query = prepare("UPDATE ``posts`` SET `body` = :body WHERE `board` = :board AND `id` = :id");
    $query->bindValue(':body', $post['body']);
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':id', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    buildThread($post['thread'] ? $post['thread'] : $id);

    return true;
}

// Delete a post (reply or thread)
function deletePost($id, $error_if_doesnt_exist = true, $rebuild_after = true) {
    global $board, $config;

    $query = prepare("SELECT `id`, `thread`, `files`, `slug`, `live_date_path` FROM ``posts`` WHERE `board` = :board AND (`id` = :id OR `thread` = :id)");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':id', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ($query->rowCount() < 1) {
        if ($error_if_doesnt_exist)
            error($config['error']['invalidpost']);
        else
            return false;
    }

    $ids = array();
    $is_thread_deletion = false;

    // Delete posts and maybe replies
    while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
        event('delete', $post);

        // Check if we're deleting a thread (OP has no thread reference)
        if (!$post['thread']) {
            $is_thread_deletion = true;
        }

        $thread_id = $post['thread'];
        $live_date_path = $post['live_date_path'] ? $post['live_date_path'] . '/' : '';

        if (!$post['thread']) {
            // Delete thread HTML page
            file_unlink($board['dir'] . $config['dir']['res'] . $post['live_date_path'] . '/' . link_for($post));
            file_unlink($board['dir'] . $config['dir']['res'] . $post['live_date_path'] . '/' . link_for($post, true)); // noko50
            file_unlink($board['dir'] . $config['dir']['res'] . $post['live_date_path'] . '/' . sprintf('%d.json', $post['id']));

            $antispam_query = prepare('DELETE FROM ``antispam`` WHERE `board` = :board AND `thread` = :thread');
            $antispam_query->bindValue(':board', $board['uri']);
            $antispam_query->bindValue(':thread', $post['id']);
            $antispam_query->execute() or error(db_error($antispam_query));
        } elseif ($query->rowCount() == 1) {
            // Rebuild thread
            $rebuild = &$post['thread'];
        }
        if ($post['files']) {
            foreach (json_decode($post['files']) as $i => $f) {
                if ($f->file !== 'deleted') {
                    file_unlink($board['dir'] . $config['dir']['img'] . $f->file);
                    file_unlink($board['dir'] . $config['dir']['thumb'] . $f->thumb);
                }
            }
        }

        $ids[] = (int)$post['id'];
    }

    $query = prepare("DELETE FROM ``posts`` WHERE `board` = :board AND (`id` = :id OR `thread` = :id)");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':id', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    // Delete reports for these posts
    if (!empty($ids)) {
        $query = prepare("DELETE FROM ``reports`` WHERE `board` = :board AND (`post` = " . implode(' OR `post` = ', $ids) . ")");
        $query->bindValue(':board', $board['uri']);
        $query->execute() or error(db_error($query));
    }

    // Delete archive votes for this thread (if we're deleting a thread)
    if ($is_thread_deletion) {
        $query = prepare("DELETE FROM ``archive_votes`` WHERE `board` = :board AND `thread_id` = :thread_id");
        $query->bindValue(':board', $board['uri']);
        $query->bindValue(':thread_id', $id, PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
    }

    $query = prepare("SELECT `board`, `post` FROM ``cites`` WHERE `target_board` = :board AND (`target` = " . implode(' OR `target` = ', $ids) . ") ORDER BY `board`");
    $query->bindValue(':board', $board['uri']);
    $query->execute() or error(db_error($query));
    while ($cite = $query->fetch(PDO::FETCH_ASSOC)) {
        if ($board['uri'] != $cite['board']) {
            if (!isset($tmp_board))
                $tmp_board = $board['uri'];
            openBoard($cite['board']);
        }
        rebuildPost($cite['post']);
    }

    if (isset($tmp_board))
        openBoard($tmp_board);

    $query = prepare("DELETE FROM ``cites`` WHERE (`target_board` = :board AND (`target` = " . implode(' OR `target` = ', $ids) . ")) OR (`board` = :board AND (`post` = " . implode(' OR `post` = ', $ids) . "))");
    $query->bindValue(':board', $board['uri']);
    $query->execute() or error(db_error($query));

    // No need to run on OPs
    if ($config['anti_bump_flood'] && isset($thread_id)) {
        $query = prepare("SELECT `sage` FROM ``posts`` WHERE `board` = :board AND `id` = :thread");
        $query->bindValue(':board', $board['uri']);
        $query->bindValue(':thread', $thread_id);
        $query->execute() or error(db_error($query));
        $bumplocked = (bool)$query->fetchColumn();

        if (!$bumplocked) {
            $query = prepare("SELECT `time` FROM ``posts`` WHERE `board` = :board AND ((`thread` = :thread AND NOT email <=> 'sage') OR `id` = :thread) ORDER BY `time` DESC LIMIT 1");
            $query->bindValue(':board', $board['uri']);
            $query->bindValue(':thread', $thread_id);
            $query->execute() or error(db_error($query));
            $bump = $query->fetchColumn();

            $query = prepare("UPDATE ``posts`` SET `bump` = :bump WHERE `board` = :board AND `id` = :thread");
            $query->bindValue(':bump', $bump);
            $query->bindValue(':board', $board['uri']);
            $query->bindValue(':thread', $thread_id);
            $query->execute() or error(db_error($query));
        }
    }

    if (isset($rebuild) && $rebuild_after) {
        buildThread($rebuild);
        buildIndex();
    }

    return true;
}

function clean($pid = false) {
    global $board, $config;

    // If we are doing the archiving in cron leave cleaning of overflow for now
    if($config['archive']['cron_job']['archiving'])
        return;

    $offset = round($config['max_pages']*$config['threads_per_page']);

    // CHANGED: Use unified posts table and filter by board
    $query = prepare("SELECT `id` FROM ``posts`` WHERE `board` = :board AND `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC LIMIT :offset, 9001");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':offset', $offset, PDO::PARAM_INT);

    $query->execute() or error(db_error($query));
    while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
        if($config['archive']['threads']) {
            Archive::archiveThread($post['id']);
            deletePost($post['id'], false, false);
            if ($pid) modLog("Automatically archived thread #{$post['id']} due to new thread #{$pid}");
        } else {
            deletePost($post['id'], false, false);
            if ($pid) modLog("Automatically deleting thread #{$post['id']} due to new thread #{$pid}");
        }
    }

    // Bump off threads with X replies earlier, spam prevention method
    if ($config['early_404']) {
        $offset = round($config['early_404_page']*$config['threads_per_page']);
        $query = prepare("SELECT `id` AS `thread_id`, (SELECT COUNT(`id`) FROM ``posts`` WHERE `board` = :board AND `thread` = `thread_id`) AS `reply_count` FROM ``posts`` WHERE `board` = :board AND `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC LIMIT :offset, 9001");
        $query->bindValue(':board', $board['uri']);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        if ($config['early_404_staged']) {
            $page = $config['early_404_page'];
            $iter = 0;
        }
        else {
            $page = 1;
        }

        while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
            if ($post['reply_count'] < $page*$config['early_404_replies']) {
                if($config['archive']['threads']) {
                    Archive::archiveThread($post['thread_id']);
                    deletePost($post['thread_id'], false, false);
                    if ($pid) modLog("Automatically archived thread #{$post['thread_id']} due to new thread #{$pid} (early 404 is set, #{$post['thread_id']} had {$post['reply_count']} replies)");
                } else {
                    deletePost($post['thread_id'], false, false);
                    if ($pid) modLog("Automatically deleting thread #{$post['thread_id']} due to new thread #{$pid} (early 404 is set, #{$post['thread_id']} had {$post['reply_count']} replies)");
                }
            }

            if ($config['early_404_staged']) {
                $iter++;

                if ($iter == $config['threads_per_page']) {
                    $page++;
                    $iter = 0;
                }
            }
        }
    }
}

function thread_find_page($thread) {
    global $config, $board;

    // CHANGED: Use unified posts table and filter by board
    $query = query("SELECT `id` FROM ``posts`` WHERE `board` = '" . $board['uri'] . "' AND `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC") or error(db_error($query));
    $threads = $query->fetchAll(PDO::FETCH_COLUMN);
    if (($index = array_search($thread, $threads)) === false)
        return false;
    return floor(($config['threads_per_page'] + $index) / $config['threads_per_page']);
}

// $brief means that we won't need to generate anything yet
function index($page, $mod=false, $brief = false) {
    global $board, $config, $debug;

    $body = '';
    $offset = round($page*$config['threads_per_page']-$config['threads_per_page']);

    // CHANGED: Use unified posts table and filter by board
    $query = prepare("SELECT * FROM ``posts`` WHERE `board` = :board AND `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC LIMIT :offset,:threads_per_page");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':offset', $offset, PDO::PARAM_INT);
    $query->bindValue(':threads_per_page', $config['threads_per_page'], PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ($page == 1 && $query->rowCount() < $config['threads_per_page'])
        $board['thread_count'] = $query->rowCount();

    if ($query->rowCount() < 1 && $page > 1)
        return false;

    $threads = array();

    while ($th = $query->fetch(PDO::FETCH_ASSOC)) {
        $thread = new Thread($th, $mod ? '?/' : $config['root'], $mod);
        
        $poll = get_poll($th['id']);
        if ($poll) {
            $thread->poll = $poll;
        }

        if ($config['cache']['enabled']) {
            $cached = cache::get("thread_index_{$board['uri']}_{$th['id']}");
            if (isset($cached['replies'], $cached['omitted'])) {
                $replies = $cached['replies'];
                $omitted = $cached['omitted'];
            } else {
                unset($cached);
            }
        }

        if (!isset($cached)) {
            $posts = prepare("SELECT * FROM ``posts`` WHERE `board` = :board AND `thread` = :id ORDER BY `id` DESC LIMIT :limit");
            $posts->bindValue(':board', $board['uri']);
            $posts->bindValue(':id', $th['id']);
            $posts->bindValue(':limit', ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview']), PDO::PARAM_INT);
            $posts->execute() or error(db_error($posts));

            $replies = array_reverse($posts->fetchAll(PDO::FETCH_ASSOC));

            if (count($replies) == ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview'])) {
                $count = numPosts($th['id']);
                $omitted = array('post_count' => $count['replies'], 'image_count' => $count['images']);
            } else {
                $omitted = false;
            }

            if ($config['cache']['enabled'])
                cache::set("thread_index_{$board['uri']}_{$th['id']}", array(
                    'replies' => $replies,
                    'omitted' => $omitted,
                ));
        }

        $num_images = 0;
        foreach ($replies as $po) {
            if ($po['num_files'])
                $num_images+=$po['num_files'];

            $thread->add(new Post($po, $mod ? '?/' : $config['root'], $mod));
        }

        $thread->images = $num_images;
        $thread->replies = isset($omitted['post_count']) ? $omitted['post_count'] : count($replies);

        if ($omitted) {
            $thread->omitted = $omitted['post_count'] - ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview']);
            $thread->omitted_images = $omitted['image_count'] - $num_images;
        }

        $threads[] = $thread;

        if (!$brief) {
            $body .= $thread->build(true);
        }
    }

    if ($config['file_board']) {
        $options = [
            'body' => $body,
            'mod' => $mod
        ];
        if ($mod) {
            $options['pm'] = create_pm_header();
        }
        $body = Element($config['file_fileboard'], $options);
    }

    return array(
        'board' => $board,
        'body' => $body,
        'post_url' => $config['post_url'],
        'config' => $config,
        'boardlist' => createBoardlist($mod),
        'threads' => $threads,
    );
}

function getPageButtons($pages, $mod=false) {
	global $config, $board;

	$btn = array();
	$root = ($mod ? '?/' : $config['root']) . $board['dir'];

	foreach ($pages as $num => $page) {
		if (isset($page['selected'])) {
			// Previous button
			if ($num == 0) {
				// There is no previous page.
				$btn['prev'] = _('Previous');
			} else {
				$loc = ($mod ? '?/' . $board['uri'] . '/' : '') .
					($num == 1 ?
						$config['file_index']
					:
						sprintf($config['file_page'], $num)
					);

				$btn['prev'] = '<form action="' . ($mod ? '' : $root . $loc) . '" method="get">' .
					($mod ?
						'<input type="hidden" name="status" value="301" />' .
						'<input type="hidden" name="r" value="' . htmlentities($loc) . '" />'
					:'') .
				'<input type="submit" value="' . _('Previous') . '" /></form>';
			}

			if ($num == count($pages) - 1) {
				// There is no next page.
				$btn['next'] = _('Next');
			} else {
				$loc = ($mod ? '?/' . $board['uri'] . '/' : '') . sprintf($config['file_page'], $num + 2);

				$btn['next'] = '<form action="' . ($mod ? '' : $root . $loc) . '" method="get">' .
					($mod ?
						'<input type="hidden" name="status" value="301" />' .
						'<input type="hidden" name="r" value="' . htmlentities($loc) . '" />'
					:'') .
				'<input type="submit" value="' . _('Next') . '" /></form>';
			}
		}
	}

	return $btn;
}

function getPages($mod=false) {
    global $board, $config;

    if (isset($board['thread_count'])) {
        $count = $board['thread_count'];
    } else {
        // CHANGED: Use unified posts table and filter by board
        $query = query("SELECT COUNT(*) FROM ``posts`` WHERE `board` = '" . $board['uri'] . "' AND `thread` IS NULL") or error(db_error());
        $count = $query->fetchColumn();
    }
    $count = floor(($config['threads_per_page'] + $count - 1) / $config['threads_per_page']);

    if ($count < 1) $count = 1;

    $pages = array();
    for ($x=0;$x<$count && $x<$config['max_pages'];$x++) {
        if ($x == 0) {
            $link = ($mod ? '?/' : $config['root']) . $board['dir'] . $config['file_index'];
        } else {
            $folder_num = intval(($x - 1) / 1000) + 1;
            $link = ($mod ? '?/' : $config['root']) . $board['dir'] . 'pagination/' . $folder_num . '/' . sprintf($config['file_page'], $x+1);
        }
        $pages[] = array(
            'num' => $x+1,
            'link' => $link
        );
    }

    return $pages;
}

// Stolen with permission from PlainIB (by Frank Usrs)
function make_comment_hex($str) {
	// remove cross-board citations
	// the numbers don't matter
	$str = preg_replace('!>>>/[A-Za-z0-9]+/!', '', $str);

	if (function_exists('iconv')) {
		// remove diacritics and other noise
		// FIXME: this removes cyrillic entirely
		$oldstr = $str;
		$str = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
		if (!$str) $str = $oldstr;
	}

	$str = strtolower($str);

	// strip all non-alphabet characters
	$str = preg_replace('/[^a-z]/', '', $str);

	return md5($str);
}

function makerobot($body) {
	global $config;
	$body = strtolower($body);

	// Leave only letters
	$body = preg_replace('/[^a-z]/i', '', $body);
	// Remove repeating characters
	if ($config['robot_strip_repeating'])
		$body = preg_replace('/(.)\\1+/', '$1', $body);

	return sha1($body);
}

function checkRobot($body) {
	if (empty($body) || event('check-robot', $body))
		return true;

	$body = makerobot($body);
	$query = prepare("SELECT 1 FROM ``robot`` WHERE `hash` = :hash LIMIT 1");
	$query->bindValue(':hash', $body);
	$query->execute() or error(db_error($query));

	if ($query->fetchColumn()) {
		return true;
	}

	// Insert new hash
	$query = prepare("INSERT INTO ``robot`` VALUES (:hash)");
	$query->bindValue(':hash', $body);
	$query->execute() or error(db_error($query));

	return false;
}

// Returns an associative array with 'replies' and 'images' keys
function numPosts($id) {
    global $board;
    // CHANGED: Use unified posts table and filter by board
    $query = prepare("SELECT COUNT(*) AS `replies`, SUM(`num_files`) AS `images` FROM ``posts`` WHERE `board` = :board AND `thread` = :thread");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':thread', $id, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    return $query->fetch(PDO::FETCH_ASSOC);
}

function muteTime() {
	global $config;

	if ($time = event('mute-time'))
		return $time;

	// Find number of mutes in the past X hours
	$query = prepare("SELECT COUNT(*) FROM ``mutes`` WHERE `time` >= :time AND `ip` = :ip");
	$query->bindValue(':time', time()-($config['robot_mute_hour']*3600), PDO::PARAM_INT);
	$query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
	$query->execute() or error(db_error($query));

	if (!$result = $query->fetchColumn())
		return 0;
	return pow($config['robot_mute_multiplier'], $result);
}

function mute() {
	// Insert mute
	$query = prepare("INSERT INTO ``mutes`` VALUES (:ip, :time)");
	$query->bindValue(':time', time(), PDO::PARAM_INT);
	$query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
	$query->execute() or error(db_error($query));

	return muteTime();
}

function checkMute() {
	global $config, $debug;

	if ($config['cache']['enabled']) {
		// Cached mute?
		if (($mute = cache::get("mute_{$_SERVER['REMOTE_ADDR']}")) && ($mutetime = cache::get("mutetime_{$_SERVER['REMOTE_ADDR']}"))) {
			error(sprintf($config['error']['youaremuted'], $mute['time'] + $mutetime - time()));
		}
	}

	$mutetime = muteTime();
	if ($mutetime > 0) {
		// Find last mute time
		$query = prepare("SELECT `time` FROM ``mutes`` WHERE `ip` = :ip ORDER BY `time` DESC LIMIT 1");
		$query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
		$query->execute() or error(db_error($query));

		if (!$mute = $query->fetch(PDO::FETCH_ASSOC)) {
			// What!? He's muted but he's not muted...
			return;
		}

		if ($mute['time'] + $mutetime > time()) {
			if ($config['cache']['enabled']) {
				cache::set("mute_{$_SERVER['REMOTE_ADDR']}", $mute, $mute['time'] + $mutetime - time());
				cache::set("mutetime_{$_SERVER['REMOTE_ADDR']}", $mutetime, $mute['time'] + $mutetime - time());
			}
			// Not expired yet
			error(sprintf($config['error']['youaremuted'], $mute['time'] + $mutetime - time()));
		} else {
			// Already expired
			return;
		}
	}
}

function purge_old_antispam() {
	$query = prepare('DELETE FROM ``antispam`` WHERE `expires` < UNIX_TIMESTAMP()');
	$query->execute() or error(db_error());
	return $query->rowCount();
}

function checkSpam(array $extra_salt = array()) {
	global $config, $pdo;

	if (!isset($_POST['hash']))
		return true;

	$hash = $_POST['hash'];

	if (!empty($extra_salt)) {
		// create a salted hash of the "extra salt"
		$extra_salt = implode(':', $extra_salt);
	} else {
		$extra_salt = '';
	}

	// Reconsturct the $inputs array
	$inputs = array();

	foreach ($_POST as $name => $value) {
		if (in_array($name, $config['spam']['valid_inputs']))
			continue;

		$inputs[$name] = $value;
	}

	// Sort the inputs in alphabetical order (A-Z)
	ksort($inputs);

	$_hash = '';

	// Iterate through each input
	foreach ($inputs as $name => $value) {
		$_hash .= $name . '=' . $value;
	}

	// Add a salt to the hash
	$_hash .= $config['cookies']['salt'];

	// Use SHA1 for the hash
	$_hash = sha1($_hash . $extra_salt);

	if ($hash != $_hash)
		return true;

	$query = prepare('SELECT `passed` FROM ``antispam`` WHERE `hash` = :hash');
	$query->bindValue(':hash', $hash);
	$query->execute() or error(db_error($query));
	if ((($passed = $query->fetchColumn(0)) === false) || ($passed > $config['spam']['hidden_inputs_max_pass'])) {
		// there was no database entry for this hash. most likely expired.
		return true;
	}

	return $hash;
}

function incrementSpamHash($hash) {
	$query = prepare('UPDATE ``antispam`` SET `passed` = `passed` + 1 WHERE `hash` = :hash');
	$query->bindValue(':hash', $hash);
	$query->execute() or error(db_error($query));
}

function buildIndex($start_page = 1, $end_page = null, $global_api = "yes") {
    global $board, $config, $build_pages, $mod;

    if ($end_page === null) {
        $end_page = $config['max_pages'];
    }
    $end_page = min($end_page, $config['max_pages']);

    $catalog_api_action = generation_strategy('sb_api', array($board['uri']));

    $pages = null;

    if ($config['api']['enabled']) {
        $api = new Api(
            $config['show_filename'],
            $config['hide_email'],
            $config['country_flags']
        );
        $catalog = array();
    }

    for ($page = $start_page; $page <= $end_page; $page++) {
        if ($page == 1) {
            $filename = $board['dir'] . $config['file_index'];
            $jsonFilename = $board['dir'] . '0.json';
        } else {
            $folder_num = intval(($page - 2) / 1000) + 1;
            $folder_path = $board['dir'] . 'pagination/' . $folder_num . '/';
            if (!is_dir($folder_path)) {
                @mkdir($folder_path, 0777, true);
            }
            $filename = $folder_path . sprintf($config['file_page'], $page);
            $jsonFilename = $folder_path . ($page - 1) . '.json';
        }

        $wont_build_this_page = $config['try_smarter'] && isset($build_pages) && !empty($build_pages) && !in_array($page, $build_pages);

        if ((!$config['api']['enabled'] || $global_api == "skip") && $wont_build_this_page)
            continue;

        $action = generation_strategy('sb_board', array($board['uri'], $page));
        if ($action == 'rebuild' || $catalog_api_action == 'rebuild') {
            $content = index($page, false, $wont_build_this_page);
            if (!$content)
                break;

            if ($config['cache']['enabled']) {
                $contentHash = md5(json_encode($content['body']));
                $contentHashKey = '_index_hashed_' . $board['uri'] . '_' . $page;
                $cachedHash = cache::get($contentHashKey);
                if ($cachedHash == $contentHash) {
                    if ($config['api']['enabled']) {
                        $catalog[$page - 1] = $content['threads'];
                    }
                    continue;
                }
                cache::set($contentHashKey, $contentHash, 3600);
            }

            if ($config['api']['enabled']) {
                $threads = $content['threads'];
                $json = json_encode($api->translatePage($threads));
                file_write($jsonFilename, $json);

                $catalog[$page - 1] = $threads;

                if ($wont_build_this_page) continue;
            }

            if (!$pages) {
                $pages = getPages();
            }
            $content['pages'] = $pages;
            $content['pages'][$page - 1]['selected'] = true;
            $content['btn'] = getPageButtons($content['pages']);
            if ($mod) {
                $content['pm'] = create_pm_header();
            }

            file_write($filename, Element($config['file_board_index'], $content));
        } elseif ($action == 'delete' || $catalog_api_action == 'delete') {
            file_unlink($filename);
            file_unlink($jsonFilename);
        }
    }

    if (($catalog_api_action == 'rebuild' || $action == 'rebuild' || ($action == 'delete' && $page <= $config['max_pages']))) {
        for ($page = $page; $page <= $config['max_pages']; $page++) {
            if ($page == 1) {
                $filename = $board['dir'] . $config['file_index'];
                $jsonFilename = $board['dir'] . '0.json';
            } else {
                $folder_num = intval(($page - 2) / 1000) + 1;
                $folder_path = $board['dir'] . 'pagination/' . $folder_num . '/';
                $filename = $folder_path . sprintf($config['file_page'], $page);
                $jsonFilename = $folder_path . ($page - 1) . '.json';
            }
            file_unlink($filename);

            if ($config['api']['enabled']) {
                file_unlink($jsonFilename);
            }
        }
    }

    if ($config['api']['enabled'] && $global_api != "skip") {
        if ($catalog_api_action == 'delete') {
            $jsonFilename = $board['dir'] . 'catalog.json';
            file_unlink($jsonFilename);
            $jsonFilename = $board['dir'] . 'threads.json';
            file_unlink($jsonFilename);
        } elseif ($catalog_api_action == 'rebuild') {
            $json = json_encode($api->translateCatalog($catalog));
            $jsonFilename = $board['dir'] . 'catalog.json';
            file_write($jsonFilename, $json);

            $json = json_encode($api->translateCatalog($catalog, true));
            $jsonFilename = $board['dir'] . 'threads.json';
            file_write($jsonFilename, $json);
        }
    }

    // Archive rebuilding is now handled in mod_rebuild
    if ($config['try_smarter'])
        $build_pages = array();
}

function buildJavascript() {
	global $config;

	$stylesheets = array();
	foreach ($config['stylesheets'] as $name => $uri) {
		$stylesheets[] = array(
			'name' => addslashes($name),
			'uri' => addslashes((!empty($uri) ? $config['uri_stylesheets'] : '') . $uri));
	}

	$script = Element('main.js', array(
		'config' => $config,
		'stylesheets' => $stylesheets
	));

	// Check if we have translation for the javascripts; if yes, we add it to additional javascripts
	list($pure_locale) = explode(".", $config['locale']);
	if (file_exists ($jsloc = "inc/locale/$pure_locale/LC_MESSAGES/javascript.js")) {
		$script = file_get_contents($jsloc) . "\n\n" . $script;
	}

	if ($config['additional_javascript_compile']) {
		foreach ($config['additional_javascript'] as $file) {
			$script .= file_get_contents($file);
		}
	}

	if ($config['minify_js']) {
		$script = JSMin::minify($script);
	}

	file_write($config['file_script'], $script);
}

function checkDNSBL() {
	global $config;

	if (isIPv6())
		return; // No IPv6 support yet.

	if (!isset($_SERVER['REMOTE_ADDR']))
		return; // Fix your web server configuration

	if (preg_match("/^(::(ffff:)?)?(127\.|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|0\.|255\.)/", $_SERVER['REMOTE_ADDR']))
		return; // It's pointless to check for local IP addresses in dnsbls, isn't it?

	if (in_array($_SERVER['REMOTE_ADDR'], $config['dnsbl_exceptions']))
		return;

	$ipaddr = ReverseIPOctets($_SERVER['REMOTE_ADDR']);

	foreach ($config['dnsbl'] as $blacklist) {
		if (!is_array($blacklist))
			$blacklist = array($blacklist);

		if (($lookup = str_replace('%', $ipaddr, $blacklist[0])) == $blacklist[0])
			$lookup = $ipaddr . '.' . $blacklist[0];

		if (!$ip = DNS($lookup))
			continue; // not in list

		$blacklist_name = isset($blacklist[2]) ? $blacklist[2] : $blacklist[0];

		if (!isset($blacklist[1])) {
			// If you're listed at all, you're blocked.
			error(sprintf($config['error']['dnsbl'], $blacklist_name));
		} elseif (is_array($blacklist[1])) {
			foreach ($blacklist[1] as $octet) {
				if ($ip == $octet || $ip == '127.0.0.' . $octet)
					error(sprintf($config['error']['dnsbl'], $blacklist_name));
			}
		} elseif (is_callable($blacklist[1])) {
			if ($blacklist[1]($ip))
				error(sprintf($config['error']['dnsbl'], $blacklist_name));
		} else {
			if ($ip == $blacklist[1] || $ip == '127.0.0.' . $blacklist[1])
				error(sprintf($config['error']['dnsbl'], $blacklist_name));
		}
	}
}

function isIPv6() {
	return strstr($_SERVER['REMOTE_ADDR'], ':') !== false;
}

function ReverseIPOctets($ip) {
	return implode('.', array_reverse(explode('.', $ip)));
}

function wordfilters(&$body) {
	global $config;

	foreach ($config['wordfilters'] as $filter) {
		if (isset($filter[2]) && $filter[2]) {
			if (is_callable($filter[1]))
				$body = preg_replace_callback($filter[0], $filter[1], $body);
			else
				$body = preg_replace($filter[0], $filter[1], $body);
		} else {
			$body = str_ireplace($filter[0], $filter[1], $body);
		}
	}
}

function quote($body, $quote=true) {
	global $config;

	$body = str_replace('<br/>', "\n", $body);

	$body = strip_tags($body);

	$body = preg_replace("/(^|\n)/", '$1&gt;', $body);

	$body .= "\n";

	if ($config['minify_html'])
		$body = str_replace("\n", '&#010;', $body);

	return $body;
}

function markup_url($matches) {
	global $config, $markup_urls;

	$url = $matches[1];
	$after = $matches[2];

	$markup_urls[] = $url;

	$link = (object) array(
		'href' => $config['link_prefix'] . $url,
		'text' => $url,
		'rel' => 'nofollow',
		'target' => '_blank',
	);

	event('markup-url', $link);
	$link = (array)$link;

	$parts = array();
	foreach ($link as $attr => $value) {
		if ($attr == 'text' || $attr == 'after')
			continue;
		$parts[] = $attr . '="' . $value . '"';
	}
	if (isset($link['after']))
		$after = $link['after'] . $after;
	return '<a ' . implode(' ', $parts) . '>' . $link['text'] . '</a>' . $after;
}

function unicodify($body) {
	$body = str_replace('...', '&hellip;', $body);
	$body = str_replace('&lt;--', '&larr;', $body);
	$body = str_replace('--&gt;', '&rarr;', $body);

	// En and em- dashes are rendered exactly the same in
	// most monospace fonts (they look the same in code
	// editors).
	$body = str_replace('---', '&mdash;', $body); // em dash
	$body = str_replace('--', '&ndash;', $body); // en dash

	return $body;
}

function extract_modifiers($body) {
	$modifiers = array();

	if (preg_match_all('@<tinyboard ([\w\s]+)>(.*?)</tinyboard>@us', $body, $matches, PREG_SET_ORDER)) {
		foreach ($matches as $match) {
			if (preg_match('/^escape /', $match[1]))
				continue;
			$modifiers[$match[1]] = html_entity_decode($match[2]);
		}
	}

	return $modifiers;
}

function remove_modifiers($body) {
	return $body ? preg_replace('@<tinyboard ([\w\s]+)>(.+?)</tinyboard>@usm', '', $body) : null;
}

function markup(&$body, $track_cites = false, $op = false) {
    global $board, $config, $markup_urls, $pdo;

    $modifiers = extract_modifiers($body);

    $body = preg_replace('@<tinyboard (?!escape )([\w\s]+)>(.+?)</tinyboard>@us', '', $body);
    $body = preg_replace('@<(tinyboard) escape ([\w\s]+)>@i', '<$1 $2>', $body);

    if (isset($modifiers['raw html']) && $modifiers['raw html'] == '1') {
        return array();
    }

    $body = str_replace("\r", '', $body);
    $body = utf8tohtml($body);

    if (mysql_version() < 50503)
        $body = mb_encode_numericentity($body, array(0x010000, 0xffffff, 0, 0xffffff), 'UTF-8');

    if ($config['markup_code']) {
        $code_markup = array();
        $body = preg_replace_callback($config['markup_code'], function($matches) use (&$code_markup) {
            $d = count($code_markup);
            $code_markup[] = $matches;
            return "<code $d>";
        }, $body);
    }

    foreach ($config['markup'] as $markup) {
        if (is_string($markup[1])) {
            $body = preg_replace($markup[0], $markup[1], $body);
        } elseif (is_callable($markup[1])) {
            $body = preg_replace_callback($markup[0], $markup[1], $body);
        }
    }

    if ($config['markup_urls']) {
        $markup_urls = array();

        $body = preg_replace_callback(
                '/((?:https?:\/\/|ftp:\/\/|irc:\/\/)[^\s<>()"]+?(?:\([^\s<>()"]*?\)[^\s<>()"]*?)*)((?:\s|<|>|"|\.||\]|!|\?|,|&#44;|&quot;)*(?:[\s<>()"]|$))/',
                'markup_url',
                $body,
                -1,
                $num_links);

        if ($num_links > $config['max_links'])
            error($config['error']['toomanylinks']);
    }

    if ($config['markup_repair_tidy'])
        $body = str_replace('  ', ' &nbsp;', $body);

    if ($config['auto_unicode']) {
        $body = unicodify($body);

        if ($config['markup_urls']) {
            foreach ($markup_urls as &$url) {
                $body = str_replace(unicodify($url), $url, $body);
            }
        }
    }

    $tracked_cites = array();

    // Cites
    if (isset($board) && preg_match_all('/(^|[\s(])&gt;&gt;(\d+?)((?=[\s,.)?!])|$)/m', $body, $cites, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        if (count($cites[0]) > $config['max_cites']) {
            error($config['error']['toomanycites']);
        }

        $skip_chars = 0;
        $body_tmp = $body;

        $search_cites = array();
        foreach ($cites as $matches) {
            $search_cites[] = '`board_id` = ' . $matches[2][0];
        }
        $search_cites = array_unique($search_cites);

        /// Unified posts table: filter by board_id
		$query = query('SELECT `thread`, `id`, `board_id`, `live_date_path` FROM ``posts`` WHERE `board` = ' . $pdo->quote($board['uri']) . ' AND (' .
			implode(' OR ', $search_cites) . ')') or error(db_error());

        $cited_posts = array();
        while ($cited = $query->fetch(PDO::FETCH_ASSOC)) {
            $cited_posts[$cited['board_id']] = [
                'id' => $cited['id'],
                'thread' => $cited['thread'] ? $cited['thread'] : false,
                'board_id' => $cited['board_id'],
				'live_date_path' => $cited['live_date_path']
            ];
        }

        foreach ($cites as $matches) {
            $cite = $matches[2][0];

            // preg_match_all is not multibyte-safe
            foreach ($matches as &$match) {
                $match[1] = mb_strlen(substr($body_tmp, 0, $match[1]));
            }

            if (isset($cited_posts[$cite])) {
                $replacement = '<a onclick="highlightReply(\'' . $cited_posts[$cite]['id'] . '\', event);" href="' .
                    $config['root'] . $board['dir'] . $config['dir']['res'] . $cited_posts[$cite]['live_date_path'] . '/' .
                    link_for(array('id' => $cited_posts[$cite]['id'], 'thread' => $cited_posts[$cite]['thread'])) . '#' . $cited_posts[$cite]['id'] . '">' .
                    '&gt;&gt;' . $cited_posts[$cite]['board_id'] .
                    '</a>';

                $body = mb_substr_replace($body, $matches[1][0] . $replacement . $matches[3][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
                $skip_chars += mb_strlen($matches[1][0] . $replacement . $matches[3][0]) - mb_strlen($matches[0][0]);

                if ($track_cites && $config['track_cites'])
                    $tracked_cites[] = array($board['uri'], $cited_posts[$cite]['id']);
            }
        }
    }

    // Cross-board linking
    if (preg_match_all('/(^|[\s(])&gt;&gt;&gt;\/(' . $config['board_regex'] . 'f?)\/(\d+)((?=[\s,.)?!])|$)/um', $body, $cites, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
		if (count($cites[0]) > $config['max_cites']) {
			error($config['error']['toomanycross']);
		}

		$skip_chars = 0;
		$body_tmp = $body;

		$cited_posts = array();
		$crossboard_indexes = array();
		$search_cites_boards = array();

		foreach ($cites as $matches) {
			$_board = $matches[2][0];
			$board_id = $matches[3][0];

			if (!isset($search_cites_boards[$_board]))
				$search_cites_boards[$_board] = array();
			$search_cites_boards[$_board][] = $board_id;
		}

		$tmp_board = $board['uri'];

		foreach ($search_cites_boards as $_board => $search_cites) {
			$clauses = array();
			foreach ($search_cites as $board_id) {
				if (!$board_id || isset($cited_posts[$_board][$board_id]))
					continue;
				$clauses[] = '`board_id` = ' . $board_id;
			}
			$clauses = array_unique($clauses);

			if ($board['uri'] != $_board) {
				if (!openBoard($_board))
					continue; // Unknown board
			}

			if (!empty($clauses)) {
				$cited_posts[$_board] = array();

				// Query to map board_id to id
				$query = query('SELECT `id`, `board_id`, `thread`, `slug`, `live_date_path` FROM ``posts`` WHERE `board` = ' . $pdo->quote($board['uri']) . ' AND (' .
					implode(' OR ', $clauses) . ')') or error(db_error());

				while ($cite = $query->fetch(PDO::FETCH_ASSOC)) {
					$live_date_path = isset($cite['live_date_path']) && $cite['live_date_path'] ? $cite['live_date_path'] . '/' : '';
					$cited_posts[$_board][$cite['board_id']] = [
						'link' => $config['root'] . $board['dir'] . $config['dir']['res'] . $live_date_path . link_for($cite) . '#' . $cite['id'],
						'id' => $cite['id']
					];
				}
			}

			$crossboard_indexes[$_board] = $config['root'] . $board['dir'] . $config['file_index'];
		}

		// Restore old board
		if ($board['uri'] != $tmp_board)
			openBoard($tmp_board);

		foreach ($cites as $matches) {
			$_board = $matches[2][0];
			$board_id = $matches[3][0];

			// preg_match_all is not multibyte-safe
			foreach ($matches as &$match) {
				$match[1] = mb_strlen(substr($body_tmp, 0, $match[1]));
			}

			if (isset($cited_posts[$_board][$board_id])) {
				$link = $cited_posts[$_board][$board_id]['link'];
				$id = $cited_posts[$_board][$board_id]['id'];

				$replacement = '<a ' .
					($_board == $board['uri'] ?
						'onclick="highlightReply(\'' . $id . '\', event);" '
					: '') . 'href="' . $link . '">' .
					'&gt;&gt;&gt;/' . $_board . '/' . $board_id .
					'</a>';

				$body = mb_substr_replace($body, $matches[1][0] . $replacement . $matches[4][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
				$skip_chars += mb_strlen($matches[1][0] . $replacement . $matches[4][0]) - mb_strlen($matches[0][0]);

				if ($track_cites && $config['track_cites'])
					$tracked_cites[] = array($_board, $id);
			} elseif (isset($crossboard_indexes[$_board])) {
				$replacement = '<a href="' . $crossboard_indexes[$_board] . '">' .
					'&gt;&gt;&gt;/' . $_board . '/' .
					'</a>';
				$body = mb_substr_replace($body, $matches[1][0] . $replacement . $matches[4][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
				$skip_chars += mb_strlen($matches[1][0] . $replacement . $matches[4][0]) - mb_strlen($matches[0][0]);
			}
		}
	}

    $tracked_cites = array_unique($tracked_cites, SORT_REGULAR);

    $body = preg_replace("/^\s*&gt;.*$/m", '<span class="quote">$0</span>', $body);

    if ($config['strip_superfluous_returns'])
        $body = preg_replace('/\s+$/', '', $body);

    $body = preg_replace("/\n/", '<br/>', $body);

    // Fix code markup
    if ($config['markup_code']) {
        foreach ($code_markup as $id => $val) {
            $code = isset($val[2]) ? $val[2] : $val[1];
            $code_lang = isset($val[2]) ? $val[1] : "";

            $code = "<pre class='code lang-$code_lang'>".str_replace(array("\n","\t"), array("&#10;","&#9;"), htmlspecialchars($code))."</pre>";

            $body = str_replace("<code $id>", $code, $body);
        }
    }

    if ($config['markup_repair_tidy']) {
        $tidy = new tidy();
        $body = str_replace("\t", '&#09;', $body);
        $body = $tidy->repairString($body, array(
            'doctype' => 'omit',
            'bare' => $config['markup_repair_tidy_bare'],
            'literal-attributes' => true,
            'indent' => false,
            'show-body-only' => true,
            'wrap' => 0,
            'output-bom' => false,
            'output-html' => true,
            'newline' => 'LF',
            'quiet' => true,
        ), 'utf8');
        $body = str_replace("\n", '', $body);
    }

    // replace tabs with 8 spaces
    $body = str_replace("\t", '        ', $body);

    return $tracked_cites;
}

function archive_list_markup(&$body) {

	$body = str_replace("\r", '', $body);
	$body = utf8tohtml($body);

	$body = preg_replace("/^\s*&gt;.*$/m", '<span class="quote">$0</span>', $body);
	// replace tabs with 8 spaces
	$body = str_replace("\t", '		', $body);
}

function escape_markup_modifiers($string) {
	return preg_replace('@<(tinyboard) ([\w\s]+)>@mi', '<$1 escape $2>', $string);
}

function defined_flags_accumulate($desired_flags) {
	global $config;
	$output_flags = 0x0;
	foreach ($desired_flags as $flagname) {
		if (defined($flagname)) {
			$flag = constant($flagname);
			if (gettype($flag) != 'integer')
				error(sprintf($config['error']['flag_wrongtype'], $flagname));
			$output_flags |= $flag;
		} else {
			if ($config['deprecation_errors'])
				error(sprintf($config['error']['flag_undefined'], $flagname));
		}
	}
	return $output_flags;
}

function utf8tohtml($utf8) {
	$flags = defined_flags_accumulate(['ENT_NOQUOTES', 'ENT_SUBSTITUTE', 'ENT_DISALLOWED']);
	return $utf8 ? htmlspecialchars($utf8, $flags, 'UTF-8') : '';
}

function ordutf8($string, &$offset) {
	$code = ord(substr($string, $offset,1));
	if ($code >= 128) { // otherwise 0xxxxxxx
		if ($code < 224)
			$bytesnumber = 2; // 110xxxxx
		else if ($code < 240)
			$bytesnumber = 3; // 1110xxxx
		else if ($code < 248)
			$bytesnumber = 4; // 11110xxx
		$codetemp = $code - 192 - ($bytesnumber > 2 ? 32 : 0) - ($bytesnumber > 3 ? 16 : 0);
		for ($i = 2; $i <= $bytesnumber; $i++) {
			$offset ++;
			$code2 = ord(substr($string, $offset, 1)) - 128; //10xxxxxx
			$codetemp = $codetemp*64 + $code2;
		}
		$code = $codetemp;
	}
	$offset += 1;
	if ($offset >= strlen($string))
		$offset = -1;
	return $code;
}

// Limit Non_Spacing_Mark and Enclosing_Mark characters
function strip_combining_chars($str) {
	global $config;
	$limit = strval($config['max_combining_chars']+1);
	return preg_replace('/(\p{Me}|\p{Mn}){'.$limit.',}/u','', $str);
}

// Helper function to render thread HTML with specified context
function renderThreadHtml($thread, $poll, $id, $isMod = false, $is50 = false) {
    global $board, $config;
    
    $hasnoko50 = $thread->postCount() >= $config['noko50_min'];
    $root = $isMod ? '?/' : $config['root'];
    $boardlist = createBoardlist($isMod);
    $return_url = $isMod ? 
        '?/' . sprintf($config['board_path'], $board['channel'], $board['uri']) . $config['file_index'] :
        $config['root'] . $board['dir'] . $config['file_index'];
    
    $options = [
        'board' => $board,
        'thread' => $thread,
        'poll' => $poll,
        'body' => $is50 ? $thread->build(false, true) : $thread->build(),
        'config' => $config,
        'id' => $id,
        'mod' => $isMod,
        'hasnoko50' => $hasnoko50,
        'isnoko50' => $is50,
        'boardlist' => $boardlist,
        'return' => $return_url
    ];
    
    if ($isMod) {
        $options['pm'] = create_pm_header();
    }
    
    return Element($config['file_thread'], $options);
}

function buildThread($id, $return = false, $mod = false) {
    global $board, $config, $build_pages;

    // If $id is a date string, look up the thread ID for that date
    if (is_string($id) && preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $id)) {
        $query = prepare("SELECT `id` FROM ``posts`` WHERE `board` = :board AND `live_date_path` = :date AND `thread` IS NULL LIMIT 1");
        $query->bindValue(':board', $board['uri']);
        $query->bindValue(':date', $id);
        $query->execute() or error(db_error($query));
        $id = $query->fetchColumn();
        if (!$id) {
            error('No thread found for date ' . htmlspecialchars($id));
        }
    }

    $id = round($id);

    if (event('build-thread', $id))
        return;

    if ($config['cache']['enabled'] && !$mod) {
        cache::delete("thread_index_{$board['uri']}_{$id}");
        cache::delete("thread_{$board['uri']}_{$id}");
    }

    if ($config['try_smarter'] && !$mod)
        $build_pages[] = thread_find_page($id);

    $action = generation_strategy('sb_thread', array($board['uri'], $id));

    if ($action == 'rebuild' || $return || $mod) {
        // Query ONCE, build thread ONCE
        $query = prepare("SELECT * FROM ``posts`` WHERE `board` = :board AND ((`thread` IS NULL AND `id` = :id) OR `thread` = :id) ORDER BY `thread`,`id`");
        $query->bindValue(':board', $board['uri']);
        $query->bindValue(':id', $id, PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        $thread = null;
        $live_date_path = null;
        while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($thread)) {
                // Build thread with public URLs
                $thread = new Thread($post, $config['root'], false);
                $live_date_path = $post['live_date_path'];
            } else {
                $thread->add(new Post($post, $config['root'], false));
            }
        }

        if (!isset($thread))
            error($config['error']['nonexistant']);

        $hasnoko50 = $thread->postCount() >= $config['noko50_min'];
        $poll = get_poll($id);

        // Render public view
        $body = renderThreadHtml($thread, $poll, $id, false, false);

        // Write HTML file using live_date_path (always public view)
        file_write($board['dir'] . $config['dir']['res'] . $live_date_path . '/' . link_for($thread), $body);
        
        // If mod is viewing and wants return value, render mod view (no second query/build needed)
        if ($mod && $return) {
            $body = renderThreadHtml($thread, $poll, $id, true, false);
        }

        // json api
        if ($config['api']['enabled'] && !$mod) {
            $api = new Api(
                $config['show_filename'],
                $config['hide_email'],
                $config['country_flags']
            );
            $json = json_encode($api->translateThread($thread));
            $jsonFilename = $board['dir'] . $config['dir']['res'] . $live_date_path . '/' . $id . '.json';
            file_write($jsonFilename, $json);
        }

        // noko50
        $noko50fn = $board['dir'] . $config['dir']['res'] . $live_date_path . '/' . link_for($thread, true);
        if ($hasnoko50 || file_exists($noko50fn)) {
            buildThread50($id, $return, $mod, $thread, $live_date_path);
        }

        if ($return) {
            return $body;
        }
    }
    elseif($action == 'delete') {
        // Use live_date_path to delete files
        $query = prepare("SELECT `live_date_path` FROM ``posts`` WHERE `board` = :board AND `id` = :id");
        $query->bindValue(':board', $board['uri']);
        $query->bindValue(':id', $id, PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        $live_date_path = $query->fetchColumn();

        $jsonFilename = $board['dir'] . $config['dir']['res'] . $live_date_path . '/' . $id . '.json';
        file_unlink($jsonFilename);

        file_unlink($board['dir'] . $config['dir']['res'] . $live_date_path . '/' . link_for(array('id' => $id), true));
        file_unlink($board['dir'] . $config['dir']['res'] . $live_date_path . '/' . link_for(array('id' => $id)));
    }
}


function buildThread50($id, $return = false, $mod = false, $thread = null, $live_date_path = null) {
    global $board, $config;
    $id = round($id);

    // If thread object is already passed, use it; otherwise query for last N posts
    if (!$thread) {
        $query = prepare("SELECT * FROM ``posts`` WHERE `board` = :board AND ((`thread` IS NULL AND `id` = :id) OR `thread` = :id) ORDER BY `thread`,`id` DESC LIMIT :limit");
        $query->bindValue(':board', $board['uri']);
        $query->bindValue(':id', $id, PDO::PARAM_INT);
        $query->bindValue(':limit', $config['noko50_count']+1, PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        $num_images = 0;
        while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($thread)) {
                $thread = new Thread($post, $config['root'], false);
                $live_date_path = $post['live_date_path'];
            } else {
                if ($post['files'])
                    $num_images += $post['num_files'];
                $thread->add(new Post($post, $config['root'], false));
            }
        }

        if (!isset($thread))
            error($config['error']['nonexistant']);

        if ($query->rowCount() == $config['noko50_count']+1) {
            $count = prepare("SELECT COUNT(`id`) as `num` FROM ``posts`` WHERE `board` = :board AND `thread` = :thread UNION ALL
                          SELECT SUM(`num_files`) FROM ``posts`` WHERE `board` = :board AND `files` IS NOT NULL AND `thread` = :thread");
            $count->bindValue(':board', $board['uri']);
            $count->bindValue(':thread', $id, PDO::PARAM_INT);
            $count->execute() or error(db_error($count));

            $c = $count->fetch();
            $thread->omitted = $c['num'] - $config['noko50_count'];

            $c = $count->fetch();
            $thread->omitted_images = $c['num'] - $num_images;
        }

        $thread->posts = array_reverse($thread->posts);
    } else if (!$live_date_path && isset($thread->posts[0]->live_date_path)) {
        $live_date_path = $thread->posts[0]->live_date_path;
    }

    // Render public noko50 view
    $body = renderThreadHtml($thread, null, $id, false, true);

    // Write noko50 file if not returning (always save public version)
    if (!$return) {
        file_write($board['dir'] . $config['dir']['res'] . $live_date_path . '/' . link_for($thread, true), $body);
    }
    
    // If mod is viewing and wants return value, render mod view (no second query/build needed)
    if ($mod && $return) {
        $body = renderThreadHtml($thread, null, $id, true, true);
    }

    if ($return) {
        return $body;
    }
}

function rrmdir($dir) {
	if (is_dir($dir)) {
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				if (filetype($dir."/".$object) == "dir")
					rrmdir($dir."/".$object);
				else
					file_unlink($dir."/".$object);
			}
		}
		reset($objects);
		rmdir($dir);
	}
}

function poster_id($ip, $thread) {
	global $config;

	if ($id = event('poster-id', $ip, $thread))
		return $id;

	// Confusing, hard to brute-force, but simple algorithm
	return substr(sha1(sha1($ip . $config['secure_trip_salt'] . $thread) . $config['secure_trip_salt']), 0, $config['poster_id_length']);
}

function generate_tripcode($name) {
	global $config;

	if ($trip = event('tripcode', $name))
		return $trip;

	if (!preg_match('/^([^#]+)?(##|#)(.+)$/', $name, $match))
		return array($name);

	$name = $match[1];
	$secure = $match[2] == '##';
	$trip = $match[3];

	// convert to SHIT_JIS encoding
	$trip = mb_convert_encoding($trip, 'Shift_JIS', 'UTF-8');

	// generate salt
	$salt = substr($trip . 'H..', 1, 2);
	$salt = preg_replace('/[^.-z]/', '.', $salt);
	$salt = strtr($salt, ':;<=>?@[\]^_`', 'ABCDEFGabcdef');

	if ($secure) {
		if (isset($config['custom_tripcode']["##{$trip}"]))
			$trip = $config['custom_tripcode']["##{$trip}"];
		else
			$trip = '!!' . substr(crypt($trip, str_replace('+', '.', '_..A.' . substr(base64_encode(sha1($trip . $config['secure_trip_salt'], true)), 0, 4))), -10);
	} else {
		if (isset($config['custom_tripcode']["#{$trip}"]))
			$trip = $config['custom_tripcode']["#{$trip}"];
		else
			$trip = '!' . substr(crypt($trip, $salt), -10);
	}

	return array($name, $trip);
}

function getPostByHash($hash) {
    global $board;
    // Include live_date_path for correct URL generation
    $query = prepare("SELECT `id`, `thread`, `live_date_path` FROM ``posts`` WHERE `board` = :board AND `filehash` = :hash");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':hash', $hash, PDO::PARAM_STR);
    $query->execute() or error(db_error($query));

    if ($post = $query->fetch(PDO::FETCH_ASSOC)) {
        return $post;
    }

    return false;
}

function getPostByHashInThread($hash, $thread) {
    global $board;
    // Include live_date_path for correct URL generation
    $query = prepare("SELECT `id`, `thread`, `live_date_path` FROM ``posts`` WHERE `board` = :board AND `filehash` = :hash AND ( `thread` = :thread OR `id` = :thread )");
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':hash', $hash, PDO::PARAM_STR);
    $query->bindValue(':thread', $thread, PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ($post = $query->fetch(PDO::FETCH_ASSOC)) {
        return $post;
    }

    return false;
}

function undoImage(array $post) {
	if (!$post['has_file'] || !isset($post['files']))
		return;

	foreach ($post['files'] as $key => $file) {
		if (isset($file['file_path']))
			file_unlink($file['file_path']);
		if (isset($file['thumb_path']))
			file_unlink($file['thumb_path']);
	}
}

function rDNS($ip_addr) {
	global $config;

	if ($config['cache']['enabled'] && ($host = cache::get('rdns_' . $ip_addr))) {
		return $host;
	}

	if (!$config['dns_system']) {
		$host = gethostbyaddr($ip_addr);
	} else {
		$resp = shell_exec_error('host -W 3 ' . $ip_addr);
		if (preg_match('/domain name pointer ([^\s]+)$/', $resp, $m))
			$host = $m[1];
		else
			$host = $ip_addr;
	}

	$isip = filter_var($host, FILTER_VALIDATE_IP);

	if ($config['fcrdns'] && !$isip && DNS($host) != $ip_addr) {
		$host = $ip_addr;
	}

	if ($config['cache']['enabled'])
		cache::set('rdns_' . $ip_addr, $host);

	return $host;
}

function DNS($host) {
	global $config;

	if ($config['cache']['enabled'] && ($ip_addr = cache::get('dns_' . $host))) {
		return $ip_addr != '?' ? $ip_addr : false;
	}

	if (!$config['dns_system']) {
		$ip_addr = gethostbyname($host);
		if ($ip_addr == $host)
			$ip_addr = false;
	} else {
		$resp = shell_exec_error('host -W 1 ' . $host);
		if (preg_match('/has address ([^\s]+)$/', $resp, $m))
			$ip_addr = $m[1];
		else
			$ip_addr = false;
	}

	if ($config['cache']['enabled'])
		cache::set('dns_' . $host, $ip_addr !== false ? $ip_addr : '?');

	return $ip_addr;
}

function shell_exec_error($command, $suppress_stdout = false) {
	global $config, $debug;

	if ($config['debug'])
		$start = microtime(true);

	$return = trim(shell_exec('PATH="' . escapeshellcmd($config['shell_path']) . ':$PATH";' .
		$command . ' 2>&1 ' . ($suppress_stdout ? '> /dev/null ' : '') . '&& echo "TB_SUCCESS"'));
	$return = preg_replace('/TB_SUCCESS$/', '', $return);

	if ($config['debug']) {
		$time = microtime(true) - $start;
		$debug['exec'][] = array(
			'command' => $command,
			'time' => '~' . round($time * 1000, 2) . 'ms',
			'response' => $return ? $return : null
		);
		$debug['time']['exec'] += $time;
	}

	return $return === 'TB_SUCCESS' ? false : $return;
}

function slugify($post) {
	global $config;

	$slug = "";

	if (isset($post['subject']) && $post['subject'])
		$slug = $post['subject'];
	elseif (isset ($post['body_nomarkup']) && $post['body_nomarkup'])
		$slug = $post['body_nomarkup'];
	elseif (isset ($post['body']) && $post['body'])
		$slug = strip_tags($post['body']);

	// Fix UTF-8 first
	$slug = mb_convert_encoding($slug, "UTF-8", "UTF-8");

	// Transliterate local characters like ü, I wonder how would it work for weird alphabets :^)
	$slug = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $slug);

	// Remove Tinyboard custom markup
	$slug = preg_replace("/<tinyboard [^>]+>.*?<\/tinyboard>/s", '', $slug);

	// Downcase everything
	$slug = strtolower($slug);

	// Strip bad characters, alphanumerics should suffice
	$slug = preg_replace('/[^a-zA-Z0-9]/', '-', $slug);

	// Replace multiple dashes with single ones
	$slug = preg_replace('/-+/', '-', $slug);

	// Strip dashes at the beginning and at the end
	$slug = preg_replace('/^-|-$/', '', $slug);

	// Slug should be X characters long, at max (80?)
	$slug = substr($slug, 0, $config['slug_max_size']);

	// Slug is now ready
	return $slug;
}

function link_for($post, $page50 = false, $foreignlink = false, $thread = false) {
    global $config, $board;

    $post = (array)$post;

    $b = $foreignlink ? $foreignlink : (isset($post['board']) ? array('uri' => $post['board']) : $board);

    $id = (isset($post['thread']) && $post['thread']) ? $post['thread'] : $post['id'];

    $slug = false;

    if ($config['slugify'] && ( (isset($post['thread']) && $post['thread']) || !isset ($post['slug']) ) ) {
        $cvar = "slug_".$b['uri']."_".$id;
        if (!$thread) {
            $slug = Cache::get($cvar);

            if ($slug === false) {
                $query = prepare("SELECT `slug` FROM ``posts`` WHERE `board` = :board AND `id` = :id");
                $query->bindValue(':board', $b['uri']);
                $query->bindValue(':id', $id, PDO::PARAM_INT);
                $query->execute() or error(db_error($query));

                $thread = $query->fetch(PDO::FETCH_ASSOC);

                $slug = $thread['slug'];

                Cache::set($cvar, $slug);
            }
        }
        else {
            $slug = $thread['slug'];
        }
    }
    elseif ($config['slugify']) {
        $slug = $post['slug'];
    }

    if ( $page50 &&  $slug)  $tpl = $config['file_page50_slug'];
    else if (!$page50 &&  $slug)  $tpl = $config['file_page_slug'];
    else if ( $page50 && !$slug)  $tpl = $config['file_page50'];
    else if (!$page50 && !$slug)  $tpl = $config['file_page'];

    $path = sprintf($tpl, $id, $slug);

    // REMOVE this block:
    // if (isset($post['live_date_path']) && $post['live_date_path']) {
    //     $path = $post['live_date_path'] . '/' . $path;
    // }

    return $path;
}

function prettify_textarea($s){
	return str_replace("\t", '&#09;', str_replace("\n", '&#13;&#10;', htmlentities($s)));
}

function purify_html($s) {
	global $config;

	$c = HTMLPurifier_Config::createDefault();
	$c->set('HTML.Allowed', $config['allowed_html']);
	$uri = $c->getDefinition('URI');
	$uri->addFilter(new HTMLPurifier_URIFilter_NoExternalImages(), $c);
	$purifier = new HTMLPurifier($c);
	$clean_html = $purifier->purify($s);
	return $clean_html;
}

function markdown($s) {
	$pd = new Parsedown();
	$pd->setMarkupEscaped(true);

	return $pd->text($s);
}

function generation_strategy($fun, $array=array()) { global $config;
	$action = false;

	foreach ($config['generation_strategies'] as $s) {
		if ($action = $s($fun, $array)) {
			break;
		}
	}

	switch ($action[0]) {
		case 'immediate':
			return 'rebuild';
		case 'defer':
			// Ok, it gets interesting here :)
			$queue = Queues::get_queue($config, 'generate');
			if ($queue === false) {
				if ($config['syslog']) {
					_syslog(LOG_ERR, "Could not initialize generate queue, falling back to immediate rebuild strategy");
				}
				return 'rebuild';
			}
			$ret = $queue->push(serialize(array('build', $fun, $array, $action)));
			if ($ret === false) {
				if ($config['syslog']) {
					_syslog(LOG_ERR, "Could not push item in the queue, falling back to immediate rebuild strategy");
				}
				return 'rebuild';
			}
			return 'ignore';
		case 'build_on_load':
			return 'delete';
	}
}

function strategy_immediate($fun, $array) {
	return array('immediate');
}

function strategy_smart_build($fun, $array) {
	return array('build_on_load');
}

function strategy_sane($fun, $array) { global $config;
	if (php_sapi_name() == 'cli') return false;
	else if (isset($_POST['mod'])) return false;
	// Thread needs to be done instantly. Same with a board page, but only if posting a new thread.
	else if ($fun == 'sb_thread' || ($fun == 'sb_board' && $array[1] == 1 && isset ($_POST['page']))) return array('immediate');
	else return false;
}

// My first, test strategy.
function strategy_first($fun, $array) {
	switch ($fun) {
	case 'sb_thread':
		return array('defer');
	case 'sb_board':
		if ($array[1] > 8) return array('build_on_load');
		else return array('defer');
	case 'sb_api':
		return array('defer');
	case 'sb_catalog':
		return array('defer');
	case 'sb_recent':
		return array('build_on_load');
	case 'sb_sitemap':
		return array('build_on_load');
	case 'sb_ukko':
		return array('defer');
	}
}

function base32_decode($d) {
	$charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$d = str_split($d);
	$l = array_pop($d);
	$b = '';
	foreach ($d as $c) {
		$b .= sprintf("%05b", strpos($charset, $c));
	}
	$padding = 8 - strlen($b) % 8;
	$b .= str_pad(decbin(strpos($charset, $l)), $padding, '0', STR_PAD_LEFT);

	return implode('', array_map(function($c) { return chr(bindec($c)); }, str_split($b, 8)));
}

function base32_encode($d) {
	$charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$b = implode('', array_map(function($c) { return sprintf("%08b", ord($c)); }, str_split($d)));
	return implode('', array_map(function($c) use ($charset) { return $charset[bindec($c)]; }, str_split($b, 5)));
}

function cloak_ip($ip) {
	global $config;
	$ipcrypt_key = $config['ipcrypt_key'] ?: null;

	if (empty($ipcrypt_key))
		return $ip;

	$ip_dec = inet_pton($ip);

	if ($config['ipcrypt_dns']) {
		$host = gethostbyaddr($ip);

		if ($host !== $ip) {
			$segments = explode('.', $host);

			$tld = [];
			$tld[] = array_pop($segments);
			if (count($segments) >= 2) {
				$tld[] = array_pop($segments);
			}

			$tld = implode('.', array_reverse($tld));
		}
	}

	if (is_numeric($ip))
		$ipbytes = pack('N', $ip);
	else if ($ip_dec !== false)
		$ipbytes = $ip_dec;
	else
		return "#ERROR";

	if (strlen($ipbytes) >= 16)
		$ipbytes = substr($ipbytes, 0, 16);

	$cyphertext = openssl_encrypt($ipbytes, 'aes-256-ctr', $ipcrypt_key, OPENSSL_RAW_DATA);

	$ret = $config['ipcrypt_prefix'].':' . base32_encode($cyphertext);
	if (isset($tld) && !empty($tld)) {
		$ret .= '.'.$tld;
	}

	return $ret;
}

function uncloak_ip($ip) {
	global $config;
	$ipcrypt_key = ($config['ipcrypt_key']);

	if (empty($ipcrypt_key))
		return $ip;

	$juice = substr($ip, strlen($config['ipcrypt_prefix']) + 1);
	if ($delimiter = strpos($juice, '.')) {
		$juice = substr($juice, 0, $delimiter);
	}

	if (substr($ip, 0, strlen($config['ipcrypt_prefix']) + 1) === $config['ipcrypt_prefix'].':') {
		$plaintext = openssl_decrypt(base32_decode($juice), 'aes-256-ctr', $ipcrypt_key, OPENSSL_RAW_DATA);

		if ($plaintext === false || strlen($plaintext) == 0)
			return '#ERROR';

		if (strlen($ip) >= 16)
			return inet_ntop($plaintext);
		else
			return long2ip(unpack('N', $plaintext)[1]);
	}

	return '#ERROR';
}

function cloak_mask($mask) {
	list($net, $block) = array_pad(explode('/', $mask, 2), 2, null);
	$mask = cloak_ip($net);
	if ($block) {
		$mask .= '/'.$block;
	}

	return $mask;
}

function uncloak_mask($mask) {
	list($addr, $block) = array_pad(explode('/', $mask, 2), 2, null);
	$mask = uncloak_ip($addr);
	if ($mask === '#ERROR') {
		$mask = $addr;
	}
	if ($block) {
		$mask .= '/'.$block;
	}

	return $mask;
}

function check_thread_limit($post) {
    global $config, $board;
    if (!isset($config['max_threads_per_hour']) || !$config['max_threads_per_hour']) return false;

    if ($post['op']) {
        $query = prepare('SELECT COUNT(*) AS `count` FROM ``posts`` WHERE `board` = :board AND `thread` IS NULL AND FROM_UNIXTIME(`time`) > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $query->bindValue(':board', $board['uri']);
        $query->execute() or error(db_error($query));
        $r = $query->fetch(PDO::FETCH_ASSOC);

        return $r['count'] >= $config['max_threads_per_hour'];
    }
}

function hashPassword($password) {
	global $config;

	return hash('sha3-256', $password . $config['secure_password_salt']);
}

// Thanks to https://gist.github.com/marijn/3901938
function trace_url($url) {
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_FOLLOWLOCATION => TRUE,  // the magic sauce
		CURLOPT_RETURNTRANSFER => TRUE,
		CURLOPT_SSL_VERIFYHOST => FALSE, // suppress certain SSL errors
		CURLOPT_SSL_VERIFYPEER => FALSE,
		CURLOPT_TIMEOUT => 30,
	));
	curl_exec($ch);
	$url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
	curl_close($ch);
	return $url;
}

// Thanks to https://stackoverflow.com/questions/10002227/linkify-regex-function-php-daring-fireball-method/10002262#10002262
function get_urls($body) {
	$regex = '(?xi)\b((?:https?://|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]{2,4}/)(?:[^\s()<>]+|\(([^\s()<>]+|(\([^\s()<>]+\)))*\))+(?:\(([^\s()<>]+|(\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:\'".,<>?«»“”‘’]))';

	$result = preg_match_all("#$regex#i", $body, $match);

	return $match[0];
}

// Returns hashed version of IP address
function get_ip_hash($ip)
{
	global $config;
	static $ip_hash;

	if (!$config['bcrypt_ip_addresses'])
		return $ip;
	if (isset($ip_hash[$ip]))
		return $ip_hash[$ip];

	// Generate BCrypt Hash and remove $2a$[cost]$[salt_22_char]$ header info - leaving 31 char hash
	$hash = crypt($ip, "$2y$" . $config['bcrypt_ip_cost'] . "$" . $config['bcrypt_ip_salt'] . "$");
	$hash = str_replace("/", "_", substr($hash, 29));
	$ip_hash[$ip] = $hash;

	return $hash;
}

function ids_from_postdata($post, $prefix='delete') {
	$ids = array();
	foreach ($_POST as $post => $value) {
		if (preg_match('/^'.$prefix.'_(\d+)$/', $post, $m)) {
			$ids[] = (int)$m[1];
		}
	}
	return array_unique($ids);
}

/**
 * Create a new poll for a thread.
 */
function create_poll($thread_id, $question, $options, $max_votes = 1, $expires = null) {
    global $pdo;
    // Insert poll
    $stmt = $pdo->prepare("INSERT INTO polls (thread_id, question, max_votes, expires) VALUES (?, ?, ?, ?)");
    $stmt->execute([$thread_id, $question, $max_votes, $expires]);
    $poll_id = $pdo->lastInsertId();

    // Insert options
    $opt_stmt = $pdo->prepare("INSERT INTO poll_options (poll_id, option_text) VALUES (?, ?)");
    foreach ($options as $opt) {
        $opt_stmt->execute([$poll_id, trim($opt)]);
    }
    return $poll_id;
}

/**
 * Record a vote.
 */
function vote_poll($poll_id, $option_id) {
    global $pdo;

    $ip_bin = inet_pton($_SERVER['REMOTE_ADDR']);
    $now = time();

    $poll_stmt = $pdo->prepare("SELECT expires, max_votes FROM polls WHERE id = ?");
    $poll_stmt->execute([$poll_id]);
    $poll = $poll_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$poll) return false;

    // Check expiration
    if (!empty($poll['expires']) && $now > (int)$poll['expires']) {
        return 'closed';
    }

    // Check votes already cast by this IP
    $check = $pdo->prepare("SELECT COUNT(*) FROM poll_votes WHERE poll_id = ? AND ip = ?");
    $check->execute([$poll_id, $ip_bin]);
    $already_voted = (int)$check->fetchColumn();

    if ($already_voted > 0) {
        return 'duplicate';
    }

    if ($poll['max_votes'] > 0 && $already_voted >= $poll['max_votes']) {
        return 'limit';
    }

    // Record vote, catch duplicate entry just in case
    try {
        $ins = $pdo->prepare("INSERT INTO poll_votes (poll_id, option_id, ip, vote_time) VALUES (?, ?, ?, ?)");
        $ins->execute([$poll_id, $option_id, $ip_bin, $now]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Integrity constraint violation
            return 'duplicate';
        }
        throw $e;
    }

    $upd = $pdo->prepare("UPDATE poll_options SET votes = votes + 1 WHERE id = ?");
    $upd->execute([$option_id]);

    return true;
}

/**
 * Fetch poll and options for display.
 */
function get_poll($thread_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM polls WHERE thread_id = ?");
    $stmt->execute([$thread_id]);
    $poll = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$poll) return null;
    $opts = $pdo->prepare("SELECT * FROM poll_options WHERE poll_id = ?");
    $opts->execute([$poll['id']]);
    $poll['options'] = $opts->fetchAll(PDO::FETCH_ASSOC);
    return $poll;
}
