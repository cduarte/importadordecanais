-- O controle de hashes de origem de streams esta no banco administrador.


CREATE TABLE IF NOT EXISTS `clientes_import` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `db_host` varchar(200) NOT NULL,
  `db_name` varchar(150) NOT NULL,
  `db_user` varchar(100) NOT NULL,
  `db_password` varbinary(512) NOT NULL,
  `m3u_url` text DEFAULT NULL,
  `m3u_file_path` text DEFAULT NULL,
  `last_import_at` timestamp NULL DEFAULT NULL,
  `import_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_import_status` varchar(50) DEFAULT NULL,
  `last_import_message` text DEFAULT NULL,
  `api_token` char(64) NOT NULL,
  `token_hash` char(128) NOT NULL,
  `token_active` tinyint(1) NOT NULL DEFAULT 1,
  `client_ip` varchar(45) DEFAULT NULL,
  `client_user_agent` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uq_api_token` (`api_token`) USING BTREE,
  KEY `idx_db_host` (`db_host`) USING BTREE,
  KEY `idx_last_import_at` (`last_import_at`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clientes_import_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_type` enum('movies','channels','series') NOT NULL DEFAULT 'movies',
  `db_host` varchar(191) NOT NULL,
  `db_name` varchar(191) NOT NULL,
  `db_user` varchar(191) NOT NULL,
  `db_password` varchar(191) NOT NULL,
  `m3u_url` text NOT NULL,
  `m3u_file_path` text DEFAULT NULL,
  `api_token` char(64) NOT NULL,
  `status` enum('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  `progress` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `message` text DEFAULT NULL,
  `total_added` int(10) unsigned DEFAULT NULL,
  `total_skipped` int(10) unsigned DEFAULT NULL,
  `total_errors` int(10) unsigned DEFAULT NULL,
  `client_ip` varchar(45) DEFAULT NULL,
  `client_user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_job_type` (`job_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clientes_import_stream_hashes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `db_host` varchar(191) NOT NULL,
  `db_name` varchar(191) NOT NULL,
  `stream_id` int(11) DEFAULT NULL,
  `stream_source_hash` char(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_client_stream_hash` (`db_host`,`db_name`,`stream_source_hash`),
  KEY `idx_stream_id` (`stream_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

