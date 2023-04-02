

CREATE TABLE IF NOT EXISTS pageownership_permissions (
  `id` int(11) NOT NULL,
  `created_by` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `usernames` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `permissions_by_type` TEXT NULL,
  `permissions_by_group` TEXT NULL,
  `additional_rights` TEXT NULL,
  `add_permissions` TEXT NULL,
  `remove_permissions` TEXT NULL,
  `pages` TEXT NULL,
  `namespaces` TINYTEXT NULL,
  `updated_at` datetime NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;



ALTER TABLE pageownership_permissions
  ADD PRIMARY KEY (`id`);

ALTER TABLE pageownership_permissions
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;




