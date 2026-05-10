<?php
/*
 *  Copyright (c) 2010-2014 Tinyboard Development Group
 */

require_once 'inc/bootstrap.php';

if ($config['debug']) {
    $parse_start_time = microtime(true);
}

require_once 'inc/mod/pages.php';

$ctx = Vichan\build_context($config);

check_login($ctx, true);

$query = isset($_SERVER['QUERY_STRING']) ? rawurldecode($_SERVER['QUERY_STRING']) : '';

$pages = [
    '' => ':?/', // redirect to dashboard
    '/' => 'dashboard', // dashboard
    '/dashboard/(\d+)' => 'dashboard', // dashboard paginated
    '/dashboard/(\d+)/own/(\d+)' => 'dashboard',
    '/search_boards' => 'search_boards',
    '/confirm/(.+)' => 'confirm', // confirm action (if javascript didn't work)
    '/logout' => 'secure logout', // logout

    '/users' => 'users', // manage users
    'mode=users' => 'secure_POST users',
    '/users/(\d+)/(promote|demote)' => 'secure user_promote', // promote/demote user
    '/users/(\d+)' => 'secure_POST user', // edit user
    '/users/new' => 'secure_POST user_new', // create a new user

    '/new_PM/([^/]+)' => 'secure_POST new_pm', // create a new pm
    '/PM/(\d+)(/reply)?' => 'pm', // read a pm
    '/inbox' => 'inbox', // pm inbox
    '/inbox/(\d+)' => 'inbox', // pm inbox with page number

    '/log' => 'log', // modlog
    '/log/(\d+)' => 'log', // modlog
    '/log:([^/:]+)' => 'user_log', // modlog
    '/log:([^/:]+)/(\d+)' => 'user_log', // modlog
    '/log:b:([^/]+)' => 'board_log', // modlog
    '/log:b:([^/]+)/(\d+)' => 'board_log', // modlog

    '/edit_news' => 'secure_POST news', // view news
    '/edit_news/(\d+)' => 'secure_POST news', // view news
    '/edit_news/delete/(\d+)' => 'secure news_delete', // delete from news

    '/edit_pages' => 'secure_POST pages',
    '/edit_page/(\d+)' => 'secure_POST edit_page',
    '/edit_pages/delete/([a-z0-9]+)' => 'secure delete_page',
    '/edit_pages/delete/([a-z0-9]+)/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)' => 'secure delete_page_board',
	'/edit_pages/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)' => 'secure_POST pages',

    '/noticeboard' => 'secure_POST noticeboard', // view noticeboard
    '/noticeboard/(\d+)' => 'secure_POST noticeboard', // view noticeboard
    '/noticeboard/delete/(\d+)' => 'secure noticeboard_delete', // delete from noticeboard

    '/edit/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/?' => 'secure_POST edit_board', // edit board details
    '/new-board' => 'secure_POST new_board', // create a new board

    '/rebuild' => 'secure_POST rebuild', // rebuild static files
    '/rebuild/(\d+)' => 'rebuild',
    '/rebuild_search' => 'rebuild',
    'mode=rebuild' => 'secure_POST rebuild',
    '/reports' => 'reports', // report queue
    '/reports/(\d+)' => 'reports', // reports with page number
    '/reports/(\d+)/dismiss(&all|&post)?' => 'secure report_dismiss', // dismiss a report

    '/IP/([\w.:]+)' => 'secure_POST ip', // view ip address
    '/IP/([\w.:]+)/remove_note/(\d+)' => 'secure ip_remove_note', // remove note from ip address

    '/ban' => 'secure_POST ban', // new ban
    '/bans' => 'secure_POST bans', // ban list
    '/bans.json' => 'secure bans_json', // ban list JSON
    '/edit_ban/(\d+)' => 'secure_POST edit_ban',
    '/ban-appeals' => 'secure_POST ban_appeals', // view ban appeals

    '/recent/(\d+)' => 'recent_posts', // view recent posts

    '/search' => 'search_redirect', // search
    '/search/(posts|IP_notes|bans|log)/(.+)/(\d+)' => 'search', // search
    '/search/(posts|IP_notes|bans|log)/(.+)' => 'search', // search

    // Archive listing (page 1 and paginated)
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/archive/?' => 'secure_POST view_archive',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/archive/(\d+)' => 'secure_POST view_archive',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/archive/pagination/(\d+)/(\d+)\.html' => 'secure_POST view_archive',

    // Mod archive (page 1 and paginated)
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/mod_archive/?' => 'secure_POST view_archive_mod_archive',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/mod_archive/(\d+)' => 'secure_POST view_archive_mod_archive',

    // Featured archive (page 1 and paginated)
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/featured/?' => 'secure_POST view_archive_featured',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/featured/(\d+)' => 'secure_POST view_archive_featured',
    '/manage_mods/([a-z0-9_]+)/?' => 'secure_POST manage_mods',

    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/ban(&delete)?/(\d+)' => 'secure_POST ban_post', // ban poster
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/move/(\d+)' => 'secure_POST move', // move thread
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/move_reply/(\d+)' => 'secure_POST move_reply', // move reply
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/edit(_raw)?/(\d+)' => 'secure_POST edit_post', // edit post
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/delete/(\d+)' => 'secure delete', // delete post
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/deletefile/(\d+)/(\d+)' => 'secure deletefile', // delete file from post
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/spoiler/(\d+)/(\d+)' => 'secure spoiler_image', // spoiler file
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/deletebyip/(\d+)(/global)?' => 'secure deletebyip', // delete all posts by IP address
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/(un)?lock/(\d+)' => 'secure lock', // lock thread
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/(un)?sticky/(\d+)' => 'secure sticky', // sticky thread
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/(un)?cycle/(\d+)' => 'secure cycle', // cycle thread
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/bump(un)?lock/(\d+)' => 'secure bumplock', // "bumplock" thread
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/archive_thread/(\d+)' => 'secure archive_thread', // send thread to archive

    '/themes' => 'themes_list', // manage themes
    '/themes/(\w+)' => 'secure_POST theme_configure', // configure/reconfigure theme
    '/themes/(\w+)/rebuild' => 'secure theme_rebuild', // rebuild theme
    '/themes/(\w+)/uninstall' => 'secure theme_uninstall', // uninstall theme

	'/config/channel' => 'secure_POST config', // config editor
    '/config/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)' => 'secure_POST config', // config editor
    '/board_suggestions' => 'secure_POST board_suggestions',

    // Date-based thread URLs
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/' . preg_quote($config['dir']['res'], '!') . '([0-9]{4}/[0-9]{2}/[0-9]{2})/' .
        str_replace('%d', '(\d+)', preg_quote($config['file_page'], '!')) => 'view_thread',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/' . preg_quote($config['dir']['res'], '!') . '([0-9]{4}/[0-9]{2}/[0-9]{2})/' .
        str_replace(['%d', '%s'], ['(\d+)', '[a-z0-9-]+'], preg_quote($config['file_page_slug'], '!')) => 'view_thread',
    // Board index pagination
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/pagination/(\d+)/(\d+)\.html' => 'view_board',
    // Catalog pagination pages
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/pagination/(\d+)/catalog_page_(\d+)\.html' => 'view_catalog_page',
    // Board index and catalog
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/' => 'view_board',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/' . preg_quote($config['file_index'], '!') => 'view_board',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/' . preg_quote($config['file_catalog'], '!') => 'view_catalog',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/' . str_replace('%d', '(\d+)', preg_quote($config['file_page'], '!')) => 'view_board',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/' . preg_quote($config['dir']['res'], '!') .
        str_replace('%d', '(\d+)', preg_quote($config['file_page50'], '!')) => 'view_thread50',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/' . preg_quote($config['dir']['res'], '!') .
        str_replace('%d', '(\d+)', preg_quote($config['file_page'], '!')) => 'view_thread',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/' . preg_quote($config['dir']['res'], '!') .
        str_replace(['%d', '%s'], ['(\d+)', '[a-z0-9-]+'], preg_quote($config['file_page50_slug'], '!')) => 'view_thread50',
    '/channel/(\d+)/([a-zA-Z0-9$_\\x{0080}-\\x{10FFFF}]+)/' . preg_quote($config['dir']['res'], '!') .
        str_replace(['%d', '%s'], ['(\d+)', '[a-z0-9-]+'], preg_quote($config['file_page_slug'], '!')) => 'view_thread',
];

