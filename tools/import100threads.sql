INSERT INTO `posts` (
    `board_id`, `board`, `thread`, `subject`, `name`, 
    `body`, `body_nomarkup`, `time`, `bump`, 
    `live_date_path`, `password`, `ip`
)
WITH RECURSIVE thread_generator AS (
    SELECT 1 AS n
    UNION ALL
    SELECT n + 1 FROM thread_generator WHERE n < 100
)
SELECT 
    n AS board_id,
    'b' AS board,
    NULL AS thread,
    CONCAT('Thread Subject #', n) AS subject,
    'Anonymous' AS name,
    CONCAT('This is the content for thread number ', n) AS body,
    CONCAT('This is the content for thread number ', n) AS body_nomarkup,
    UNIX_TIMESTAMP() - (n * 60) AS time,
    UNIX_TIMESTAMP() - (n * 60) AS bump,
    DATE_FORMAT(NOW(), '%Y/%m/%d/') AS live_date_path, -- Generates e.g., 2026/05/03/
    'password123' AS password,
    '127.0.0.1' AS ip
FROM thread_generator;