<?php
// theme.php — Catalog theme with pagination and working thumbnails

require 'info.php';

/** Fetch all board URIs from the database. */
function get_all_boards() {
    $boards = [];
    $query = query("SELECT uri FROM ``boards``") or error(db_error());
    while ($b = $query->fetch(PDO::FETCH_ASSOC)) {
        $boards[] = $b['uri'];
    }
    return $boards;
}

/**
 * Entry point for rebuilding the catalog.
 *
 * @param string     $action   'all', 'post-thread', 'post', etc.
 * @param array      $settings Theme settings.
 * @param string|bool $board   Current board URI (or false).
 * @param int|null   $batch_page_start  (optional) Start page for batch.
 * @param int|null   $batch_page_end    (optional) End page for batch.
 */
function catalog_build($action, $settings, $board, $batch_page_start = null, $batch_page_end = null) {
    global $config, $build_pages;

    $boards = explode(' ', $settings['boards']);
    if (in_array('*', $boards)) {
        $boards = get_all_boards();
    }

    $build_board = function($bname) use ($settings, $batch_page_start, $batch_page_end) {
        $cat = new Catalog();
        $strategy = generation_strategy("sb_catalog", [$bname]);

        if ($strategy === 'delete') {
            @unlink($GLOBALS['config']['dir']['home'] . $bname . '/catalog.html');
            @unlink($GLOBALS['config']['dir']['home'] . $bname . '/index.rss');
        } elseif ($strategy === 'rebuild') {
            $cat->build($settings, $bname, false, $batch_page_start, $batch_page_end);
        }
    };

    // ✅ SMART BUILD LOGIC FOR CATALOG
    if ($config['try_smarter'] && isset($build_pages) && !empty($build_pages)) {
        $batch_page_start = min($build_pages);
        $batch_page_end = max($build_pages);
    }

    if ($action === 'all') {
        foreach ($boards as $bname) {
            $build_board($bname);
        }
    } elseif (
        $action === 'post-thread'
        || ($settings['update_on_posts'] && in_array($action, ['post', 'post-delete']))
    ) {
        if ($board && in_array($board, $boards)) {
            $build_board($board);
        }
    }
}

class Catalog {
    /**
     * Build the catalog HTML (paginated).
     *
     * @param array  $settings   Theme settings.
     * @param string $board_name Board URI.
     * @param bool   $mod        Moderator preview?
     * @param int|null $batch_page_start  (optional) Start page for batch.
     * @param int|null $batch_page_end    (optional) End page for batch.
     * @return string|null       HTML for mod, null when writing files.
     */
    public function build($settings, $board_name, $mod = false, $batch_page_start = null, $batch_page_end = null) {
        global $config, $board, $build_pages;

        // Ensure correct board context
        if (!isset($board) || $board['uri'] !== $board_name) {
            if (!openBoard($board_name)) {
                error(sprintf(_("Board %s doesn't exist"), $board_name));
            }
        }

        $recent_posts = [];
        $stats        = [];

        // ─── FETCH THREADS ───────────────────────────────────────────────────────
        $sql = "
            SELECT
              *,
              `id` AS `thread_id`,
              (SELECT COUNT(`id`) FROM `posts` WHERE `board` = :board AND `thread` = `thread_id`) AS `reply_count`,
              (SELECT SUM(`num_files`) FROM `posts` WHERE `board` = :board AND `thread` = `thread_id` AND `num_files` IS NOT NULL) AS `image_count`,
              :board AS `board`
            FROM `posts`
            WHERE `board` = :board AND `thread` IS NULL
            ORDER BY `bump` DESC
        ";
        $query = prepare($sql);
        $query->bindValue(':board', $board_name);
        $query->execute() or error(db_error());

        while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
            $post_date_path = isset($post['live_date_path']) && $post['live_date_path'] ? $post['live_date_path'] . '/' : '';
            if ($mod) {
                $post['link'] = $config['root']
                              . $config['file_mod'] . '?/'
                              . $board['dir'] . $config['dir']['res']
                              . $post_date_path
                              . link_for($post);
            } else {
                $post['link'] = $config['root']
                              . $board['dir'] . $config['dir']['res']
                              . $post_date_path
                              . link_for($post);
            }

            if (!empty($post['embed'])
                && preg_match(
                    '/^https?:\/\/(\w+\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([A-Za-z0-9\-_]{10,11})/',
                    $post['embed'], $m
                )
            ) {
                $post['youtube'] = $m[2];
            }

            if (!empty($post['files'])) {
                $files = json_decode($post['files']);
                $thumb = $files[0];
                if ($thumb->file === 'deleted') {
                    foreach ($files as $f) {
                        if ($f->file !== 'deleted') {
                            $thumb = $f;
                            break;
                        }
                    }
                    if ($thumb->file === 'deleted') {
                        $post['file'] = $config['image_deleted'];
                    } else {
                        $post['file'] = $config['uri_thumb'] . $thumb->thumb;
                    }
                }
                elseif ($thumb->thumb === 'spoiler') {
                    $post['file'] = $config['root'] . $config['spoiler_image'];
                }
                elseif ($thumb->thumb === 'file') {
                    $file_icon = $config['file_icons'][$files[0]->extension] ?? $config['file_icons']['default'];
                    $file_icon_thumb = sprintf($config['file_thumb'], $file_icon);
                    $post['file'] = $config['root'] . $file_icon_thumb;
                }
                else {
                    $post['file'] = $config['uri_thumb'] . $thumb->thumb;
                }
            } else {
                $post['file'] = $config['root'] . $config['no_image'];
            }

            $post['image_count'] = $post['image_count'] ?? 0;
            $post['pubdate']     = date('r', $post['time']);

            $recent_posts[] = $post;
        }

