<?php

class Archive {

    static public function archiveThread($thread_id) {
        global $config, $board;

        if(!$config['archive']['threads'])
            return;

        // Fetch thread data, including live_date_path
        $thread_query = prepare("SELECT `thread`, `subject`, `body_nomarkup`, `trip`, `board_id`, `live_date_path` FROM ``posts`` WHERE `board` = :board AND `id` = :id");
        $thread_query->bindValue(':board', $board['uri']);
        $thread_query->bindValue(':id', $thread_id, PDO::PARAM_INT);
        $thread_query->execute() or error(db_error($thread_query));
        $thread_data = $thread_query->fetch(PDO::FETCH_ASSOC);

        if($thread_data['thread'] !== NULL)
            error($config['error']['invalidpost']);

        $thread_data['snippet_body'] = strtok($thread_data['body_nomarkup'], "\r\n");
        $thread_data['snippet_body'] = substr($thread_data['snippet_body'], 0, $config['archive']['snippet_len'] - strlen($thread_data['subject']));
        archive_list_markup($thread_data['snippet_body']);
        $thread_data['snippet'] = '<b>' . $thread_data['subject'] . '</b> ';
        $thread_data['snippet'] .= $thread_data['snippet_body'];

        // Use the thread's live_date_path for the archive path
        $date_path = $thread_data['live_date_path'];
        $archive_res_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['res'] . $date_path . '/';
        $archive_img_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['img'] . $date_path . '/';
        $archive_thumb_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['thumb'] . $date_path . '/';

        @mkdir($archive_res_path, 0777, true);
        @mkdir($archive_img_path, 0777, true);
        @mkdir($archive_thumb_path, 0777, true);

        // Fetch all posts in the thread, including live_date_path
        $query = prepare("SELECT `id`,`thread`,`files`,`slug`,`live_date_path` FROM ``posts`` WHERE `board` = :board AND (`id` = :id OR `thread` = :id)");
        $query->bindValue(':board', $board['uri']);
        $query->bindValue(':id', $thread_id, PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        $file_list = array();

        while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
            $post_date_path = $post['live_date_path'];
            $post_archive_res_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['res'] . $post_date_path . '/';
            $post_archive_img_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['img'] . $post_date_path . '/';
            $post_archive_thumb_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['thumb'] . $post_date_path . '/';

            @mkdir($post_archive_res_path, 0777, true);
            @mkdir($post_archive_img_path, 0777, true);
            @mkdir($post_archive_thumb_path, 0777, true);

            if (!$post['thread']) {
                $thread_file_content = @file_get_contents($board['dir'] . $config['dir']['res'] . $post_date_path . '/' . link_for($post));

                // Fix image and thumb URLs for archive
                $thread_file_content = str_replace(
                    '/' . $board['dir'] . $config['dir']['img'],
                    '/' . $board['dir'] . $config['dir']['archive'] . $config['dir']['img'],
                    $thread_file_content
                );
                $thread_file_content = str_replace(
                    '/' . $board['dir'] . $config['dir']['thumb'],
                    '/' . $board['dir'] . $config['dir']['archive'] . $config['dir']['thumb'],
                    $thread_file_content
                );

                // Fix HTML page links (thread/post links)
                $thread_file_content = str_replace(
                    '/' . $board['dir'] . $config['dir']['res'],
                    '/' . $board['dir'] . $config['dir']['archive'] . $config['dir']['res'],
                    $thread_file_content
                );

                $thread_file_content = str_replace('Posting mode: Reply', 'Archived thread', $thread_file_content);
                $thread_file_content = preg_replace("/<form name=\"post\"(.*?)<\/form>/i", "", $thread_file_content);

                $thread_file_content = str_replace(
                    sprintf($config['board_path'] . $config['dir']['archive'] . $config['dir']['archive'], $board['channel'], $board['uri']),
                    sprintf('href="/' . $config['board_path'] . $config['dir']['archive'], $board['channel'], $board['uri']),
                    $thread_file_content
                );

                $thread_file_content = preg_replace("/<form(.*?)>/i", "", $thread_file_content);
                $thread_file_content = preg_replace("/<\/form>/i", "", $thread_file_content);
                $thread_file_content = preg_replace("/<input (.*?)>/i", "", $thread_file_content);

                $thread_file_content = preg_replace("/<div id=\"report\-fields\"(.*?)<\/div>/i", "", $thread_file_content);
                $thread_file_content = preg_replace("/<div id=\"thread\-interactions\"(.*?)<\/div>/i", "", $thread_file_content);
                $thread_file_content = preg_replace("/<a id=\"unimportant\" href=\"\/[a-zA-Z0-9]+\/archive\/catalog(.*?)<\/a>/i", "", $thread_file_content);
                $thread_file_content = preg_replace("/\b\/(archive)(\/featured\/)/i", "$2", $thread_file_content);

                @file_put_contents($post_archive_res_path . sprintf($config['file_page'], $thread_id), $thread_file_content, LOCK_EX);
            }

            $json_file_content = @file_get_contents($board['dir'] . $config['dir']['res'] . $post_date_path . '/' . sprintf('%d.json', $thread_id));
            if ($json_file_content !== false) {
                $json_file_content = str_replace(
                    substr($board['dir'], 0, -1) . '\/' . substr($config['dir']['res'], 0, -1),
                    substr($board['dir'], 0, -1) . '\/' . substr($config['dir']['archive'], 0, -1) . '\/' . substr($config['dir']['res'], 0, -1),
                    $json_file_content
                );
                @file_put_contents($post_archive_res_path . sprintf('%d.json', $thread_id), $json_file_content, LOCK_EX);
            }

            if ($post['files']) {
                foreach (json_decode($post['files']) as $i => $f) {
                    if ($f->file !== 'deleted') {
                        // Only store the filename, not the path
                        $f->file = basename($f->file);
                        $f->thumb = basename($f->thumb);

                        @copy($board['dir'] . $config['dir']['img'] . $post_date_path . '/' . $f->file, $post_archive_img_path . $f->file);
                        @copy($board['dir'] . $config['dir']['thumb'] . $post_date_path . '/' . $f->thumb, $post_archive_thumb_path . $f->thumb);

                        @unlink($board['dir'] . $config['dir']['img'] . $post_date_path . '/' . $f->file);
                        @unlink($board['dir'] . $config['dir']['thumb'] . $post_date_path . '/' . $f->thumb);

                        $file_list[] = $f;
                    }
                }
            }
        }
        $first_image = null;
        foreach ($file_list as $file) {
            if (isset($file->thumb) && $file->thumb !== 'deleted') {
                $first_image = $file->thumb;
                break;
            }
        }

        $query = prepare("INSERT INTO `archive_threads` (`board_uri`, `original_thread_id`, `board_id`, `snippet`, `lifetime`, `files`, `featured`, `mod_archived`, `votes`, `path`, `first_image`) VALUES (:board_uri, :original_thread_id, :board_id, :snippet, :lifetime, :files, 0, 0, 0, :path, :first_image)");
        $query->bindValue(':board_uri', $board['uri'], PDO::PARAM_STR);
        $query->bindValue(':original_thread_id', $thread_id, PDO::PARAM_INT);
        $query->bindValue(':board_id', $thread_data['board_id'], PDO::PARAM_INT);
        $query->bindValue(':snippet', $thread_data['snippet'], PDO::PARAM_STR);
        $query->bindValue(':lifetime', time(), PDO::PARAM_INT);
        $query->bindValue(':files', json_encode($file_list));
        $query->bindValue(':path', $date_path, PDO::PARAM_STR);
        $query->bindValue(':first_image', $first_image, PDO::PARAM_STR);
        $query->execute() or error(db_error($query));

        global $pdo;
        $archive_id = $pdo->lastInsertId();

        if(in_array($thread_data['trip'], $config['archive']['auto_feature_trips']))
            self::featureThread($archive_id, $board['uri']);

        if(!$config['archive']['cron_job']['purge'])
            self::purgeArchive($board['uri']);

        self::buildArchiveIndex($board['uri']);

        return true;
    }

