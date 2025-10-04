-- Adiciona o novo tipo de job 'series' à enumeração existente.
ALTER TABLE `clientes_import_jobs`
    MODIFY `job_type` ENUM('movies','channels','series') NOT NULL DEFAULT 'movies';
