-- PayCow WHMCS Payment Gateway Schema
-- Optional manual execution script for administrators with restricted database permissions.
-- WHMCS administrators with standard privileges have this table initialized automatically
-- when viewing gateway configuration in the WHMCS Admin Area.

CREATE TABLE IF NOT EXISTS `mod_paycow_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL,
  `session_id` varchar(64) NOT NULL,
  `payment_reference` varchar(32) DEFAULT NULL,
  `network` varchar(16) NOT NULL,
  `currency` varchar(16) NOT NULL DEFAULT 'USDT',
  `amount` varchar(32) NOT NULL,
  `payable_amount` varchar(32) DEFAULT NULL,
  `tx_hash` varchar(128) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mod_paycow_sessions_session_id_unique` (`session_id`),
  KEY `mod_paycow_sessions_invoice_id_index` (`invoice_id`),
  KEY `mod_paycow_sessions_payment_reference_index` (`payment_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