if (!$mod) {
    $pages = [
        '!^register(?:&[^&=]+=[^&]*)*$!u' => 'register',
        '!^(.+)?$!' => 'login'
    ];
} elseif (isset($_GET['status'], $_GET['r'])) {
    header('Location: ' . $_GET['r'], true, (int)$_GET['status']);
    exit;
}

if (isset($config['mod']['custom_pages'])) {
    $pages = array_merge($pages, $config['mod']['custom_pages']);
}

$new_pages = [];
foreach ($pages as $key => $callback) {
    if (is_string($callback) && preg_match('/^secure /', $callback)) {
        $key .= '(/(?P<token>[a-f0-9]{8}))?';
    }
    $new_pages[(!empty($key) && $key[0] == '!') ? $key : '!^' . $key . '(?:&[^&=]+=[^&]*)*$!u'] = $callback;
}
$pages = $new_pages;

foreach ($pages as $uri => $handler) {
    if (!preg_match($uri, $query, $matches)) {
        continue;
    }

    // Debug: Log the matches and handler
    if ($config['debug']) {
        error_log("Matched URI: $uri, Handler: $handler, Matches: " . print_r($matches, true));
    }

    // CSRF/secure handler check
    if (is_string($handler) && preg_match('/^secure(_POST)? /', $handler, $m)) {
        $secure_post_only = isset($m[1]);
        if (!$secure_post_only || $_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $matches['token'] ?? ($_POST['token'] ?? false);
            if ($token === false) {
                if ($secure_post_only) {
                    error($config['error']['csrf']);
                } else {
                    mod_confirm($ctx, ltrim($query, '/'));
                    exit;
                }
            }
            $actual_query = preg_replace('!/([a-f0-9]{8})$!', '', $query);
            if ($token != make_secure_link_token(ltrim($actual_query, '/'))) {
                error($config['error']['csrf']);
            }
        }
        $handler = preg_replace('/^secure(_POST)? /', '', $handler);
    }

    // Debug: Store handler info
    if ($config['debug']) {
        $debug['mod_page'] = [
            'req' => $query,
            'match' => $uri,
            'handler' => $handler,
        ];
        $debug['time']['parse_mod_req'] = '~' . round((microtime(true) - $parse_start_time) * 1000, 2) . 'ms';
    }

    // Prepare arguments: always pass $ctx as first argument
    $args = array_values($matches);
    $args[0] = $ctx;

    // Special case: view_board with 4+ params (for pagination)
    if ($handler === 'view_board' && count($args) >= 5) {
        mod_view_board($ctx, $args[1], $args[2], $args[3], $args[4]);
        exit;
    }

    // Handler dispatch
    if (is_string($handler)) {
        if ($handler[0] === ':') {
            header('Location: ' . substr($handler, 1), true, $config['redirect_http']);
        } elseif (is_callable("mod_$handler")) {
            call_user_func_array("mod_$handler", $args);
        } else {
            error("Mod page '$handler' not found!");
        }
    } elseif (is_callable($handler)) {
        call_user_func_array($handler, $args);
    } else {
        error("Mod page '$handler' not a string, and not callable!");
    }
    exit;
}

error($config['error']['404']);