    static public function purgeArchive($board_uri) {
        global $config, $board;

        if(!$config['archive']['lifetime'])
            return;

        if (empty($board) || $board['uri'] !== $board_uri) {
            if (!openBoard($board_uri)) {
                error_log("purgeArchive: Failed to open board: " . $board_uri);
                return 0;
            }
        }

        $query = prepare("SELECT `id`, `original_thread_id`, `files`, `path` FROM `archive_threads` WHERE `board_uri` = :board_uri AND `lifetime` < :lifetime AND `featured` = 0 AND `mod_archived` = 0");
        $query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
        $query->bindValue(':lifetime', strtotime("-" . $config['archive']['lifetime']), PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        while($thread = $query->fetch(PDO::FETCH_ASSOC)) {
            $archive_res_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['res'] . $thread['path'] . '/';
            $archive_img_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['img'] . $thread['path'] . '/';
            $archive_thumb_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['thumb'] . $thread['path'] . '/';

            foreach (json_decode($thread['files']) as $f) {
                @unlink($archive_img_path . $f->file);
                @unlink($archive_thumb_path . $f->thumb);
            }
            @unlink($archive_res_path . sprintf($config['file_page'], $thread['original_thread_id']));
        }

        if($query->rowCount() != 0) {
            $delete_query = prepare("DELETE FROM `archive_threads` WHERE `board_uri` = :board_uri AND `lifetime` < :lifetime AND `featured` = 0 AND `mod_archived` = 0");
            $delete_query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
            $delete_query->bindValue(':lifetime', strtotime("-" . $config['archive']['lifetime']), PDO::PARAM_INT);
            $delete_query->execute() or error(db_error($delete_query));

            modLog(sprintf("Purged %d archived threads from board %s due to expiration date", $delete_query->rowCount(), $board_uri));
            return $delete_query->rowCount();
        }
        return 0;
    }

    static public function featureThread($archive_entry_id, $board_uri, $mod_archive = false) {
        global $config, $mod;
        global $board;
        if (empty($board) || $board['uri'] !== $board_uri) {
            if (!openBoard($board_uri)) {
                error_log("featureThread: Failed to open board: " . $board_uri);
                return false;
            }
        }

        if(!$mod_archive && !$config['feature']['threads']) return;
        if($mod_archive && !$config['mod_archive']['threads']) return;

        $query_sql = "SELECT `original_thread_id`, `files`, `path` FROM `archive_threads` WHERE `id` = :id AND `board_uri` = :board_uri AND " . ($mod_archive?"`mod_archived`":"`featured`") . " = 0";
        $query = prepare($query_sql);
        $query->bindValue(':id', $archive_entry_id, PDO::PARAM_INT);
        $query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
        $query->execute() or error(db_error($query));

        if(!$thread = $query->fetch(PDO::FETCH_ASSOC))
            error($config['error']['invalidpost']);

        $original_thread_id = $thread['original_thread_id'];

        $featured_res_path = $board['dir'] . ($mod_archive ? $config['dir']['mod_archive'] : $config['dir']['featured']) . $config['dir']['res'] . $thread['path'] . '/';
        $featured_img_path = $board['dir'] . ($mod_archive ? $config['dir']['mod_archive'] : $config['dir']['featured']) . $config['dir']['img'] . $thread['path'] . '/';
        $featured_thumb_path = $board['dir'] . ($mod_archive ? $config['dir']['mod_archive'] : $config['dir']['featured']) . $config['dir']['thumb'] . $thread['path'] . '/';

        @mkdir($featured_res_path, 0777, true);
        @mkdir($featured_img_path, 0777, true);
        @mkdir($featured_thumb_path, 0777, true);

        $thread_file_content = @file_get_contents($board['dir'] . $config['dir']['archive'] . $config['dir']['res'] . $thread['path'] . '/' . sprintf($config['file_page'], $original_thread_id));

        $thread_file_content = str_replace(
            sprintf('src="/' . $config['board_path'] . $config['dir']['archive'] . $config['dir']['res'], $board['channel'], $board_uri),
            sprintf('src="/' . $config['board_path'] . ($mod_archive?$config['dir']['mod_archive']:$config['dir']['featured']) . $config['dir']['res'], $board['channel'], $board_uri),
            $thread_file_content
        );
        $thread_file_content = str_replace(
            sprintf('href="/' . $config['board_path'] . $config['dir']['archive'] . $config['dir']['res'], $board['channel'], $board_uri),
            sprintf('href="/' . $config['board_path'] . ($mod_archive?$config['dir']['mod_archive']:$config['dir']['featured']) . $config['dir']['res'], $board['channel'], $board_uri),
            $thread_file_content
        );
        $thread_file_content = str_replace('Archived thread', 'Featured thread', $thread_file_content);

        @file_put_contents($featured_res_path . sprintf($config['file_page'], $original_thread_id), $thread_file_content, LOCK_EX);

        foreach (json_decode($thread['files']) as $f) {
            $source_img = $board['dir'] . $config['dir']['archive'] . $config['dir']['img'] . $thread['path'] . '/' . $f->file;
            $dest_img = $featured_img_path . $f->file;
            $source_thumb = $board['dir'] . $config['dir']['archive'] . $config['dir']['thumb'] . $thread['path'] . '/' . $f->thumb;
            $dest_thumb = $featured_thumb_path . $f->thumb;

            if (!@copy($source_img, $dest_img)) {
                error_log("Failed to copy image: $source_img");
            }
            if (!@copy($source_thumb, $dest_thumb)) {
                error_log("Failed to copy thumbnail: $source_thumb");
            }
        }

        $update_query = prepare("UPDATE `archive_threads` SET " . ($mod_archive?"`mod_archived`":"`featured`") . " = 1 WHERE `id` = :id AND `board_uri` = :board_uri");
        $update_query->bindValue(':id', $archive_entry_id, PDO::PARAM_INT);
        $update_query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
        $update_query->execute() or error(db_error($update_query));

        modLog(sprintf("Added thread #%d (original: %d) to " . ($mod_archive?"mod archive":"featured threads") . " for board %s", $archive_entry_id, $original_thread_id, $board_uri));

        self::buildFeaturedIndex($board_uri);
        self::buildArchiveIndex($board_uri);

        return true;
    }

    static public function deleteFeatured($archive_entry_id, $board_uri, $mod_archive = false) {
        global $config, $mod;
        global $board;

        if (empty($board) || $board['uri'] !== $board_uri) {
            if (!openBoard($board_uri)) {
                error_log("deleteFeatured: Failed to open board: " . $board_uri);
                return;
            }
        }

        $query = prepare("SELECT `original_thread_id`, `files`, `path`, `lifetime` FROM `archive_threads` WHERE `id` = :id AND `board_uri` = :board_uri AND (`featured` = 1 OR `mod_archived` = 1)");
        $query->bindValue(':id', $archive_entry_id, PDO::PARAM_INT);
        $query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
        $query->execute() or error(db_error($query));

        if(!$thread = $query->fetch(PDO::FETCH_ASSOC))
            error($config['error']['invalidpost']);

        $original_thread_id = $thread['original_thread_id'];
        $featured_res_path = $board['dir'] . ($mod_archive ? $config['dir']['mod_archive'] : $config['dir']['featured']) . $config['dir']['res'] . $thread['path'] . '/';
        $featured_img_path = $board['dir'] . ($mod_archive ? $config['dir']['mod_archive'] : $config['dir']['featured']) . $config['dir']['img'] . $thread['path'] . '/';
        $featured_thumb_path = $board['dir'] . ($mod_archive ? $config['dir']['mod_archive'] : $config['dir']['featured']) . $config['dir']['thumb'] . $thread['path'] . '/';

        foreach (json_decode($thread['files']) as $f) {
            @unlink($featured_img_path . $f->file);
            @unlink($featured_thumb_path . $f->thumb);
        }
        @unlink($featured_res_path . sprintf($config['file_page'], $original_thread_id));

        $update_query = prepare("UPDATE `archive_threads` SET " . ($mod_archive?"`mod_archived`":"`featured`") . " = 0 WHERE `id` = :id AND `board_uri` = :board_uri");
        $update_query->bindValue(':id', $archive_entry_id, PDO::PARAM_INT);
        $update_query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
        $update_query->execute() or error(db_error($update_query));

        modLog(sprintf("Removed thread #%d (original: %d) from " . ($mod_archive?"mod archive":"featured threads") . " for board %s", $archive_entry_id, $original_thread_id, $board_uri));

        self::buildFeaturedIndex($board_uri);
        self::buildArchiveIndex($board_uri);
    }

    static public function deleteArchived($archive_entry_id, $board_uri) {
        global $config, $mod;
        global $board;

        if (empty($board) || $board['uri'] !== $board_uri) {
            if (!openBoard($board_uri)) {
                error_log("deleteArchived: Failed to open board: " . $board_uri);
                return;
            }
        }

        $query = prepare("SELECT `original_thread_id`, `files`, `path` FROM `archive_threads` WHERE `id` = :id AND `board_uri` = :board_uri");
        $query->bindValue(':id', $archive_entry_id, PDO::PARAM_INT);
        $query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
        $query->execute() or error(db_error($query));

        if(!$thread = $query->fetch(PDO::FETCH_ASSOC))
            error($config['error']['invalidpost']);

        $original_thread_id = $thread['original_thread_id'];
        $archived_res_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['res'] . $thread['path'] . '/';
        $archived_img_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['img'] . $thread['path'] . '/';
        $archived_thumb_path = $board['dir'] . $config['dir']['archive'] . $config['dir']['thumb'] . $thread['path'] . '/';

        foreach (json_decode($thread['files']) as $f) {
            @unlink($archived_img_path . $f->file);
            @unlink($archived_thumb_path . $f->thumb);
        }
        @unlink($archived_res_path . sprintf($config['file_page'], $original_thread_id));
        @unlink($archived_res_path . sprintf('%d.json', $original_thread_id));

        $delete_query = prepare("DELETE FROM `archive_threads` WHERE `id` = :id AND `board_uri` = :board_uri");
        $delete_query->bindValue(':id', $archive_entry_id, PDO::PARAM_INT);
        $delete_query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
        $delete_query->execute() or error(db_error($delete_query));

        $del_vote_query = prepare("DELETE FROM `archive_votes` WHERE `board` = :board AND `thread_id` = :thread_id");
        $del_vote_query->bindValue(':board', $board_uri, PDO::PARAM_STR);
        $del_vote_query->bindValue(':thread_id', $original_thread_id, PDO::PARAM_INT);
        $del_vote_query->execute() or error(db_error($del_vote_query));

        // Delete reports for this archived thread
        $del_reports_query = prepare("DELETE FROM `reports` WHERE `board` = :board AND `post` = :post");
        $del_reports_query->bindValue(':board', $board_uri, PDO::PARAM_STR);
        $del_reports_query->bindValue(':post', $original_thread_id, PDO::PARAM_INT);
        $del_reports_query->execute() or error(db_error($del_reports_query));

        modLog(sprintf("Deleted archived thread #%d (original: %d) from board %s", $archive_entry_id, $original_thread_id, $board_uri));
        self::buildArchiveIndex($board_uri);
    }

    static public function RebuildArchiveIndexes($board_uris = null) {
        global $config;

        if (!$config['archive']['threads']) return;

        $boards_to_rebuild = [];
        if (is_array($board_uris)) {
            foreach ($board_uris as $uri) {
                $boards_to_rebuild[] = ['uri' => $uri];
            }
        } else {
            $query = query("SELECT DISTINCT `board_uri` FROM `archive_threads`");
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $boards_to_rebuild[] = ['uri' => $row['board_uri']];
            }
        }

        foreach ($boards_to_rebuild as $current_board_info) {
            $current_board_uri = $current_board_info['uri'];
            global $board;
            $original_global_board = $board;
            if (empty($board) || $board['uri'] !== $current_board_uri) {
                if (!openBoard($current_board_uri)) {
                    error_log("RebuildArchiveIndexes: Failed to open board: " . $current_board_uri);
                    continue;
                }
            }

            if (!$config['archive']['cron_job']['purge']) {
                self::purgeArchive($current_board_uri);
            }
            self::buildArchiveIndex($current_board_uri);
            self::buildFeaturedIndex($current_board_uri);

            $board = $original_global_board;
        }
    }

