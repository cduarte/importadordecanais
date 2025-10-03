CREATE TABLE IF NOT EXISTS clientes_import_stream_hashes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    db_host VARCHAR(191) NOT NULL,
    db_name VARCHAR(191) NOT NULL,
    stream_id INT DEFAULT NULL,
    stream_source_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_client_stream_hash (db_host, db_name, stream_source_hash),
    KEY idx_stream_id (stream_id)
);
