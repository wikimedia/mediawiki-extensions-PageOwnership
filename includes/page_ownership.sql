

CREATE TABLE IF NOT EXISTS `page_ownership` (
  `id` int(11) NOT NULL,
  `created_by` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `usernames` tinytext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_id` int(11) NOT NULL,
  `role` enum('editor','admin','reader') COLLATE latin1_general_ci DEFAULT 'editor',
  `permissions` set('edit','create','manage properties','subpages') COLLATE latin1_general_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;



ALTER TABLE `page_ownership`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `page_ownership`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;