    static public function buildArchiveIndex($board_uri, $threads_per_page = null) {
        global $config;
        global $board;

        if (!$config['archive']['threads']) return;

        if (empty($board) || $board['uri'] !== $board_uri) {
            if (!openBoard($board_uri)) {
                error_log("buildArchiveIndex: Failed to open board: " . $board_uri);
                return;
            }
        }

        // Use config value if not explicitly set
        if ($threads_per_page === null) {
            $threads_per_page = isset($config['archive']['threads_per_page']) ? $config['archive']['threads_per_page'] : 5;
        }

        $total_threads = self::getArchiveCount($board_uri);
        $total_pages = ceil($total_threads / $threads_per_page);

        for ($page = 1; $page <= $total_pages; $page++) {
            $archive = self::getArchiveListPaginated($board_uri, $page, $threads_per_page);

            foreach ($archive as &$thread) {
                $thread['archived_url'] = $config['root'] . $board['dir'] . $config['dir']['archive'] . $config['dir']['res'] . $thread['path'] . '/' . sprintf($config['file_page'], $thread['original_thread_id']);
                if ($thread['first_image']) {
                    $thread['image_url'] = $config['root'] . $board['dir'] . $config['dir']['archive'] . $config['dir']['thumb'] . $thread['path'] . '/' . $thread['first_image'];
                } else {
                    $thread['image_url'] = null;
                }
            }

            $title = sprintf(_('Archived') . ' %s: ' . $config['board_abbreviation'], _('threads'), $board['uri']);

            $archive_page_content = Element("mod/archive_list.html", array(
                'config' => $config,
                'thread_count' => $total_threads,
                'board' => $board,
                'archive' => $archive,
                'current_page' => $page,
                'total_pages' => $total_pages
            ));

            $archive_page = Element('page.html', array(
                'config' => $config,
                'mod' => false,
                'hide_dashboard_link' => true,
                'boardlist' => createBoardList(false),
                'title' => $title,
                'subtitle' => "",
                'body' => $archive_page_content
            ));

           // Calculate subfolder for every 1000 pages after the first 1000
           if ($page == 1) {
                $filename = $config['dir']['home'] . $board['dir'] . $config['dir']['archive'] . $config['file_index'];
            } else {
                $pagination_base = $config['dir']['home'] . $board['dir'] . $config['dir']['archive'] . 'pagination/';
                if ($page <= 1000) {
                    $folder = '1/';
                } else {
                    $folder_num = floor(($page - 1) / 1000) * 1000 + 1;
                    $folder = $folder_num . '/';
                }
                $full_folder_path = $pagination_base . $folder;
                if (!is_dir($full_folder_path)) {
                    @mkdir($full_folder_path, 0777, true);
                }
                $filename = $full_folder_path . $page . '.html';
            }
            file_write($filename, $archive_page);
        }
    }

