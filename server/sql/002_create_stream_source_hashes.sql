CREATE TABLE IF NOT EXISTS stream_source_hashes (
    stream_id INT UNSIGNED NOT NULL,
    stream_source_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (stream_id),
    UNIQUE KEY uniq_stream_source_hash (stream_source_hash),
    CONSTRAINT fk_stream_source_hashes_stream FOREIGN KEY (stream_id)
        REFERENCES streams (id)
        ON DELETE CASCADE
);

INSERT INTO stream_source_hashes (stream_id, stream_source_hash)
SELECT s.id, SHA2(s.stream_source, 256)
FROM streams AS s
LEFT JOIN stream_source_hashes AS h ON h.stream_id = s.id
WHERE h.stream_id IS NULL
  AND s.stream_source IS NOT NULL
  AND s.stream_source <> '';
