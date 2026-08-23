/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `readings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `readings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `captured_at` datetime NOT NULL,
  `metric` varchar(24) NOT NULL,
  `value` decimal(12,3) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `phase` char(1) DEFAULT NULL,
  `src` varchar(48) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_metric_time` (`metric`,`captured_at`),
  KEY `idx_time` (`captured_at`)
) ENGINE=InnoDB AUTO_INCREMENT=65328 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `k` varchar(32) NOT NULL,
  `v` varchar(64) DEFAULT NULL,
  `descr` varchar(160) DEFAULT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cycle_baseline`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cycle_baseline` (
  `cycle_start` date NOT NULL,
  `metric` varchar(24) NOT NULL,
  `value` decimal(12,3) NOT NULL,
  `note` varchar(120) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`cycle_start`,`metric`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worker_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `worker_state` (
  `k` varchar(32) NOT NULL,
  `v` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;


-- ---------------------------------------------------------------------------
-- Seed data (hand-written, not dumped from a live install). Adjust the
-- tariff/billing values to match your own electricity board's rates before
-- relying on the cost estimates. `admin_key` gates settings.php — set your
-- own before exposing this publicly.
-- ---------------------------------------------------------------------------
INSERT INTO settings (k, v, descr) VALUES
  ('admin_key', 'change-me', 'Set a key to protect settings.php (blank = uses upload key)'),
  ('alert_email', '', 'Email address for alerts (uses server mail)'),
  ('alert_gap_min', '20', 'Alert if no camera frames for this many minutes'),
  ('billing_day', '1', 'Day of month the billing cycle starts/resets (bill paid)'),
  ('billing_metric', 'ip_cu_fd', 'Which cumulative register is the billed import'),
  ('currency', 'INR', 'Currency code for display'),
  ('export_metric', 'ep_cu_fd', 'Which register is solar export to grid'),
  ('fixed_charge', '0', 'Fixed monthly charge added to the bill — set to your tariff'),
  ('rate_per_kwh', '0', 'Flat tariff per kWh (used only if tariff_slabs is blank)'),
  ('subsidy', '0', 'Monthly subsidy deducted — most boards set this per-bill, not by formula'),
  ('surcharge_pct', '0', 'Regulatory surcharge %, applied to (energy charge + fixed charge) — verify against a real bill'),
  ('tariff_slabs', '', 'Slab tariff: size:rate,size:rate,...,*:rate (e.g. 100:2.90,100:4.20,*:7.90) — from your board''s published tariff'),
  ('telegram_chat', '', 'Telegram chat id to send alerts to'),
  ('telegram_token', '', 'Telegram bot token (from @BotFather)'),
  ('tz', 'Asia/Kolkata', 'Display timezone — set to your own');
