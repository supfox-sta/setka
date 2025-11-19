-- Table for pinned wiki pages per group (flexible, with sort)
CREATE TABLE IF NOT EXISTS `wiki_pins` (
  `club_id` INT NOT NULL,
  `page_id` INT NOT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`club_id`, `page_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
