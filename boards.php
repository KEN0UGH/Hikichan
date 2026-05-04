<?php
require 'inc/bootstrap.php';

// --- CONFIG ---
$per_page = 20; // boards per page

// --- GET PARAMETERS ---
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'uri';
$dir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'desc') ? 'DESC' : 'ASC';
$offset = ($page - 1) * $per_page;

// --- SORTABLE COLUMNS MAP ---
$sortable = [
    'uri' => 'uri',
    'title' => 'title',
    'posts' => 'total_posts',
    'threads' => 'total_threads',
    'posters' => 'unique_posters',
    'ppm' => 'ppm'
];

// Ensure valid column
if (!array_key_exists($sort, $sortable)) {
    $sort = 'uri';
}

// --- FETCH BOARD DATA RAW ---
$sql_base = "SELECT b.uri, b.title, b.subtitle, b.channel,
                (SELECT COUNT(*) FROM posts WHERE board = b.uri) AS total_posts,
                (SELECT COUNT(*) FROM posts WHERE board = b.uri AND thread IS NULL) AS total_threads,
                (SELECT COUNT(DISTINCT ip) FROM posts WHERE board = b.uri) AS unique_posters,
                (SELECT COUNT(*) FROM posts WHERE board = b.uri AND time >= :since) AS ppm
            FROM boards b";

$params = [':since' => time() - (86400 * 30)];

// Search filter
if ($search !== '') {
    $sql_base .= " WHERE b.uri LIKE :search OR b.title LIKE :search OR b.subtitle LIKE :search";
    $params[':search'] = '%' . $search . '%';
}

// Total boards count
$sql_count = "SELECT COUNT(*) FROM boards";
if ($search !== '') {
    $sql_count .= " WHERE uri LIKE :search OR title LIKE :search OR subtitle LIKE :search";
}
$stmt_count = prepare($sql_count);
foreach ($params as $k => $v) {
    if ($k !== ':since') $stmt_count->bindValue($k, $v);
}
$stmt_count->execute();
$total_boards = $stmt_count->fetchColumn();
$total_pages = max(1, ceil($total_boards / $per_page));

// Apply sorting
$sql_base .= " ORDER BY {$sortable[$sort]} $dir";

// Apply pagination
$sql_base .= " LIMIT :limit OFFSET :offset";

// Prepare and execute
$stmt = prepare($sql_base);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$boards_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format boards
$boards = [];
foreach ($boards_raw as $board) {
    $boards[] = [
        'uri' => $board['uri'],
        // This builds the "channel/1/" part of your URL
        'dir' => 'channel/' . $board['channel'] . '/',
        'title' => $board['title'],
        'subtitle' => $board['subtitle'],
        'posts' => number_format($board['total_posts']),
        'threads' => number_format($board['total_threads']),
        'posters' => number_format($board['unique_posters']),
        'ppm' => number_format($board['ppm'])
    ];
}

// Render with template
$body = Element('boards_stats.html', [
    'boards' => $boards,
    'search' => $search,
    'page' => $page,
    'total_pages' => $total_pages,
    'sort' => $sort,
    'dir' => strtolower($dir)
]);

echo Element($config['file_page_template'], [
    'config' => $config,
    'title' => _('Boards'),
    'boardlist' => createBoardlist(),
    'body' => $body
]);
