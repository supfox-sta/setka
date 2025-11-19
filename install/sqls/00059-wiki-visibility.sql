-- Add visibility to wiki pages: 0=all,1=members,2=leaders only
ALTER TABLE `wiki_pages`
  ADD COLUMN `visibility` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_published`;
