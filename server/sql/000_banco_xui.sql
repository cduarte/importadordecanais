-- parte do banco de dados do xui que esta na maquina do cliente para saber como é a estrutura do banco de dados
-- não pode alterar ou adicionar colunas nas tabelas do banco de dados do cliente
-- não pode alterar e nem adicionar tabelas no banco de dados do cliente


CREATE TABLE `streams` (
	`id` INT(11) NOT NULL AUTO_INCREMENT,
	`type` INT(11) NULL DEFAULT NULL,
	`category_id` LONGTEXT NULL DEFAULT NULL COLLATE 'utf8mb4_bin',
	`stream_display_name` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`stream_source` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`stream_icon` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`notes` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`enable_transcode` TINYINT(4) NULL DEFAULT '0',
	`transcode_attributes` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`custom_ffmpeg` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`movie_properties` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`movie_subtitles` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`read_native` TINYINT(4) NULL DEFAULT '1',
	`target_container` TEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`stream_all` TINYINT(4) NULL DEFAULT '0',
	`remove_subtitles` TINYINT(4) NULL DEFAULT '0',
	`custom_sid` VARCHAR(150) NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`epg_api` INT(1) NULL DEFAULT '0',
	`epg_id` INT(11) NULL DEFAULT NULL,
	`channel_id` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`epg_lang` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`order` INT(11) NULL DEFAULT '0',
	`auto_restart` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`transcode_profile_id` INT(11) NULL DEFAULT '0',
	`gen_timestamps` TINYINT(4) NULL DEFAULT '1',
	`added` INT(11) NULL DEFAULT NULL,
	`series_no` INT(11) NULL DEFAULT '0',
	`direct_source` TINYINT(4) NULL DEFAULT '0',
	`tv_archive_duration` INT(11) NULL DEFAULT '0',
	`tv_archive_server_id` INT(11) NULL DEFAULT '0',
	`tv_archive_pid` INT(11) NULL DEFAULT '0',
	`vframes_server_id` INT(11) NULL DEFAULT '0',
	`vframes_pid` INT(11) NULL DEFAULT '0',
	`movie_symlink` TINYINT(4) NULL DEFAULT '0',
	`rtmp_output` TINYINT(4) NULL DEFAULT '0',
	`allow_record` TINYINT(4) NULL DEFAULT '0',
	`probesize_ondemand` INT(11) NULL DEFAULT '128000',
	`custom_map` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`external_push` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`delay_minutes` INT(11) NULL DEFAULT '0',
	`tmdb_language` VARCHAR(64) NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`llod` TINYINT(4) NULL DEFAULT '0',
	`year` INT(4) NULL DEFAULT NULL,
	`rating` FLOAT NOT NULL DEFAULT '0',
	`plex_uuid` VARCHAR(256) NULL DEFAULT '' COLLATE 'utf8mb3_unicode_ci',
	`uuid` VARCHAR(32) NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`epg_offset` INT(11) NULL DEFAULT '0',
	`updated` TIMESTAMP NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
	`similar` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`tmdb_id` INT(11) NULL DEFAULT NULL,
	`adaptive_link` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`title_sync` VARCHAR(64) NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`fps_restart` TINYINT(1) NULL DEFAULT '0',
	`fps_threshold` INT(11) NULL DEFAULT '90',
	`direct_proxy` TINYINT(1) NULL DEFAULT '0',
	PRIMARY KEY (`id`) USING BTREE,
	INDEX `type` (`type`) USING BTREE,
	INDEX `enable_transcode` (`enable_transcode`) USING BTREE,
	INDEX `read_native` (`read_native`) USING BTREE,
	INDEX `epg_id` (`epg_id`) USING BTREE,
	INDEX `channel_id` (`channel_id`) USING BTREE,
	INDEX `transcode_profile_id` (`transcode_profile_id`) USING BTREE,
	INDEX `order` (`order`) USING BTREE,
	INDEX `direct_source` (`direct_source`) USING BTREE,
	INDEX `rtmp_output` (`rtmp_output`) USING BTREE,
	INDEX `epg_api` (`epg_api`) USING BTREE,
	INDEX `uuid` (`uuid`) USING BTREE,
	FULLTEXT INDEX `search` (`stream_display_name`, `stream_source`, `notes`, `channel_id`)
)
COLLATE='utf8mb3_unicode_ci'
ENGINE=InnoDB
;

CREATE TABLE `streams_categories` (
	`id` INT(11) NOT NULL AUTO_INCREMENT,
	`category_type` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`category_name` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb3_unicode_ci',
	`parent_id` INT(11) NULL DEFAULT '0',
	`cat_order` INT(11) NULL DEFAULT '0',
	`is_adult` INT(1) NULL DEFAULT '0',
	PRIMARY KEY (`id`) USING BTREE,
	INDEX `category_type` (`category_type`) USING BTREE,
	INDEX `cat_order` (`cat_order`) USING BTREE,
	INDEX `parent_id` (`parent_id`) USING BTREE
)
COLLATE='utf8mb3_unicode_ci'
ENGINE=InnoDB
;

CREATE TABLE `streams_episodes` (
	`id` INT(11) NOT NULL AUTO_INCREMENT,
	`season_num` INT(11) NULL DEFAULT NULL,
	`episode_num` INT(11) NULL DEFAULT NULL,
	`series_id` INT(11) NULL DEFAULT NULL,
	`stream_id` INT(11) NULL DEFAULT NULL,
	PRIMARY KEY (`id`) USING BTREE,
	INDEX `season_num` (`season_num`) USING BTREE,
	INDEX `series_id` (`series_id`) USING BTREE,
	INDEX `stream_id` (`stream_id`) USING BTREE,
	INDEX `episode_num` (`episode_num`) USING BTREE
)
COLLATE='utf8mb3_general_ci'
ENGINE=InnoDB
;

CREATE TABLE `streams_series` (
	`id` INT(11) NOT NULL AUTO_INCREMENT,
	`title` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`category_id` LONGTEXT NULL DEFAULT NULL COLLATE 'utf8mb4_bin',
	`cover` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`cover_big` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`genre` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`plot` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`cast` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`rating` INT(11) NULL DEFAULT NULL,
	`director` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`release_date` VARCHAR(255) NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`last_modified` INT(11) NULL DEFAULT NULL,
	`tmdb_id` INT(11) NULL DEFAULT NULL,
	`seasons` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`episode_run_time` INT(11) NULL DEFAULT '0',
	`backdrop_path` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`youtube_trailer` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`tmdb_language` VARCHAR(50) NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	`year` INT(4) NULL DEFAULT NULL,
	`plex_uuid` VARCHAR(256) NULL DEFAULT '' COLLATE 'utf8mb3_general_ci',
	`similar` MEDIUMTEXT NULL DEFAULT NULL COLLATE 'utf8mb3_general_ci',
	PRIMARY KEY (`id`) USING BTREE,
	INDEX `last_modified` (`last_modified`) USING BTREE,
	FULLTEXT INDEX `search` (`title`, `plot`, `cast`, `director`)
)
COLLATE='utf8mb3_general_ci'
ENGINE=InnoDB
;
