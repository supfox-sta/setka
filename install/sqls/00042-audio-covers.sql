-- Audio covers migration
-- Adds optional cover_photo to audios and foreign key to photos(id)

ALTER TABLE `audios`
    ADD COLUMN `cover_photo` BIGINT(20) UNSIGNED NULL AFTER `length`;

CREATE INDEX `idx_audios_cover_photo` ON `audios` (`cover_photo`);

ALTER TABLE `audios`
    ADD CONSTRAINT `fk_audios_cover_photo`
    FOREIGN KEY (`cover_photo`) REFERENCES `photos`(`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE;
