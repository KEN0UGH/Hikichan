<?php
// Glue code for handling a Tinyboard/vichan post.
// Portions of this file are derived from Tinyboard code.

function postHandler($post) {
    global $board, $config;

    if ($post->has_file) {
        foreach ($post->files as &$file) {

            //
            // ─── AUDIO-ONLY WebM HANDLER ────────────────────────────────────────
            //
            // If the file is a WebM but its MIME type is audio/*, skip video logic
            if ($file->extension === 'webm'
                && strpos($file->type, 'audio/') === 0
            ) {
                // Spoiler handling
                if ($config['spoiler_images'] && isset($_POST['spoiler'])) {
                    $file = webm_set_spoiler($file);
                } else {
                    // Use generic file icon (or define an audio-specific one)
                    $file->thumb       = 'file';
                    $file->thumbwidth  = 100;
                    $file->thumbheight = 30;
                }

                // Provide reasonable dimensions for templates
                $file->width  = $file->thumbwidth;
                $file->height = $file->thumbheight;

                // Skip further video checks
                continue;
            }

            //
            // ─── VIDEO WebM WITH FFMPEG ────────────────────────────────────────
            //
            if ($file->extension === 'webm'
                && $config['webm']['use_ffmpeg']
            ) {
                require_once dirname(__FILE__) . '/ffmpeg.php';
                $webminfo = get_webm_info($file->file_path);

                if (isset($webminfo['error'])) {
                    return $webminfo['error']['msg'];
                }

                $file->width  = $webminfo['width'];
                $file->height = $webminfo['height'];

                if ($config['spoiler_images'] && isset($_POST['spoiler'])) {
                    $file = webm_set_spoiler($file);
                } else {
                    $file = set_thumbnail_dimensions($post, $file);
                    $tn_path = $board['dir']
                             . $config['dir']['thumb']
							 . $post->live_date_path . '/'
                             . $file->file_id . '.jpg';

                    if (make_webm_thumbnail(
                            $file->file_path,
                            $tn_path,
                            $file->thumbwidth,
                            $file->thumbheight,
                            $webminfo['duration']
                        ) === 0
                    ) {
                        $file->thumb = $file->file_id . '.jpg';
                    } else {
                        $file->thumb = 'file';
                    }
                }
            }

            //
            // ─── VIDEO WebM WITHOUT FFMPEG ─────────────────────────────────────
            //
            elseif ($file->extension === 'webm') {
                require_once dirname(__FILE__) . '/videodata.php';
                $videoDetails = videoData($file->file_path);

                if (!isset($videoDetails['container'])
                    || $videoDetails['container'] !== 'webm'
                ) {
                    return "Invalid WebM file";
                }

                if ($config['spoiler_images'] && isset($_POST['spoiler'])) {
                    $file = webm_set_spoiler($file);
                }
                elseif (isset($videoDetails['frame'])) {
                    $thumbName = $board['dir']
                               . $config['dir']['thumb']
							   . $post->live_date_path . '/'
                               . $file->file_id . '.webm';

                    if ($thumbFile = fopen($thumbName, 'wb')) {
                        fwrite($thumbFile, $videoDetails['frame']);
                        fclose($thumbFile);
                        $file->thumb = $file->file_id . '.webm';
                    } else {
                        $file->thumb = 'file';
                    }
                } else {
                    $file->thumb = 'file';
                }

                if (isset($videoDetails['width'])
                    && isset($videoDetails['height'])
                ) {
                    $file->width  = $videoDetails['width'];
                    $file->height = $videoDetails['height'];

                    if ($file->thumb !== 'file'
                        && $file->thumb !== 'spoiler'
                    ) {
                        $file = set_thumbnail_dimensions($post, $file);
                    }
                }
            }

            //
            // ─── MP4 HANDLING (NO FFMPEG) ──────────────────────────────────────
            //
            elseif ($file->extension === 'mp4') {
                if ($config['spoiler_images'] && isset($_POST['spoiler'])) {
                    $file = webm_set_spoiler($file);
                } else {
                    // Use 'file' as special keyword - template will use configured file icon
                    $file->thumb = 'file';
                    $size = @getimagesize(sprintf($config['file_thumb'], $config['file_icons']['mp4']));
                    $file->thumbwidth  = $size[0];
                    $file->thumbheight = $size[1];
                }

                // Provide some default video dimensions
                $file->width  = 640;
                $file->height = 360;

                $file = set_thumbnail_dimensions($post, $file);
            }

            // (Any additional branches—e.g., for other video or container types—go here)
        }
    }
}

/**
 * Calculates thumbnail dimensions preserving aspect ratio.
 */
function set_thumbnail_dimensions($post, $file) {
    global $board, $config;

    $maxw = $post->op
          ? $config['thumb_op_width']
          : $config['thumb_width'];
    $maxh = $post->op
          ? $config['thumb_op_height']
          : $config['thumb_height'];

    if ($file->width > $maxw || $file->height > $maxh) {
        $file->thumbwidth  = min($maxw, intval(round($file->width  * $maxh / $file->height)));
        $file->thumbheight = min($maxh, intval(round($file->height * $maxw / $file->width)));
    } else {
        $file->thumbwidth  = $file->width;
        $file->thumbheight = $file->height;
    }

    return $file;
}

/**
 * Sets spoiler thumbnail for video or audio.
 */
function webm_set_spoiler($file) {
    global $config;

    $file->thumb = 'spoiler';
    $size = @getimagesize($config['spoiler_image']);
    $file->thumbwidth  = $size[0];
    $file->thumbheight = $size[1];

    return $file;
}
