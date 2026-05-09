<?php
    require 'info.php';
    
    function index_build($action, $settings, $board) {
        // Possible values for $action:
        //	- all (rebuild everything, initialization)
        //	- news (news has been updated)
        //	- boards (board list changed)
        //	- post (a post has been made)
        //	- post-thread (a thread has been made)
        
        $b = new index();
        $b->build($action, $settings);
    }
    
    // Wrap functions in a class so they don't interfere with normal Tinyboard operations
    class index {
        public function build($action, $settings) {
            global $config, $_theme;
            
            if ($action == 'all') {
                copy('templates/themes/index/' . $settings['basecss'], $config['dir']['home'] . $settings['css']);
            }
            
            $this->excluded = explode(' ', $settings['exclude_recent_activity']);
            
            if ($action == 'all' || $action == 'post' || $action == 'post-thread' || $action == 'post-delete') {
                $action = generation_strategy('sb_index', array());
                if ($action == 'delete') {
                    file_unlink($config['dir']['home'] . $settings['html']);
                }
                elseif ($action == 'rebuild') {
                    file_write($config['dir']['home'] . $settings['html'], $this->homepage($settings));
                }
            }
            if ($action == 'all' || $action == 'news' || $action == 'boards'){
                file_write($config['dir']['home'] . $settings['html'], $this->homepage($settings));
            }
        }
            
        // Build news page
        public function homepage($settings) {
            global $config, $board, $pdo;

            $recent_activity = Array();
            $stats = Array();

            $boards = listBoards();

            // Build recent activity (posts with images, videos, embeds, or just text)
            $board_uris = [];
            foreach ($boards as &$_board) {
                if (in_array($_board['uri'], $this->excluded))
                    continue;
                $board_uris[] = $pdo->quote($_board['uri']);
            }
            if (empty($board_uris)) {
                error(_("Can't build the Index theme, because there are no boards to be fetched."));
            }

            // Remove files IS NOT NULL to allow text-only posts
            $query = prepare('SELECT * FROM ``posts`` WHERE `board` IN (' . implode(',', $board_uris) . ') ORDER BY `time` DESC LIMIT :limit');
            $query->bindValue(':limit', (int)$settings['limit_activity'], PDO::PARAM_INT);
            $query->execute() or error(db_error($query));

            $video_extensions = ['webm', 'mp4', 'ogg'];
            $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];

            while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
                openBoard($post['board']);

                $files = isset($post['files']) ? json_decode($post['files']) : null;
                $has_file = $files && isset($files[0]->file) && $files[0]->file !== 'deleted' && $files[0]->thumb !== 'file';
                $post['adult'] = ($has_file && isset($files[0]->adult) && $files[0]->adult) ? true : false;

                $is_image = false;
                $is_video = false;
                $is_embed = false;

                if ($has_file) {
                    $file = $files[0];
                    $ext = strtolower(pathinfo($file->file, PATHINFO_EXTENSION));
                    $is_image = in_array($ext, $image_extensions);
                    $is_video = in_array($ext, $video_extensions);
                    $is_embed = isset($file->embed) && $file->embed;
                }

                // Skip posts with no files and no text
                if (!$has_file && empty($post['body'])) {
                    continue;
                }

                $post_date_path = isset($post['live_date_path']) && $post['live_date_path'] ? $post['live_date_path'] . '/' : '';
                $post['link'] = $config['root'] . $board['dir'] . $config['dir']['res'] . $post_date_path . link_for($post) . '#' . $post['id'];

                // Set display type for the template
                if ($has_file) {
                    if ($is_image) {
                        $post['display_type'] = 'image';
                        if ($file->thumb == 'spoiler') {
                            $tn_size = @getimagesize($config['spoiler_image']);
                            $post['src'] = $config['spoiler_image'];
                            $post['thumbwidth'] = $tn_size[0];
                            $post['thumbheight'] = $tn_size[1];
                        } else {
                            $post['src'] = $config['uri_thumb'] . $file->thumb;
                            $post['thumbwidth'] = $file->thumbwidth;
                            $post['thumbheight'] = $file->thumbheight;
                        }
                    } elseif ($is_video) {
                        $post['display_type'] = 'video';
                        $post['video_src'] = $config['uri_img'] . $file->file;
                        // Use video thumbnail if available, else fallback
                        if (!empty($file->thumb) && $file->thumb != 'file') {
                            $post['src'] = $config['uri_thumb'] . $file->thumb;
                            $post['thumbwidth'] = $file->thumbwidth;
                            $post['thumbheight'] = $file->thumbheight;
                        } else {
                            $post['src'] = $config['default_video_thumb'] ?? '';
                            $post['thumbwidth'] = 128;
                            $post['thumbheight'] = 128;
                        }
                    } elseif ($is_embed) {
                        $post['display_type'] = 'embed';
                        $post['embed'] = $file->embed;
                        // Optionally, set a default embed thumbnail
                        $post['src'] = $config['default_embed_thumb'] ?? '';
                        $post['thumbwidth'] = 128;
                        $post['thumbheight'] = 128;
                    }
                } else {
                    $post['display_type'] = 'text';
                    // No thumbnail for text-only posts
                    $post['src'] = '';
                    $post['thumbwidth'] = 0;
                    $post['thumbheight'] = 0;
                }

                $post['snippet'] = ($post['body'] != "") ? pm_snippet($post['body'], 150) : "<em>" . _("(no comment)") . "</em>";
                $post['board_name'] = $board['name'];
                
                // Check if board is marked as adult OR if it's in the nsfw_boards setting
                $is_nsfw_board = (isset($board['adult']) && $board['adult']) || in_array($post['board'], explode(' ', $settings['nsfw_boards']));
                $post['nsfw'] = $is_nsfw_board ? true : false;

                $recent_activity[] = $post;
            }

            // Stats
            // Total posts
            $query = prepare('SELECT COUNT(*) FROM ``posts`` WHERE `board` IN (' . implode(',', $board_uris) . ')');
            $query->execute() or error(db_error($query));
            $stats['total_posts'] = number_format($query->fetchColumn());

            // Unique IPs
            $query = prepare('SELECT COUNT(DISTINCT(`ip`)) FROM ``posts`` WHERE `board` IN (' . implode(',', $board_uris) . ')');
            $query->execute() or error(db_error($query));
            $stats['unique_posters'] = number_format($query->fetchColumn());

            // Active content
            $query = prepare('SELECT `files` FROM ``posts`` WHERE `board` IN (' . implode(',', $board_uris) . ') AND `num_files` > 0');
            $query->execute() or error(db_error($query));
            $files = $query->fetchAll();
            $stats['active_content'] = 0;
            foreach ($files as &$file) {
                preg_match_all('/"size":([0-9]*)/', $file[0], $matches);
                $stats['active_content'] += array_sum($matches[1]);
            }

            // News entries
            $settings['no_recent'] = (int) $settings['no_recent'];
            $query = query("SELECT * FROM ``news`` ORDER BY `time` DESC" . ($settings['no_recent'] ? ' LIMIT ' . $settings['no_recent'] : '')) or error(db_error());
            $news = $query->fetchAll(PDO::FETCH_ASSOC);

            // Fetch boards marked to show in index with their stats
            $index_boards = [];
            $query = prepare('SELECT `uri`, `title`, `subtitle`, `channel` FROM ``boards`` WHERE `show_in_index` = 1 ORDER BY `board_order` ASC, `uri` ASC');
            $query->execute() or error(db_error($query));
            $boards_to_index = $query->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($boards_to_index as $idx_board) {
                $idx_board_uri = $pdo->quote($idx_board['uri']);
                
                // Total posts
                $query = prepare('SELECT COUNT(*) FROM ``posts`` WHERE `board` = :board');
                $query->bindValue(':board', $idx_board['uri']);
                $query->execute() or error(db_error($query));
                $total_posts = $query->fetchColumn();
                
                // Total threads
                $query = prepare('SELECT COUNT(*) FROM ``posts`` WHERE `board` = :board AND `thread` IS NULL');
                $query->bindValue(':board', $idx_board['uri']);
                $query->execute() or error(db_error($query));
                $total_threads = $query->fetchColumn();
                
                // Unique posters
                $query = prepare('SELECT COUNT(DISTINCT(`ip`)) FROM ``posts`` WHERE `board` = :board');
                $query->bindValue(':board', $idx_board['uri']);
                $query->execute() or error(db_error($query));
                $unique_posters = $query->fetchColumn();
                
                // Posts per month
                $query = prepare('SELECT COUNT(*) FROM ``posts`` WHERE `board` = :board AND `time` >= :since');
                $query->bindValue(':board', $idx_board['uri']);
                $query->bindValue(':since', time() - (86400 * 30), PDO::PARAM_INT);
                $query->execute() or error(db_error($query));
                $ppm = $query->fetchColumn();
                
                $index_boards[] = [
                    'uri' => $idx_board['uri'],
                    'dir' => 'channel/' . $idx_board['channel'] . '/',
                    'title' => $idx_board['title'],
                    'subtitle' => $idx_board['subtitle'],
                    'posts' => number_format($total_posts),
                    'threads' => number_format($total_threads),
                    'posters' => number_format($unique_posters),
                    'ppm' => number_format($ppm)
                ];
            }

            // Excluded boards for boardlist
            $excluded_boards = isset($settings['exclude_board_list']) ? explode(' ', $settings['exclude_board_list']) : [];
            $boardlist = array_filter($boards, function($board) use ($excluded_boards) {
                return !in_array($board['uri'], $excluded_boards);
            });

            return Element('themes/index/index.html', Array(
                'settings' => $settings,
                'config' => $config,
                'boardlist' => createBoardlist(),
                'recent_activity' => $recent_activity,
                'stats' => $stats,
                'news' => $news,
                'index_boards' => $index_boards,
                'boards' => $boardlist
            ));
        }
    };
    
?>