    // Build a limited number of archive index pages per call for batching
    static public function buildArchiveIndexBatch($board_uri, $start_page = 1, $batch_size = 100, $threads_per_page = null) {
        global $config, $board;

        if (!$config['archive']['threads']) return 0;

        if (empty($board) || $board['uri'] !== $board_uri) {
            if (!openBoard($board_uri)) {
                error_log("buildArchiveIndexBatch: Failed to open board: " . $board_uri);
                return 0;
            }
        }

        if ($threads_per_page === null) {
            $threads_per_page = isset($config['archive']['threads_per_page']) ? $config['archive']['threads_per_page'] : 5;
        }

        $total_threads = self::getArchiveCount($board_uri);
        $total_pages = ceil($total_threads / $threads_per_page);

        $end_page = min($start_page + $batch_size - 1, $total_pages);
        for ($page = $start_page; $page <= $end_page; $page++) {
            $archive = self::getArchiveListPaginated($board_uri, $page, $threads_per_page);

            foreach ($archive as &$thread) {
                $thread['archived_url'] = $config['root'] . $board['dir'] . $config['dir']['archive'] . $config['dir']['res'] . $thread['path'] . '/' . sprintf($config['file_page'], $thread['original_thread_id']);
                if ($thread['first_image']) {
                    $thread['image_url'] = $config['root'] . $board['dir'] . $config['dir']['archive'] . $config['dir']['thumb'] . $thread['path'] . '/' . $thread['first_image'];
                } else {
                    $thread['image_url'] = null;
                }
            }

            $title = sprintf(_('Archived') . ' %s: ' . $config['board_abbreviation'], _('threads'), $board['uri']);

            $archive_page_content = Element("mod/archive_list.html", array(
                'config' => $config,
                'thread_count' => $total_threads,
                'board' => $board,
                'archive' => $archive,
                'current_page' => $page,
                'total_pages' => $total_pages
            ));

            $archive_page = Element('page.html', array(
                'config' => $config,
                'mod' => false,
                'hide_dashboard_link' => true,
                'boardlist' => createBoardList(false),
                'title' => $title,
                'subtitle' => "",
                'body' => $archive_page_content
            ));

            // Calculate subfolder for every 1000 pages after the first 1000
            if ($page == 1) {
                $filename = $config['dir']['home'] . $board['dir'] . $config['dir']['archive'] . $config['file_index'];
            } else {
                $pagination_base = $config['dir']['home'] . $board['dir'] . $config['dir']['archive'] . 'pagination/';
                if ($page <= 1000) {
                    $folder = '1/';
                } else {
                    $folder_num = floor(($page - 1) / 1000) * 1000 + 1;
                    $folder = $folder_num . '/';
                }
                $full_folder_path = $pagination_base . $folder;
                if (!is_dir($full_folder_path)) {
                    @mkdir($full_folder_path, 0777, true);
                }
                $filename = $full_folder_path . $page . '.html';
            }
            file_write($filename, $archive_page);
        }
        // Return the next page to process, or 0 if done
        return ($end_page < $total_pages) ? ($end_page + 1) : 0;
    }

