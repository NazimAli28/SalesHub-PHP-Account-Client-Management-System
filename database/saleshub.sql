-- =============================================================================
-- SalesHub — database schema + demo data
-- MariaDB 10.4+ / MySQL 8+
--
-- Import:  phpMyAdmin → Import → this file
--      or  mysql -u root < database/saleshub.sql
--
-- All data below is FICTIONAL demo data. Every demo login uses password: Demo@123
-- =============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `saleshub` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `saleshub`;

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- users — staff who log in. Roles: admin, support, tl, sales_executive
-- pc_number links a sales executive to the accounts assigned to their workstation.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'bcrypt hash (password_hash)',
  `role` enum('admin','support','tl','sales_executive') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `name` varchar(100) DEFAULT NULL,
  `pc_number` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` (`id`, `username`, `password`, `role`, `created_at`, `name`, `pc_number`) VALUES
(1, 'admin',   '$2y$10$0/ZMRS9lneLIS/LPvLy.3uyR2pHEfsV8YjJcUzeKOM6bQcMrFfTIK', 'admin',           '2026-01-05 09:00:00', 'Demo Admin',    ''),
(2, 'support', '$2y$10$vPxDDiZpDRDQ/k5DZqdQ5eMGAKWLmEJtGLZEhwxdOX3Xjs2Ibrnh6', 'support',         '2026-01-05 09:05:00', 'Demo Support',  ''),
(3, 'tl',      '$2y$10$R75DhChDoPahIz7cnuF.uuOucaJYLJAryN1g/2bOqjBJBKrcJps0i', 'tl',              '2026-01-05 09:10:00', 'Demo Team Lead',''),
(4, 'agent1',  '$2y$10$JmQNpw405Nly3tsOBy4/R.3LKVwDICWzyEyr6rZOX/oC9ngha9VXe', 'sales_executive', '2026-01-06 09:00:00', 'Alex Carter',   'U1 P1'),
(5, 'agent2',  '$2y$10$xa08/mikH3VDGdhwz4Y9Ze7OrzCb/M8WWPhSOWHktkMgyiRbBeGE.', 'sales_executive', '2026-01-06 09:05:00', 'Jordan Blake',  'U1 P2');

-- -----------------------------------------------------------------------------
-- accounts — pooled platform accounts assigned to sales workstations (pc_number)
-- standings: health of the account. pending_standings: change awaiting approval.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `accounts`;
CREATE TABLE `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `email_password` varchar(255) NOT NULL,
  `discord_email` varchar(255) DEFAULT NULL,
  `discord_password` varchar(255) DEFAULT NULL,
  `discord_account_date` date DEFAULT NULL,
  `discord_account_assigned_date` date DEFAULT NULL,
  `recovery_email` varchar(255) DEFAULT NULL,
  `recovery_phone_number` varchar(20) DEFAULT NULL,
  `phone_holder_name` varchar(255) DEFAULT NULL,
  `account_batch` date DEFAULT NULL,
  `agent_name` varchar(100) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `pc_number` varchar(50) DEFAULT NULL,
  `tl_name` varchar(255) DEFAULT NULL,
  `standings` enum('Active','Spam','Limited','Disabled','Violation') NOT NULL DEFAULT 'Active',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0: not assigned, 1: assigned',
  `has_client` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0: no client, 1: has client',
  `pending_standings` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `discord_email` (`discord_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `accounts` (`id`, `email`, `email_password`, `discord_email`, `discord_password`, `discord_account_date`, `discord_account_assigned_date`, `recovery_email`, `recovery_phone_number`, `phone_holder_name`, `account_batch`, `agent_name`, `unit`, `pc_number`, `tl_name`, `standings`, `status`, `has_client`, `pending_standings`) VALUES
(1,  'studio.acct01@example.com', 'demo-pass', 'studio.acct01@example.com', 'demo-pass', '2025-03-12', '2026-01-10', 'recovery01@example.com', '+10000000001', 'Holder One',   '2026-01-01', 'Alex Carter',  'U1', 'U1 P1', 'Demo Team Lead', 'Active',    1, 1, NULL),
(2,  'studio.acct02@example.com', 'demo-pass', 'studio.acct02@example.com', 'demo-pass', '2025-04-02', '2026-01-10', 'recovery02@example.com', '+10000000002', 'Holder Two',   '2026-01-01', 'Alex Carter',  'U1', 'U1 P1', 'Demo Team Lead', 'Active',    1, 1, NULL),
(3,  'studio.acct03@example.com', 'demo-pass', 'studio.acct03@example.com', 'demo-pass', '2025-05-19', '2026-02-03', 'recovery03@example.com', '+10000000003', 'Holder Three', '2026-02-01', 'Alex Carter',  'U1', 'U1 P1', 'Demo Team Lead', 'Limited',   1, 0, NULL),
(4,  'studio.acct04@example.com', 'demo-pass', 'studio.acct04@example.com', 'demo-pass', '2025-06-08', '2026-02-03', 'recovery04@example.com', '+10000000004', 'Holder Four',  '2026-02-01', 'Alex Carter',  'U1', 'U1 P1', 'Demo Team Lead', 'Active',    1, 0, 'Spam'),
(5,  'studio.acct05@example.com', 'demo-pass', 'studio.acct05@example.com', 'demo-pass', '2025-07-21', '2026-02-15', 'recovery05@example.com', '+10000000005', 'Holder Five',  '2026-02-01', 'Jordan Blake', 'U1', 'U1 P2', 'Demo Team Lead', 'Active',    1, 1, NULL),
(6,  'studio.acct06@example.com', 'demo-pass', 'studio.acct06@example.com', 'demo-pass', '2025-08-30', '2026-02-15', 'recovery06@example.com', '+10000000006', 'Holder Six',   '2026-03-01', 'Jordan Blake', 'U1', 'U1 P2', 'Demo Team Lead', 'Spam',      1, 0, NULL),
(7,  'studio.acct07@example.com', 'demo-pass', 'studio.acct07@example.com', 'demo-pass', '2025-09-14', '2026-03-04', 'recovery07@example.com', '+10000000007', 'Holder Seven', '2026-03-01', 'Jordan Blake', 'U1', 'U1 P2', 'Demo Team Lead', 'Active',    1, 1, NULL),
(8,  'studio.acct08@example.com', 'demo-pass', 'studio.acct08@example.com', 'demo-pass', '2025-10-01', NULL,         'recovery08@example.com', '+10000000008', 'Holder Eight', '2026-04-01', NULL,           NULL, NULL,    NULL,             'Active',    0, 0, NULL),
(9,  'studio.acct09@example.com', 'demo-pass', 'studio.acct09@example.com', 'demo-pass', '2025-10-11', NULL,         'recovery09@example.com', '+10000000009', 'Holder Nine',  '2026-04-01', NULL,           NULL, NULL,    NULL,             'Active',    0, 0, NULL),
(10, 'studio.acct10@example.com', 'demo-pass', 'studio.acct10@example.com', 'demo-pass', '2025-11-23', NULL,         'recovery10@example.com', '+10000000010', 'Holder Ten',   '2026-04-01', NULL,           NULL, NULL,    NULL,             'Disabled',  0, 0, NULL),
(11, 'studio.acct11@example.com', 'demo-pass', 'studio.acct11@example.com', 'demo-pass', '2025-12-05', NULL,         'recovery11@example.com', '+10000000011', 'Holder Eleven','2026-05-01', NULL,           NULL, NULL,    NULL,             'Violation', 0, 0, NULL),
(12, 'studio.acct12@example.com', 'demo-pass', 'studio.acct12@example.com', 'demo-pass', '2026-01-17', NULL,         'recovery12@example.com', '+10000000012', 'Holder Twelve','2026-05-01', NULL,           NULL, NULL,    NULL,             'Active',    0, 0, NULL);

-- -----------------------------------------------------------------------------
-- approvals — maker-checker queue. Agents submit changes; admin/support approve.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `approvals`;
CREATE TABLE `approvals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `table_name` varchar(64) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `changes_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`changes_json`)),
  `submitted_by` varchar(100) NOT NULL,
  `submitted_by_user_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` varchar(100) DEFAULT NULL,
  `reviewed_by_user_id` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `reviewer_comment` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `approvals` (`id`, `table_name`, `record_id`, `action`, `changes_json`, `submitted_by`, `submitted_by_user_id`, `status`, `reviewed_by`, `reviewed_by_user_id`, `reviewed_at`, `created_at`, `reviewer_comment`) VALUES
(1, 'accounts',   NULL, 'create', '{"num_accounts":3}', 'Jordan Blake', 5, 'pending',  NULL,           NULL, NULL,                  '2026-09-20 11:15:00', NULL),
(2, 'leads_data', 6,    'delete', '{"id":6}',           'Jordan Blake', 5, 'approved', 'Demo Support', 2,    '2026-09-18 16:40:00', '2026-09-18 15:02:00', 'Duplicate lead'),
(3, 'accounts',   NULL, 'create', '{"num_accounts":2}', 'Alex Carter',  4, 'rejected', 'Demo Admin',   1,    '2026-09-10 12:00:00', '2026-09-10 10:30:00', 'Use the current pool first');

-- -----------------------------------------------------------------------------
-- leads_data — prospects captured by sales executives
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `leads_data`;
CREATE TABLE `leads_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date DEFAULT NULL,
  `sales_executive` varchar(255) DEFAULT NULL,
  `pc_number` varchar(255) DEFAULT NULL,
  `closer_name` varchar(255) DEFAULT NULL,
  `discord_email` varchar(255) DEFAULT NULL,
  `client_discord_username` varchar(255) DEFAULT NULL,
  `client_email` varchar(255) DEFAULT NULL,
  `service_items` text DEFAULT NULL,
  `follow_up_stage` varchar(255) DEFAULT NULL,
  `last_message` text DEFAULT NULL,
  `scenario_if_lost` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `leads_data` (`id`, `date`, `sales_executive`, `pc_number`, `closer_name`, `discord_email`, `client_discord_username`, `client_email`, `service_items`, `follow_up_stage`, `last_message`, `scenario_if_lost`, `created_at`) VALUES
(1, '2026-09-15', 'Alex Carter',  'U1 P1', 'Alex Carter',  'studio.acct01@example.com', 'pixel_fox',     'pixelfox@example.com',   'Animated Logo,Emotes (Static)',          'Shows interest in portfolio', 'Shared portfolio samples',         NULL,                          '2026-09-15 10:12:00'),
(2, '2026-09-16', 'Alex Carter',  'U1 P1', 'Alex Carter',  'studio.acct02@example.com', 'nova_streams',  'nova@example.com',       'Twitch Stream Pack',                     'Show Interest in Payment',    'Quoted pricing for full pack',     NULL,                          '2026-09-16 13:40:00'),
(3, '2026-09-18', 'Alex Carter',  'U1 P1', 'Alex Carter',  'studio.acct03@example.com', 'retro_rabbit',  'retro@example.com',      'Logo/PFP,Banner',                        'Shows interest in portfolio', 'Asked for turnaround time',        NULL,                          '2026-09-18 09:05:00'),
(4, '2026-09-19', 'Jordan Blake', 'U1 P2', 'Jordan Blake', 'studio.acct05@example.com', 'gamer_owl',     'gamerowl@example.com',   'Overlay/ Screens / Banners',             'Show Interest in Payment',    'Sent invoice link',                NULL,                          '2026-09-19 17:22:00'),
(5, '2026-09-21', 'Jordan Blake', 'U1 P2', 'Jordan Blake', 'studio.acct07@example.com', 'moonlit_live',  'moonlit@example.com',    'Emotes (Animated),Animated Alerts',      'Shows interest in portfolio', 'Shared emote samples',             'Client needs more time',      '2026-09-21 11:48:00'),
(7, '2026-09-24', 'Jordan Blake', 'U1 P2', 'Jordan Blake', 'studio.acct05@example.com', 'arcade_kid',    'arcadekid@example.com',  'Mascot Logo',                            'Show Interest in Payment',    'Negotiating bundle discount',      NULL,                          '2026-09-24 14:30:00');

-- -----------------------------------------------------------------------------
-- client_retention — closed sales, upsell plans and payment follow-ups
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `client_retention`;
CREATE TABLE `client_retention` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` int(11) DEFAULT NULL,
  `sales_executive` varchar(255) DEFAULT NULL,
  `discord_email` varchar(255) DEFAULT NULL,
  `upsale_order_number` varchar(255) DEFAULT NULL,
  `pc_number` varchar(255) DEFAULT NULL,
  `fresh_sale_item` varchar(255) DEFAULT NULL,
  `first_sale_price` text DEFAULT NULL,
  `next_payment_date` date DEFAULT NULL,
  `closed_by` varchar(255) DEFAULT NULL,
  `team` varchar(255) DEFAULT NULL,
  `nurturing_sale` varchar(255) DEFAULT NULL,
  `plan_upcoming_sales` text DEFAULT NULL,
  `nurturing_rating` decimal(5,2) DEFAULT NULL,
  `items_left_upsale` text DEFAULT NULL,
  `client_discord_username` varchar(255) DEFAULT NULL,
  `client_name_payment` varchar(255) DEFAULT NULL,
  `expected_next_upsale_date` date DEFAULT NULL,
  `if_client_lost` text DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `client_retention` (`id`, `order_number`, `sales_executive`, `discord_email`, `upsale_order_number`, `pc_number`, `fresh_sale_item`, `first_sale_price`, `next_payment_date`, `closed_by`, `team`, `nurturing_sale`, `plan_upcoming_sales`, `nurturing_rating`, `items_left_upsale`, `client_discord_username`, `client_name_payment`, `expected_next_upsale_date`, `if_client_lost`, `comments`, `created_at`) VALUES
(1, 10231, 'Alex Carter',  'studio.acct01@example.com', '10240',       'U1 P1', 'Animated Logo',      'Total: $180\nReceived: $90\nRemaining: $90', '2026-09-30', 'Alex Carter',  'Unit 1 Alpha (Floor 3 Evening)', 'Nurture Sale', 'Emotes (Static), Animated Alerts', 85.00, 'Alerts',  'pixel_fox',    'P. Fox',     '2026-10-10', NULL, 'Happy with first draft',        '2026-09-12 12:00:00'),
(2, 10244, 'Alex Carter',  'studio.acct02@example.com', NULL,          'U1 P1', 'Twitch Stream Pack', 'Total: $350\nReceived: $350\nRemaining: $0', '2026-10-05', 'Alex Carter',  'Unit 1 Alpha (Floor 3 Evening)', 'Nurture Sale', 'Animated Stream Pack',            90.00, NULL,      'nova_streams', 'N. Streams', '2026-10-20', NULL, 'Paid in full',                  '2026-09-14 15:30:00'),
(3, 10259, 'Jordan Blake', 'studio.acct05@example.com', '10262\n10270','U1 P2', 'Logo/PFP',           'Total: $120\nReceived: $60\nRemaining: $60', '2026-09-27', 'Jordan Blake', 'Unit 2 Bravo (Floor 3 Evening)', 'Nurture Sale', 'Banner, Animated Logo',           75.00, 'Banner',  'gamer_owl',    'G. Owl',     '2026-10-02', NULL, 'Second installment due today',  '2026-09-16 10:10:00'),
(4, 10267, 'Jordan Blake', 'studio.acct07@example.com', NULL,          'U1 P2', 'Emotes (Animated)',  'Total: $90\nReceived: $90\nRemaining: $0',   NULL,         'Jordan Blake', 'Unit 2 Bravo (Floor 3 Evening)', 'Lost',         NULL,                              40.00, NULL,      'moonlit_live', 'M. Live',    NULL,         'Went with another studio', 'Offer discount next season', '2026-09-19 18:45:00');

-- -----------------------------------------------------------------------------
-- socials_data — social media accounts linked to a platform account
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS `socials_data`;
CREATE TABLE `socials_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `discord_email` varchar(255) NOT NULL,
  `social_account` varchar(255) NOT NULL,
  `username_email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `date_of_creation` date NOT NULL,
  `sales_executive` varchar(255) DEFAULT NULL,
  `pc_number` varchar(255) DEFAULT NULL,
  `is_using` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_discord_email` (`discord_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `socials_data` (`id`, `discord_email`, `social_account`, `username_email`, `password`, `date_of_creation`, `sales_executive`, `pc_number`, `is_using`, `created_at`) VALUES
(1, 'studio.acct01@example.com', 'Instagram', 'studio_art_01',  'demo-pass', '2026-01-12', 'Alex Carter',  'U1 P1', 1, '2026-01-12 10:00:00'),
(2, 'studio.acct01@example.com', 'X (Twitter)', 'studioart01', 'demo-pass', '2026-01-12', 'Alex Carter',  'U1 P1', 0, '2026-01-12 10:05:00'),
(3, 'studio.acct05@example.com', 'Instagram', 'studio_art_05',  'demo-pass', '2026-02-16', 'Jordan Blake', 'U1 P2', 1, '2026-02-16 09:30:00'),
(4, 'studio.acct07@example.com', 'Behance',   'studio.art07',   'demo-pass', '2026-03-05', 'Jordan Blake', 'U1 P2', 0, '2026-03-05 14:20:00');

COMMIT;