        // ─── INCLUDE JS ────────────────────────────────────────────────────────
        foreach (['js/jquery.min.js','js/jquery.mixitup.min.js','js/catalog.js','js/catalog-search.js'] as $js) {
            if (!in_array($js, $config['additional_javascript'])) {
                $config['additional_javascript'][] = $js;
            }
        }

        $base_link = $mod
                   ? $config['root'] . $config['file_mod'] . '?/' . $board['dir']
                   : $config['root'] . $board['dir'];

        // ─── PAGINATION ─────────────────────────────────────────────────────────
        $per_page = isset($settings['items_per_page']) && (int)$settings['items_per_page'] > 0
            ? (int)$settings['items_per_page']
            : 15;
        $total = count($recent_posts);
        $total_pages = $per_page > 0 ? (int)ceil($total / $per_page) : 1;

        // Determine batch range
        $start_page = $batch_page_start !== null ? max(1, (int)$batch_page_start) : 1;
        $end_page = $batch_page_end !== null ? min($total_pages, (int)$batch_page_end) : $total_pages;

        // SMART BUILD: Only build pages in $build_pages if set
        $pages_to_build = range($start_page, $end_page);
        if ($config['try_smarter'] && isset($build_pages) && !empty($build_pages)) {
            $pages_to_build = array_intersect($pages_to_build, $build_pages);
            if (empty($pages_to_build)) return null;
        }

        foreach ($pages_to_build as $page) {
            $slice = array_slice($recent_posts, ($page-1)*$per_page, $per_page);

            $html = Element('themes/catalog/catalog.html', [
                'settings'     => $settings,
                'config'       => $config,
                'boardlist'    => createBoardlist($mod),
                'recent_posts' => $slice,
                'stats'        => $stats,
                'board'        => $board_name,
                'link'         => $base_link,
                'mod'          => $mod,
                'current_page' => $page,
                'total_pages'  => $total_pages,
            ]);

            if ($mod) {
                if ($page === 1) {
                    return $html;
                }
            } else {
                if ($page === 1) {
                    $filename = 'catalog.html';
                    $filepath = $config['dir']['home'] . $board['dir'] . '/' . $filename;
                } else {
                    $folder_num = intval(($page - 2) / 1000) + 1;
                    $folder_path = $config['dir']['home'] . $board['dir'] . '/pagination/' . $folder_num;
                    if (!is_dir($folder_path)) {
                        @mkdir($folder_path, 0777, true);
                    }
                    $filename = "catalog_page_{$page}.html";
                    $filepath = $folder_path . '/' . $filename;
                }
                file_write($filepath, $html);
            }
        }

        return null;
    }
}