    static public function getArchiveListPaginated($board_uri, $page, $threads_per_page) {
        global $config;

        $offset = ($page - 1) * $threads_per_page;
        $query = prepare("SELECT `id`, `original_thread_id`, `board_id`, `snippet`, `featured`, `mod_archived`, `votes`, `path`, `first_image` FROM `archive_threads` WHERE `board_uri` = :board_uri AND `lifetime` > :lifetime ORDER BY `original_thread_id` DESC LIMIT :limit OFFSET :offset");
        $query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
        $query->bindValue(':lifetime', strtotime("-" . $config['archive']['lifetime']), PDO::PARAM_INT);
        $query->bindValue(':limit', $threads_per_page, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    static public function getArchiveCount($board_uri) {
        global $config;

        $query = prepare("SELECT COUNT(*) as count FROM `archive_threads` WHERE `board_uri` = :board_uri AND `lifetime` > :lifetime");
        $query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
        $query->bindValue(':lifetime', strtotime("-" . $config['archive']['lifetime']), PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        $result = $query->fetch(PDO::FETCH_ASSOC);
        return $result['count'];
    }

    static public function getArchiveList($board_uri, $featured = false, $mod_archive = false, $order_by_lifetime = false) {
        global $config;

        $archive = false;
        $sql_common_select = "`id`, `original_thread_id`, `board_id`, `snippet`, `featured`, `mod_archived`, `votes`, `path`, `first_image`";
        $order_clause = $order_by_lifetime ? " ORDER BY `lifetime` DESC" : " ORDER BY `original_thread_id` DESC";

        if($featured) {
            $query = prepare("SELECT $sql_common_select FROM `archive_threads` WHERE `board_uri` = :board_uri AND `featured` = 1" . $order_clause);
        } else if($mod_archive) {
            $query = prepare("SELECT $sql_common_select FROM `archive_threads` WHERE `board_uri` = :board_uri AND `mod_archived` = 1" . $order_clause);
        } else {
            $query = prepare("SELECT $sql_common_select FROM `archive_threads` WHERE `board_uri` = :board_uri AND `lifetime` > :lifetime_val" . $order_clause);
            $query->bindValue(':lifetime_val', strtotime("-" . $config['archive']['lifetime']), PDO::PARAM_INT);
        }
        $query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
        $query->execute() or error(db_error($query));
        $archive = $query->fetchAll(PDO::FETCH_ASSOC);
        return $archive;
    }

    static public function buildFeaturedIndex($board_uri) {
        global $config;
        global $board;

        if(!$config['feature']['threads']) return;

        if (empty($board) || $board['uri'] !== $board_uri) {
            if (!openBoard($board_uri)) {
                error_log("buildFeaturedIndex: Failed to open board: " . $board_uri);
                return;
            }
        }

        $archive = self::getArchiveList($board_uri, true);

        foreach($archive as &$thread) {
            $thread['featured_url'] = $config['root'] . $board['dir'] . $config['dir']['featured'] . $config['dir']['res'] . $thread['path'] . '/' . sprintf($config['file_page'], $thread['original_thread_id']);
            if ($thread['first_image']) {
                $thread['image_url'] = $config['root'] . $board['dir'] . $config['dir']['featured'] . $config['dir']['thumb'] . $thread['path'] . '/' . $thread['first_image'];
            } else {
                $thread['image_url'] = null;
            }
        }

        $title = sprintf(_('Featured') . ' %s: ' . $config['board_abbreviation'], _('threads'), $board['uri']);
        $body_content = Element("mod/archive_featured_list.html", array(
            'config' => $config,
            'board' => $board,
            'archive' => $archive
        ));
        $archive_page = Element('page.html', array(
            'config' => $config,
            'mod' => false,
            'hide_dashboard_link' => true,
            'boardlist' => createBoardList(false),
            'title' => $title,
            'subtitle' => "",
            'body' => $body_content
        ));
        file_write($config['dir']['home'] . $board['dir'] . $config['dir']['featured'] . $config['file_index'], $archive_page);
    }

    static public function addVote($board_uri, $original_thread_id) {
        global $config;

        $query = prepare("SELECT `id` FROM `archive_threads` WHERE `board_uri` = :board_uri AND `original_thread_id` = :original_thread_id");
        $query->bindValue(':board_uri', $board_uri, PDO::PARAM_STR);
        $query->bindValue(':original_thread_id', $original_thread_id, PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        $archive_entry = $query->fetch(PDO::FETCH_ASSOC);

        if (!$archive_entry) {
            error($config['error']['nonexistant']);
        }
        $archive_entry_id = $archive_entry['id'];

        $query = prepare("SELECT COUNT(*) FROM `archive_votes` WHERE `board` = :board AND `thread_id` = :thread_id AND `ip` = :ip");
        $query->bindValue(':board', $board_uri, PDO::PARAM_STR);
        $query->bindValue(':thread_id', $original_thread_id, PDO::PARAM_INT);
        $query->bindValue(':ip', get_ip_hash($_SERVER['REMOTE_ADDR']), PDO::PARAM_STR);
        $query->execute() or error(db_error($query));
        if ($query->fetchColumn(0) != 0) {
            error($config['error']['already_voted']);
        }

        $update_query = prepare("UPDATE `archive_threads` SET `votes` = `votes`+1 WHERE `id` = :archive_entry_id");
        $update_query->bindValue(':archive_entry_id', $archive_entry_id, PDO::PARAM_INT);
        $update_query->execute() or error(db_error($update_query));

        $insert_query = prepare("INSERT INTO `archive_votes` VALUES (NULL, :board, :thread_id, :ip)");
        $insert_query->bindValue(':board', $board_uri, PDO::PARAM_STR);
        $insert_query->bindValue(':thread_id', $original_thread_id, PDO::PARAM_INT);
        $insert_query->bindValue(':ip', get_ip_hash($_SERVER['REMOTE_ADDR']), PDO::PARAM_STR);
        $insert_query->execute() or error(db_error($insert_query));

        self::buildArchiveIndex($board_uri);
    }
}
?>