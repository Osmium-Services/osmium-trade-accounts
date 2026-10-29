ALTER TABLE `{PREFIX}trade_customers`
  CHANGE COLUMN `avatar` `logo_filename` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;
