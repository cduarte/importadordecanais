-- Adiciona a coluna content_type e ajusta os índices conforme o schema atual.
ALTER TABLE `clientes_import_stream_hashes`
    ADD COLUMN `content_type` ENUM('movie','series') NOT NULL DEFAULT 'movie' AFTER `db_name`;

-- Garante que registros existentes tenham um valor válido definido.
UPDATE `clientes_import_stream_hashes`
SET `content_type` = 'movie'
WHERE `content_type` IS NULL;

-- Atualiza o índice único para incluir content_type.
ALTER TABLE `clientes_import_stream_hashes`
    DROP INDEX `uniq_client_stream_hash`,
    ADD UNIQUE KEY `uniq_client_stream_hash` (`db_host`, `db_name`, `content_type`, `stream_source_hash`);

-- Adiciona o índice auxiliar utilizado no schema.
ALTER TABLE `clientes_import_stream_hashes`
    ADD KEY `idx_content_type` (`content_type`);

-- Remove o default temporário mantendo a coluna como NOT NULL.
ALTER TABLE `clientes_import_stream_hashes`
    ALTER COLUMN `content_type` DROP DEFAULT;
