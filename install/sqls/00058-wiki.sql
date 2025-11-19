-- Wiki pages for communities

CREATE TABLE IF NOT EXISTS `wiki_pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` INT NOT NULL,
  `slug` VARCHAR(128) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `body_wiki` MEDIUMTEXT NOT NULL,
  `body_html` MEDIUMTEXT NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` INT UNSIGNED NOT NULL,
  `updated_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_group_slug` (`group_id`, `slug`),
  KEY `idx_group_updated` (`group_id`, `updated_at`)
  -- FK to groups omitted due to type mismatch in some installations; will be added via ALTER when types are confirmed
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wiki_revisions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_id` INT UNSIGNED NOT NULL,
  `rev` INT UNSIGNED NOT NULL,
  `author_id` INT UNSIGNED NOT NULL,
  `body_wiki` MEDIUMTEXT NOT NULL,
  `body_html` MEDIUMTEXT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_page_rev` (`page_id`, `rev`),
  KEY `idx_page_created` (`page_id`, `created_at`),
  CONSTRAINT `fk_wiki_revisions_page` FOREIGN KEY (`page_id`) REFERENCES `wiki_pages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
