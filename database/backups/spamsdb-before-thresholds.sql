-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: spamsdb
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `account_codes`
--

DROP TABLE IF EXISTS `account_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `account_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_code` varchar(50) NOT NULL,
  `account_name` varchar(150) NOT NULL,
  `account_group` enum('supply','asset','semi_expendable') NOT NULL DEFAULT 'asset',
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_codes_account_code` (`account_code`),
  UNIQUE KEY `uk_account_codes_account_name` (`account_name`),
  KEY `idx_account_codes_created_by` (`created_by`),
  KEY `idx_account_codes_updated_by` (`updated_by`),
  CONSTRAINT `fk_account_codes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_account_codes_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_codes`
--

LOCK TABLES `account_codes` WRITE;
/*!40000 ALTER TABLE `account_codes` DISABLE KEYS */;
INSERT INTO `account_codes` VALUES (1,'1-07-05-030','Information and Communication Technology Equipment','asset','Starter account code for ICT equipment',1,NULL,NULL,'2026-03-21 02:00:21',NULL),(2,'5-02-03-010','Office Supplies Expenses','supply','Starter account code for office supplies',1,NULL,NULL,'2026-03-21 02:00:21',NULL),(3,'1-06-07-010','Semi-Expendable Office Equipment','semi_expendable','Starter account code for semi-expendable equipment',1,NULL,NULL,'2026-03-21 02:00:21',NULL),(4,'5.02.03.210.01','Semi-Expendable ME - Machinery','semi_expendable','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:43:27',NULL),(5,'5.02.03.210.02','Semi-Expendable ME - Office Equipment','semi_expendable','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:43:27',NULL),(6,'5.02.03.210.03','Semi-Expendable ME - Information & Communications Technology Equipment','semi_expendable','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:43:27',NULL),(7,'5.02.03.210.04','Semi-Expendable ME - Agricultural and Forestry Equipment','semi_expendable','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:43:28',NULL),(8,'5.02.03.210.05','Semi-Expendable ME - Marine and Fishery Equipment','semi_expendable','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:43:28',NULL),(9,'5.02.03.210.07','Semi-Expendable ME - Communications Equipment','semi_expendable','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:43:28',NULL),(10,'5.02.03.210.08','Semi-Expendable ME - Disaster Response and Rescue Equipment','semi_expendable','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:43:28',NULL),(11,'5.02.03.210.10','Semi-Expendable ME - Medical Equipment','semi_expendable','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:43:28',NULL),(12,'5.02.03.210.12','Semi-Expendable ME - Sports Equipment','semi_expendable','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:43:28',NULL),(13,'5.02.03.210.13','Semi-Expendable ME - Technical and Scientific Equipment','semi_expendable','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:43:28',NULL),(14,'5.02.03.210.99','Semi-Expendable ME - Other Machinery and Equipment','semi_expendable','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:43:28',NULL),(15,'1.06.05.020.00','Office Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:34',NULL),(16,'1.06.05.030.00','Information and Communications Technology Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(17,'1.06.05.040.00','Agricultural and Forestry Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(18,'1.06.05.050.00','Marine and Fishery Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(19,'1.06.05.060.00','Airport Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(20,'1.06.05.070.00','Communication Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(21,'1.06.05.080.00','Construction and Heavy Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(22,'1.06.05.090.00','Disaster Response and Rescue Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(23,'1.06.05.100.00','Military, Police and Security Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(24,'1.06.05.110.00','Medical Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(25,'1.06.05.120.00','Printing Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(26,'1.06.05.130.00','Sports Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(27,'1.06.05.140.00','Technical and Scientific Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(28,'1.06.05.990.00','Other Machinery and Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(29,'1.06.06.990.00','Other Transportation Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL),(30,'1.06.99.990.00','Other Property, Plant and Equipment','asset','GAM seeded account code from provided chart screenshot',1,NULL,NULL,'2026-03-21 02:51:35',NULL);
/*!40000 ALTER TABLE `account_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `module_name` varchar(100) NOT NULL,
  `record_type` varchar(100) NOT NULL,
  `record_id` bigint(20) unsigned DEFAULT NULL,
  `action_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_user_id` (`user_id`),
  KEY `idx_audit_logs_module_name` (`module_name`),
  CONSTRAINT `fk_audit_logs_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `brands`
--

DROP TABLE IF EXISTS `brands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `brands` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `brand_code` varchar(30) NOT NULL,
  `brand_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_brands_brand_code` (`brand_code`),
  UNIQUE KEY `uk_brands_brand_name` (`brand_name`),
  KEY `idx_brands_created_by` (`created_by`),
  KEY `idx_brands_updated_by` (`updated_by`),
  CONSTRAINT `fk_brands_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_brands_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `brands`
--

LOCK TABLES `brands` WRITE;
/*!40000 ALTER TABLE `brands` DISABLE KEYS */;
INSERT INTO `brands` VALUES (1,'BRD-2026-0001','Acer','ICT equipment and laptop brand',1,NULL,NULL,'2026-03-21 04:02:14',NULL),(2,'BRD-2026-0002','Dell','ICT equipment and desktop/laptop brand',1,NULL,NULL,'2026-03-21 04:02:14',NULL),(3,'BRD-2026-0003','HP','Printers, desktops, laptops, and peripherals',1,NULL,NULL,'2026-03-21 04:02:14',NULL),(4,'BRD-2026-0004','Lenovo','Desktop and laptop brand',1,NULL,NULL,'2026-03-21 04:02:14',NULL),(5,'BRD-2026-0005','Epson','Printers and projectors',1,NULL,NULL,'2026-03-21 04:02:14',NULL),(6,'BRD-2026-0006','Canon','Printers and imaging equipment',1,NULL,NULL,'2026-03-21 04:02:14',NULL),(7,'BRD-2026-0007','Brother','Printers and office equipment',1,NULL,NULL,'2026-03-21 04:02:14',NULL),(8,'BRD-2026-0008','Asus','Laptop and ICT equipment brand',1,NULL,NULL,'2026-03-21 04:02:14',NULL),(9,'BRD-2026-0009','JBL','Audio and communication equipment',1,NULL,NULL,'2026-03-21 04:02:14',NULL),(10,'BRD-2026-0010','Samsung','Monitors, tablets, and ICT devices',1,NULL,NULL,'2026-03-21 04:02:14',NULL);
/*!40000 ALTER TABLE `brands` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_code` varchar(50) NOT NULL,
  `category_name` varchar(150) NOT NULL,
  `category_type` enum('supply','asset','semi_expendable') NOT NULL DEFAULT 'supply',
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_categories_category_code` (`category_code`),
  UNIQUE KEY `uk_categories_category_name` (`category_name`),
  KEY `idx_categories_created_by` (`created_by`),
  KEY `idx_categories_updated_by` (`updated_by`),
  CONSTRAINT `fk_categories_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_categories_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classifications`
--

DROP TABLE IF EXISTS `classifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `classifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `classification_code` varchar(50) NOT NULL,
  `classification_name` varchar(150) NOT NULL,
  `classification_group` enum('supply','asset','semi_expendable') NOT NULL DEFAULT 'asset',
  `account_code_id` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_classifications_classification_code` (`classification_code`),
  UNIQUE KEY `uk_classifications_classification_name` (`classification_name`),
  KEY `idx_classifications_created_by` (`created_by`),
  KEY `idx_classifications_updated_by` (`updated_by`),
  KEY `idx_classifications_account_code_id` (`account_code_id`),
  CONSTRAINT `fk_classifications_account_code_id` FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_classifications_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_classifications_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classifications`
--

LOCK TABLES `classifications` WRITE;
/*!40000 ALTER TABLE `classifications` DISABLE KEYS */;
INSERT INTO `classifications` VALUES (1,'CLS-2026-0001','Desktop Computer','asset',16,'Standard desktop workstation',1,NULL,NULL,'2026-03-21 00:46:45','2026-03-21 03:19:55'),(2,'CLS-2026-0002','Projector','asset',15,'Projection equipment for meetings and classrooms',1,NULL,NULL,'2026-03-21 00:46:46','2026-03-21 03:19:55'),(3,'CLS-2026-0003','Laptop Computer','asset',16,'Portable computer equipment',1,NULL,NULL,'2026-03-21 03:11:54',NULL),(4,'CLS-2026-0004','Printer','asset',25,'Office and network printer equipment',1,NULL,NULL,'2026-03-21 03:11:54',NULL),(5,'CLS-2026-0005','Office Chair','asset',15,'Office seating and furniture-type equipment under office equipment',1,NULL,NULL,'2026-03-21 03:11:54',NULL),(6,'CLS-2026-0006','Steel Filing Cabinet','asset',15,'Records storage cabinet',1,NULL,NULL,'2026-03-21 03:11:55',NULL),(7,'CLS-2026-0007','Router and Network Device','asset',20,'Network routing and connectivity device',1,NULL,NULL,'2026-03-21 03:11:55',NULL),(8,'CLS-2026-0008','Medical Diagnostic Device','asset',24,'Medical diagnostic or clinic equipment',1,NULL,NULL,'2026-03-21 03:11:55',NULL),(9,'CLS-2026-0009','Bond Paper','supply',2,'Common office paper supply',1,NULL,NULL,'2026-03-21 03:11:55',NULL),(10,'CLS-2026-0010','Printer Ink and Toner','supply',2,'Printing consumables',1,NULL,NULL,'2026-03-21 03:11:55',NULL),(11,'CLS-2026-0011','Cleaning Supplies','supply',2,'Janitorial and sanitation supplies',1,NULL,NULL,'2026-03-21 03:11:55',NULL),(12,'CLS-2026-0012','Semi-Expendable Office Equipment','semi_expendable',5,'Semi-expendable office equipment items',1,NULL,NULL,'2026-03-21 03:11:55',NULL),(13,'CLS-2026-0013','Semi-Expendable ICT Equipment','semi_expendable',6,'Semi-expendable information and communications equipment',1,NULL,NULL,'2026-03-21 03:11:55',NULL),(14,'CLS-2026-0014','Semi-Expendable Communications Equipment','semi_expendable',9,'Semi-expendable communication devices',1,NULL,NULL,'2026-03-21 03:11:55',NULL);
/*!40000 ALTER TABLE `classifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_departments_code` (`code`),
  UNIQUE KEY `uk_departments_name` (`name`),
  KEY `idx_departments_created_by` (`created_by`),
  KEY `idx_departments_updated_by` (`updated_by`),
  CONSTRAINT `fk_departments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_departments_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `distribution_item_details`
--

DROP TABLE IF EXISTS `distribution_item_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `distribution_item_details` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `distribution_item_id` bigint(20) unsigned NOT NULL,
  `receiving_item_detail_id` bigint(20) unsigned DEFAULT NULL,
  `brand` varchar(150) DEFAULT NULL,
  `model` varchar(150) DEFAULT NULL,
  `serial_no` varchar(150) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_distribution_item_details_distribution_item_id` (`distribution_item_id`),
  KEY `idx_distribution_item_details_receiving_item_detail_id` (`receiving_item_detail_id`),
  CONSTRAINT `fk_distribution_item_details_distribution_item_id` FOREIGN KEY (`distribution_item_id`) REFERENCES `distribution_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_distribution_item_details_receiving_item_detail_id` FOREIGN KEY (`receiving_item_detail_id`) REFERENCES `receiving_item_details` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `distribution_item_details`
--

LOCK TABLES `distribution_item_details` WRITE;
/*!40000 ALTER TABLE `distribution_item_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `distribution_item_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `distribution_items`
--

DROP TABLE IF EXISTS `distribution_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `distribution_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `distribution_id` bigint(20) unsigned NOT NULL,
  `receiving_item_id` bigint(20) unsigned NOT NULL,
  `issuance_item_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_distributed` decimal(14,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_distribution_items_distribution_id` (`distribution_id`),
  KEY `idx_distribution_items_receiving_item_id` (`receiving_item_id`),
  KEY `idx_distribution_items_issuance_item_id` (`issuance_item_id`),
  CONSTRAINT `fk_distribution_items_distribution_id` FOREIGN KEY (`distribution_id`) REFERENCES `distributions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_distribution_items_issuance_item_id` FOREIGN KEY (`issuance_item_id`) REFERENCES `issuance_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_distribution_items_receiving_item_id` FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `distribution_items`
--

LOCK TABLES `distribution_items` WRITE;
/*!40000 ALTER TABLE `distribution_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `distribution_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `distributions`
--

DROP TABLE IF EXISTS `distributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `distributions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `document_type` enum('ics','par') NOT NULL,
  `document_no` varchar(50) NOT NULL,
  `distribution_date` date NOT NULL,
  `office_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('posted','cancelled') NOT NULL DEFAULT 'posted',
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_distributions_system_reference` (`system_reference`),
  UNIQUE KEY `uk_distributions_document_no` (`document_no`),
  KEY `idx_distributions_office_id` (`office_id`),
  KEY `idx_distributions_employee_id` (`employee_id`),
  KEY `idx_distributions_created_by` (`created_by`),
  KEY `idx_distributions_updated_by` (`updated_by`),
  CONSTRAINT `fk_distributions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_distributions_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_distributions_office_id` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_distributions_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `distributions`
--

LOCK TABLES `distributions` WRITE;
/*!40000 ALTER TABLE `distributions` DISABLE KEYS */;
/*!40000 ALTER TABLE `distributions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_no` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix_name` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `office_id` bigint(20) unsigned DEFAULT NULL,
  `responsibility_code_id` bigint(20) unsigned DEFAULT NULL,
  `position_title` varchar(150) DEFAULT NULL,
  `employment_status` varchar(50) DEFAULT NULL,
  `is_unit_head` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employees_employee_no` (`employee_no`),
  UNIQUE KEY `uk_employees_email` (`email`),
  KEY `idx_employees_department_id` (`department_id`),
  KEY `idx_employees_created_by` (`created_by`),
  KEY `idx_employees_updated_by` (`updated_by`),
  KEY `idx_employees_office_id` (`office_id`),
  KEY `idx_employees_responsibility_code_id` (`responsibility_code_id`),
  CONSTRAINT `fk_employees_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_employees_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_employees_office_id` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_employees_responsibility_code_id` FOREIGN KEY (`responsibility_code_id`) REFERENCES `responsibility_codes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_employees_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
INSERT INTO `employees` VALUES (12,'EMP-0001','Juan','D.','Santos',NULL,NULL,NULL,12,2,'University Accountant',NULL,0,1,NULL,NULL,'2026-03-22 04:47:54','2026-03-22 04:50:28'),(13,'EMP-0002','Maria','L.','Reyes',NULL,NULL,NULL,13,3,'Finance Officer',NULL,0,1,NULL,NULL,'2026-03-22 04:47:55','2026-03-22 04:50:28'),(14,'EMP-0003','Pedro','A.','Gonzalez',NULL,NULL,NULL,14,4,'IT Administrator',NULL,0,1,NULL,NULL,'2026-03-22 04:47:55','2026-03-22 04:50:28');
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `funds`
--

DROP TABLE IF EXISTS `funds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `funds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fund_code` varchar(50) NOT NULL,
  `fund_name` varchar(150) NOT NULL,
  `fund_source` varchar(150) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_funds_fund_code` (`fund_code`),
  UNIQUE KEY `uk_funds_fund_name` (`fund_name`),
  KEY `idx_funds_created_by` (`created_by`),
  KEY `idx_funds_updated_by` (`updated_by`),
  CONSTRAINT `fk_funds_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_funds_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `funds`
--

LOCK TABLES `funds` WRITE;
/*!40000 ALTER TABLE `funds` DISABLE KEYS */;
INSERT INTO `funds` VALUES (1,'FND-2026-0001','General Fund','Government Appropriations','Default general fund for regular procurement',1,NULL,NULL,'2026-03-21 01:46:53',NULL),(2,'GAA-GAS','General Fund - General Administration and Support','01','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:11',NULL),(3,'TICT','Tuition Fees - Common Fund','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(4,'IGP','Income Generating Projects','06','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(5,'GAA-STO','General Fund - Support to Operations','01','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(6,'GAA-HEP','General Fund - Higher Education Program','01','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(7,'GAA-RP','General Fund - Research Program','01','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(8,'GAA-TAEP','General Fund - Technical Advisory Extension Program','01','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(9,'GAA-AEP','General Fund - Advanced Education Program','01','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(10,'THETP','Tuition Fees - Higher Education Program','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(11,'TNSTP','Tuition Fees - National Service Training Program','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(12,'TOL','Tuition Fees - Open Learning Center','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(13,'TPR','Tuition Fees - Production','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(14,'TGS','Tuition Fees - Graduate School','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(15,'TRP','Tuition Fees - Research Program','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(16,'TTAEP','Tuition Fees - Technical Advisory Extension Program','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(17,'OI','Other Income','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:12',NULL),(18,'AADM','Admission Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(19,'ACEF','Certification Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(20,'ADEP','Diploma','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(21,'AENT','Entrance Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(22,'AFAP','Fines and Penalties','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(23,'AIREG','Registration Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(24,'ASID','Student ID','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(25,'ATOR','Transcript of Records','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(26,'BOND','Performance/Security Bond','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(27,'CIT','Tuition Fees - Certificate in Teaching','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(28,'COCN','Cocoon - Main Campus Yearbook','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(29,'ECED','Tuition Fees - Early Childhood Education','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(30,'LCAL','Laboratory Fees - Calawag Extension Campus','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(31,'LCL','Crime Lab Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(32,'LCUR','Recreational, Social and Cultural Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(33,'LDEP','Department Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(34,'LEL','Engineering Lab Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(35,'LFLP','LP Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(36,'LGS','Graduate School Journal','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(37,'LGUI','Guidance Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(38,'LHB','Handbook Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(39,'LIT','Computer Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(40,'LHS','Student Development Fee - Lab High School','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(41,'LLF','Library Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:13',NULL),(42,'LLL','Laboratory Fees - Libertad Extension Campus','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:14',NULL),(43,'LMAINT','Maintenance and Development Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:14',NULL),(44,'LMDI','Medical/Dental Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:14',NULL),(45,'LML','Maritime Lab Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:14',NULL),(46,'LOT','Practicum Fee','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:14',NULL),(47,'TOLM','Module Fee - Open Learning','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:14',NULL),(48,'TPRISM','The Prism','05','GAM seeded fund code from provided screenshot',1,NULL,NULL,'2026-03-21 02:54:14',NULL);
/*!40000 ALTER TABLE `funds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `issuance_items`
--

DROP TABLE IF EXISTS `issuance_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `issuance_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `issuance_id` bigint(20) unsigned NOT NULL,
  `stock_item_id` bigint(20) unsigned NOT NULL,
  `quantity_issued` decimal(14,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_issuance_items_issuance_id` (`issuance_id`),
  KEY `idx_issuance_items_stock_item_id` (`stock_item_id`),
  CONSTRAINT `fk_issuance_items_issuance_id` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_issuance_items_stock_item_id` FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `issuance_items`
--

LOCK TABLES `issuance_items` WRITE;
/*!40000 ALTER TABLE `issuance_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `issuance_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `issuances`
--

DROP TABLE IF EXISTS `issuances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `issuances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `issuance_date` date NOT NULL,
  `office_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'posted',
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_issuances_system_reference` (`system_reference`),
  KEY `idx_issuances_office_id` (`office_id`),
  KEY `idx_issuances_employee_id` (`employee_id`),
  KEY `idx_issuances_created_by` (`created_by`),
  KEY `idx_issuances_updated_by` (`updated_by`),
  CONSTRAINT `fk_issuances_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_issuances_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_issuances_office_id` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_issuances_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `issuances`
--

LOCK TABLES `issuances` WRITE;
/*!40000 ALTER TABLE `issuances` DISABLE KEYS */;
/*!40000 ALTER TABLE `issuances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mode_of_procurements`
--

DROP TABLE IF EXISTS `mode_of_procurements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mode_of_procurements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mode_code` varchar(50) NOT NULL,
  `mode_name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mode_of_procurements_mode_code` (`mode_code`),
  UNIQUE KEY `uk_mode_of_procurements_mode_name` (`mode_name`),
  KEY `idx_mode_of_procurements_created_by` (`created_by`),
  KEY `idx_mode_of_procurements_updated_by` (`updated_by`),
  CONSTRAINT `fk_mode_of_procurements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_mode_of_procurements_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mode_of_procurements`
--

LOCK TABLES `mode_of_procurements` WRITE;
/*!40000 ALTER TABLE `mode_of_procurements` DISABLE KEYS */;
INSERT INTO `mode_of_procurements` VALUES (1,'MOP-2026-0001','Public Bidding','Competitive public bidding process',1,NULL,NULL,'2026-03-21 01:39:20',NULL),(2,'MOP-2026-0002','Shopping','Procurement through shopping under approved thresholds',1,NULL,NULL,'2026-03-21 01:39:20',NULL),(3,'MOP-2026-0003','Small Value Procurement','Procurement for small value requirements',1,NULL,NULL,'2026-03-21 01:39:20',NULL),(4,'MOP-2026-0004','Negotiated Procurement','Negotiated procurement mode',1,NULL,NULL,'2026-03-21 01:39:20',NULL),(5,'MOP-2026-0005','Direct Contracting','Direct contracting procurement mode',1,NULL,NULL,'2026-03-21 01:39:20',NULL);
/*!40000 ALTER TABLE `mode_of_procurements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `models`
--

DROP TABLE IF EXISTS `models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `models` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `brand_id` bigint(20) unsigned NOT NULL,
  `model_code` varchar(30) NOT NULL,
  `model_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_models_model_code` (`model_code`),
  UNIQUE KEY `uk_models_brand_model_name` (`brand_id`,`model_name`),
  KEY `idx_models_brand_id` (`brand_id`),
  KEY `idx_models_created_by` (`created_by`),
  KEY `idx_models_updated_by` (`updated_by`),
  CONSTRAINT `fk_models_brand_id` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_models_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_models_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `models`
--

LOCK TABLES `models` WRITE;
/*!40000 ALTER TABLE `models` DISABLE KEYS */;
INSERT INTO `models` VALUES (1,1,'MDL-2026-0001','Aspire 7','Laptop model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(2,1,'MDL-2026-0002','Veriton','Desktop computer model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(3,2,'MDL-2026-0003','OptiPlex','Desktop computer model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(4,2,'MDL-2026-0004','Latitude','Laptop model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(5,3,'MDL-2026-0005','LaserJet Pro','Printer model series',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(6,3,'MDL-2026-0006','ProBook','Laptop model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(7,4,'MDL-2026-0007','ThinkPad','Laptop model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(8,4,'MDL-2026-0008','ThinkCentre','Desktop computer model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(9,5,'MDL-2026-0009','L3210','Printer model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(10,5,'MDL-2026-0010','EB-X06','Projector model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(11,6,'MDL-2026-0011','PIXMA G2010','Printer model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(12,7,'MDL-2026-0012','DCP-T420W','Printer model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(13,8,'MDL-2026-0013','VivoBook','Laptop model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(14,9,'MDL-2026-0014','IRX108BT','Portable speaker model',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(15,10,'MDL-2026-0015','ViewFinity','Monitor model series',1,NULL,NULL,'2026-03-21 04:02:45',NULL),(16,10,'MDL-2026-0016','Galaxy Tab','Tablet model series',1,NULL,NULL,'2026-03-21 04:02:45',NULL);
/*!40000 ALTER TABLE `models` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `offices`
--

DROP TABLE IF EXISTS `offices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `offices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `office_code` varchar(50) NOT NULL,
  `office_name` varchar(150) NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `office_head_employee_id` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_offices_office_code` (`office_code`),
  UNIQUE KEY `uk_offices_office_name` (`office_name`),
  KEY `idx_offices_department_id` (`department_id`),
  KEY `idx_offices_created_by` (`created_by`),
  KEY `idx_offices_updated_by` (`updated_by`),
  KEY `idx_offices_office_head_employee_id` (`office_head_employee_id`),
  CONSTRAINT `fk_offices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_offices_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_offices_office_head_employee_id` FOREIGN KEY (`office_head_employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_offices_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `offices`
--

LOCK TABLES `offices` WRITE;
/*!40000 ALTER TABLE `offices` DISABLE KEYS */;
INSERT INTO `offices` VALUES (12,'OFF-ADMIN','Office of the Registrar',NULL,NULL,NULL,1,NULL,NULL,'2026-03-22 04:50:27',NULL),(13,'OFF-FIN','Finance Office',NULL,NULL,NULL,1,NULL,NULL,'2026-03-22 04:50:27',NULL),(14,'OFF-IT','Information Technology Office',NULL,NULL,NULL,1,NULL,NULL,'2026-03-22 04:50:27',NULL),(15,'OFF-HR','Human Resources Office',NULL,NULL,NULL,1,NULL,NULL,'2026-03-22 04:50:27',NULL);
/*!40000 ALTER TABLE `offices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_items`
--

DROP TABLE IF EXISTS `purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `line_no` int(10) unsigned NOT NULL,
  `item_type` enum('supply','semi_expendable','equipment') NOT NULL DEFAULT 'supply',
  `account_code_id` bigint(20) unsigned DEFAULT NULL,
  `classification_id` bigint(20) unsigned DEFAULT NULL,
  `item_description` text NOT NULL,
  `quantity` decimal(14,2) NOT NULL DEFAULT 0.00,
  `unit_of_measure_id` bigint(20) unsigned DEFAULT NULL,
  `unit_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_purchase_order_items_purchase_order_id` (`purchase_order_id`),
  KEY `idx_purchase_order_items_classification_id` (`classification_id`),
  KEY `idx_purchase_order_items_unit_of_measure_id` (`unit_of_measure_id`),
  KEY `idx_purchase_order_items_account_code_id` (`account_code_id`),
  CONSTRAINT `fk_purchase_order_items_account_code_id` FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_order_items_classification_id` FOREIGN KEY (`classification_id`) REFERENCES `classifications` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_order_items_purchase_order_id` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_order_items_unit_of_measure_id` FOREIGN KEY (`unit_of_measure_id`) REFERENCES `unit_of_measures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_items`
--

LOCK TABLES `purchase_order_items` WRITE;
/*!40000 ALTER TABLE `purchase_order_items` DISABLE KEYS */;
INSERT INTO `purchase_order_items` VALUES (1,1,1,'supply',2,9,'8.5 x 13',250.00,6,500.00,125000.00,'2026-03-21 03:33:06',NULL),(2,1,2,'semi_expendable',5,13,'Printer',10.00,2,25000.00,250000.00,'2026-03-21 03:33:07',NULL),(3,1,3,'equipment',1,1,'Desktop Computer',20.00,2,55000.00,1100000.00,'2026-03-21 03:33:07',NULL),(4,2,1,'supply',2,9,'Inserted item',2.00,1,50.00,100.00,'2026-03-22 09:32:33',NULL),(5,3,1,'supply',2,9,'A4',250.00,6,250.00,62500.00,'2026-03-22 09:37:07',NULL),(6,3,2,'semi_expendable',5,12,'Printer',25.00,2,25000.00,625000.00,'2026-03-22 09:37:07',NULL),(7,3,3,'equipment',16,1,'Desktop Computer',50.00,2,55000.00,2750000.00,'2026-03-22 09:37:07',NULL);
/*!40000 ALTER TABLE `purchase_order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `po_number` varchar(100) NOT NULL,
  `po_date` date NOT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `fund_id` bigint(20) unsigned NOT NULL,
  `supplier_address` varchar(255) DEFAULT NULL,
  `mode_of_procurement_id` bigint(20) unsigned NOT NULL,
  `mode_of_procurement` varchar(100) DEFAULT NULL,
  `place_of_delivery` varchar(255) NOT NULL DEFAULT 'University of Antique',
  `delivery_term_days` int(10) unsigned DEFAULT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `office_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('encoded','verified','cancelled') NOT NULL DEFAULT 'encoded',
  `purpose` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchase_orders_system_reference` (`system_reference`),
  UNIQUE KEY `uk_purchase_orders_po_number` (`po_number`),
  KEY `idx_purchase_orders_supplier_id` (`supplier_id`),
  KEY `idx_purchase_orders_office_id` (`office_id`),
  KEY `idx_purchase_orders_created_by` (`created_by`),
  KEY `idx_purchase_orders_updated_by` (`updated_by`),
  KEY `idx_purchase_orders_mode_of_procurement_id` (`mode_of_procurement_id`),
  KEY `idx_purchase_orders_fund_id` (`fund_id`),
  CONSTRAINT `fk_purchase_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_orders_fund_id` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_orders_mode_of_procurement_id` FOREIGN KEY (`mode_of_procurement_id`) REFERENCES `mode_of_procurements` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_orders_supplier_id` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_orders_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_orders`
--

LOCK TABLES `purchase_orders` WRITE;
/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
INSERT INTO `purchase_orders` VALUES (1,'POREC-2026-0001','1111','2026-03-21',2,18,'San Jose, Antique',3,NULL,'University of Antique',30,'2026-04-20',NULL,'encoded',NULL,NULL,1475000.00,1,NULL,'2026-03-21 03:33:06',NULL),(2,'POREC-2026-0002','TEST-PO-INSERT','2026-03-22',1,1,'123 Main St',1,NULL,'University of Antique',0,'2026-03-22',NULL,'encoded',NULL,NULL,100.00,1,NULL,'2026-03-22 09:32:33',NULL),(3,'POREC-2026-0003','2222','2026-03-22',2,21,'San Jose, Antique',3,NULL,'University of Antique',30,'2026-04-21',NULL,'encoded',NULL,NULL,3437500.00,1,NULL,'2026-03-22 09:37:07',NULL);
/*!40000 ALTER TABLE `purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `receiving_item_details`
--

DROP TABLE IF EXISTS `receiving_item_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receiving_item_details` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receiving_item_id` bigint(20) unsigned NOT NULL,
  `brand_id` bigint(20) unsigned DEFAULT NULL,
  `model_id` bigint(20) unsigned DEFAULT NULL,
  `brand` varchar(150) DEFAULT NULL,
  `model` varchar(150) DEFAULT NULL,
  `serial_no` varchar(150) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `is_distributed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_receiving_item_details_receiving_item_id` (`receiving_item_id`),
  KEY `fk_receiving_item_details_brand_id` (`brand_id`),
  KEY `fk_receiving_item_details_model_id` (`model_id`),
  CONSTRAINT `fk_receiving_item_details_brand_id` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_receiving_item_details_model_id` FOREIGN KEY (`model_id`) REFERENCES `models` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_receiving_item_details_receiving_item_id` FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `receiving_item_details`
--

LOCK TABLES `receiving_item_details` WRITE;
/*!40000 ALTER TABLE `receiving_item_details` DISABLE KEYS */;
INSERT INTO `receiving_item_details` VALUES (6,21,7,12,'Brother','DCP-T420W','111','',0,'2026-03-23 00:20:51',NULL),(7,22,1,1,'Acer','Aspire 7','111','',0,'2026-03-23 00:20:51',NULL);
/*!40000 ALTER TABLE `receiving_item_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `receiving_items`
--

DROP TABLE IF EXISTS `receiving_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receiving_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receiving_id` bigint(20) unsigned NOT NULL,
  `purchase_order_item_id` bigint(20) unsigned NOT NULL,
  `quantity_delivered` decimal(14,2) NOT NULL DEFAULT 0.00,
  `quantity_accepted` decimal(14,2) NOT NULL DEFAULT 0.00,
  `quantity_rejected` decimal(14,2) NOT NULL DEFAULT 0.00,
  `item_condition` varchar(100) DEFAULT NULL,
  `unit_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_receiving_items_receiving_id` (`receiving_id`),
  KEY `idx_receiving_items_purchase_order_item_id` (`purchase_order_item_id`),
  CONSTRAINT `fk_receiving_items_purchase_order_item_id` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_receiving_items_receiving_id` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `receiving_items`
--

LOCK TABLES `receiving_items` WRITE;
/*!40000 ALTER TABLE `receiving_items` DISABLE KEYS */;
INSERT INTO `receiving_items` VALUES (1,1,1,200.00,200.00,0.00,'Good Condition',500.00,100000.00,'','2026-03-21 03:33:59','2026-03-21 03:50:40'),(2,1,2,5.00,5.00,0.00,'Good Condition',25000.00,125000.00,'','2026-03-21 03:33:59','2026-03-21 03:50:40'),(3,1,3,2.00,2.00,0.00,'Good Condition',55000.00,110000.00,'','2026-03-21 03:33:59','2026-03-21 03:50:40'),(20,18,5,100.00,100.00,0.00,'Good Condition',250.00,25000.00,'','2026-03-23 00:20:51',NULL),(21,18,6,5.00,5.00,0.00,'Good Condition',25000.00,125000.00,'','2026-03-23 00:20:51',NULL),(22,18,7,10.00,10.00,0.00,'Good Condition',55000.00,550000.00,'','2026-03-23 00:20:51',NULL);
/*!40000 ALTER TABLE `receiving_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `receivings`
--

DROP TABLE IF EXISTS `receivings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receivings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `ris_no` varchar(100) DEFAULT NULL,
  `received_date` date NOT NULL,
  `delivery_receipt_no` varchar(100) DEFAULT NULL,
  `invoice_no` varchar(100) DEFAULT NULL,
  `status` enum('draft','partial','completed','cancelled') NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `total_received_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_receivings_system_reference` (`system_reference`),
  KEY `idx_receivings_purchase_order_id` (`purchase_order_id`),
  KEY `idx_receivings_created_by` (`created_by`),
  KEY `idx_receivings_updated_by` (`updated_by`),
  CONSTRAINT `fk_receivings_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_receivings_purchase_order_id` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_receivings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `receivings`
--

LOCK TABLES `receivings` WRITE;
/*!40000 ALTER TABLE `receivings` DISABLE KEYS */;
INSERT INTO `receivings` VALUES (1,'RCV-2026-0001',1,NULL,'2026-03-21','','','partial','',335000.00,1,NULL,'2026-03-21 03:33:58','2026-03-21 03:50:37'),(18,'RCV-2026-0002',3,'RIS-2026-03-0001','2026-03-23','','','partial','',700000.00,1,NULL,'2026-03-23 00:20:51',NULL);
/*!40000 ALTER TABLE `receivings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `responsibility_codes`
--

DROP TABLE IF EXISTS `responsibility_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `responsibility_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `office_id` bigint(20) unsigned NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_responsibility_codes_office_code` (`office_id`,`code`),
  KEY `idx_responsibility_codes_created_by` (`created_by`),
  KEY `idx_responsibility_codes_updated_by` (`updated_by`),
  CONSTRAINT `fk_responsibility_codes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_responsibility_codes_office_id` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_responsibility_codes_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `responsibility_codes`
--

LOCK TABLES `responsibility_codes` WRITE;
/*!40000 ALTER TABLE `responsibility_codes` DISABLE KEYS */;
INSERT INTO `responsibility_codes` VALUES (2,12,'RC-ADM-01','Admin Responsibility Code',1,NULL,NULL,'2026-03-22 04:50:27',NULL),(3,13,'RC-FIN-01','Finance Responsibility Code',1,NULL,NULL,'2026-03-22 04:50:27',NULL),(4,14,'RC-IT-01','IT Responsibility Code',1,NULL,NULL,'2026-03-22 04:50:27',NULL),(5,15,'RC-HR-01','HR Responsibility Code',1,NULL,NULL,'2026-03-22 04:50:27',NULL);
/*!40000 ALTER TABLE `responsibility_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Administrator','Full system access',1,'2026-03-20 23:50:54',NULL);
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `series_numbers`
--

DROP TABLE IF EXISTS `series_numbers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `series_numbers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(100) NOT NULL,
  `prefix` varchar(30) NOT NULL,
  `year_value` smallint(5) unsigned DEFAULT NULL,
  `current_value` int(10) unsigned NOT NULL DEFAULT 0,
  `padding_length` tinyint(3) unsigned NOT NULL DEFAULT 4,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_series_numbers_module_key` (`module_key`)
) ENGINE=InnoDB AUTO_INCREMENT=469 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `series_numbers`
--

LOCK TABLES `series_numbers` WRITE;
/*!40000 ALTER TABLE `series_numbers` DISABLE KEYS */;
INSERT INTO `series_numbers` VALUES (1,'departments','DEP',2026,0,4,'2026-03-21 00:18:41',NULL),(2,'offices','OFF',2026,0,4,'2026-03-21 00:18:41',NULL),(3,'employees','EMP',2026,0,4,'2026-03-21 00:18:41',NULL),(4,'suppliers','SUP',2026,5,4,'2026-03-21 00:18:42','2026-03-21 03:11:55'),(13,'classifications','CLS',2026,14,4,'2026-03-21 00:46:45','2026-03-21 03:11:55'),(14,'unit_of_measures','UOM',2026,10,4,'2026-03-21 00:53:07','2026-03-21 03:11:55'),(28,'purchase_orders','POREC',2026,3,4,'2026-03-21 01:00:39','2026-03-22 09:37:07'),(41,'mode_of_procurements','MOP',2026,0,4,'2026-03-21 01:39:20',NULL),(43,'funds','FND',2026,0,4,'2026-03-21 01:46:53',NULL),(58,'receivings','RCV',2026,2,4,'2026-03-21 03:06:18','2026-03-23 00:20:51'),(114,'brands','BRD',2026,10,4,'2026-03-21 03:58:19','2026-03-21 04:02:45'),(115,'models','MDL',2026,16,4,'2026-03-21 03:58:19','2026-03-21 04:02:45'),(150,'stock_items','STK',2026,4,4,'2026-03-21 04:58:59','2026-03-23 00:20:51'),(151,'issuances','ISS',2026,0,4,'2026-03-21 04:58:59',NULL),(156,'distributions','DST',2026,0,4,'2026-03-21 05:07:45',NULL);
/*!40000 ALTER TABLE `series_numbers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_items`
--

DROP TABLE IF EXISTS `stock_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `receiving_id` bigint(20) unsigned DEFAULT NULL,
  `receiving_item_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_order_item_id` bigint(20) unsigned DEFAULT NULL,
  `item_type` enum('supply','semi_expendable','equipment') NOT NULL DEFAULT 'supply',
  `account_code_id` bigint(20) unsigned DEFAULT NULL,
  `classification_id` bigint(20) unsigned DEFAULT NULL,
  `unit_of_measure_id` bigint(20) unsigned DEFAULT NULL,
  `item_description` text NOT NULL,
  `unit_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `quantity_received` decimal(14,2) NOT NULL DEFAULT 0.00,
  `quantity_issued` decimal(14,2) NOT NULL DEFAULT 0.00,
  `quantity_on_hand` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stock_items_system_reference` (`system_reference`),
  UNIQUE KEY `uk_stock_items_receiving_item_id` (`receiving_item_id`),
  KEY `idx_stock_items_receiving_id` (`receiving_id`),
  KEY `idx_stock_items_purchase_order_item_id` (`purchase_order_item_id`),
  KEY `idx_stock_items_account_code_id` (`account_code_id`),
  KEY `idx_stock_items_classification_id` (`classification_id`),
  KEY `idx_stock_items_unit_of_measure_id` (`unit_of_measure_id`),
  KEY `idx_stock_items_created_by` (`created_by`),
  KEY `idx_stock_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_stock_items_account_code_id` FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_items_classification_id` FOREIGN KEY (`classification_id`) REFERENCES `classifications` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_items_purchase_order_item_id` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_items_receiving_id` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_items_receiving_item_id` FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_items_unit_of_measure_id` FOREIGN KEY (`unit_of_measure_id`) REFERENCES `unit_of_measures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_items`
--

LOCK TABLES `stock_items` WRITE;
/*!40000 ALTER TABLE `stock_items` DISABLE KEYS */;
INSERT INTO `stock_items` VALUES (1,'STK-2026-0001',1,1,1,'supply',2,9,6,'8.5 x 13',500.00,200.00,0.00,200.00,1,NULL,'2026-03-21 03:33:58','2026-03-21 03:50:37'),(18,'STK-2026-0002',18,20,5,'supply',2,9,6,'A4',250.00,100.00,0.00,100.00,1,NULL,'2026-03-23 00:20:51',NULL),(19,'STK-2026-0003',18,21,6,'semi_expendable',5,12,2,'Printer',25000.00,5.00,0.00,5.00,1,NULL,'2026-03-23 00:20:51',NULL),(20,'STK-2026-0004',18,22,7,'equipment',16,1,2,'Desktop Computer',55000.00,10.00,0.00,10.00,1,NULL,'2026-03-23 00:20:51',NULL);
/*!40000 ALTER TABLE `stock_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stock_item_id` bigint(20) unsigned NOT NULL,
  `movement_type` enum('receipt','issue','return','adjustment') NOT NULL,
  `movement_date` date NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_in` decimal(14,2) NOT NULL DEFAULT 0.00,
  `quantity_out` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance_after` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stock_movements_stock_item_id` (`stock_item_id`),
  KEY `idx_stock_movements_created_by` (`created_by`),
  CONSTRAINT `fk_stock_movements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_movements_stock_item_id` FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_movements`
--

LOCK TABLES `stock_movements` WRITE;
/*!40000 ALTER TABLE `stock_movements` DISABLE KEYS */;
INSERT INTO `stock_movements` VALUES (1,1,'receipt','2026-03-21','receiving',1,200.00,0.00,200.00,'Backfilled from receiving RCV-2026-0001',1,'2026-03-21 03:33:58'),(26,18,'receipt','2026-03-23','receiving',18,100.00,0.00,100.00,'Received from RCV-2026-0002',1,'2026-03-23 00:20:51'),(27,19,'receipt','2026-03-23','receiving',18,5.00,0.00,5.00,'Received from RCV-2026-0002',1,'2026-03-23 00:20:51'),(28,20,'receipt','2026-03-23','receiving',18,10.00,0.00,10.00,'Received from RCV-2026-0002',1,'2026-03-23 00:20:51');
/*!40000 ALTER TABLE `stock_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(50) NOT NULL,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `contact_no` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `tin_no` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_suppliers_supplier_code` (`supplier_code`),
  UNIQUE KEY `uk_suppliers_supplier_name` (`supplier_name`),
  KEY `idx_suppliers_created_by` (`created_by`),
  KEY `idx_suppliers_updated_by` (`updated_by`),
  CONSTRAINT `fk_suppliers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_suppliers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'SUP-2026-0001','Antique Office Depot','Sales Coordinator','09171234567','sales@antiqueofficedepot.test','Sibalom, Antique','000-111-222-000',1,NULL,NULL,'2026-03-21 03:11:53',NULL),(2,'SUP-2026-0002','Panay ICT Trading','Account Executive','09181234567','info@panayicttrading.test','San Jose, Antique','000-111-222-001',1,NULL,NULL,'2026-03-21 03:11:54',NULL),(3,'SUP-2026-0003','Visayan Medical and Laboratory Supplies','Customer Support','09191234567','support@visayanmedlab.test','Iloilo City','000-111-222-002',1,NULL,NULL,'2026-03-21 03:11:54',NULL),(4,'SUP-2026-0004','Western Printing Solutions','Marketing Officer','09201234567','orders@westernprinting.test','Kalibo, Aklan','000-111-222-003',1,NULL,NULL,'2026-03-21 03:11:54',NULL),(5,'SUP-2026-0005','Libertad General Merchandise','Owner','09211234567','sales@libertadgm.test','Libertad, Antique','000-111-222-004',1,NULL,NULL,'2026-03-21 03:11:54',NULL);
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `unit_of_measures`
--

DROP TABLE IF EXISTS `unit_of_measures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `unit_of_measures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uom_code` varchar(50) NOT NULL,
  `uom_name` varchar(100) NOT NULL,
  `abbreviation` varchar(20) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_unit_of_measures_uom_code` (`uom_code`),
  UNIQUE KEY `uk_unit_of_measures_uom_name` (`uom_name`),
  UNIQUE KEY `uk_unit_of_measures_abbreviation` (`abbreviation`),
  KEY `idx_unit_of_measures_created_by` (`created_by`),
  KEY `idx_unit_of_measures_updated_by` (`updated_by`),
  CONSTRAINT `fk_unit_of_measures_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_unit_of_measures_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `unit_of_measures`
--

LOCK TABLES `unit_of_measures` WRITE;
/*!40000 ALTER TABLE `unit_of_measures` DISABLE KEYS */;
INSERT INTO `unit_of_measures` VALUES (1,'UOM-2026-0001','Piece','pcs','Individual piece count',1,NULL,NULL,'2026-03-21 00:53:07',NULL),(2,'UOM-2026-0002','Unit','unit','Single equipment or supply unit',1,NULL,NULL,'2026-03-21 00:53:07',NULL),(3,'UOM-2026-0003','Set','set','Grouped set of components',1,NULL,NULL,'2026-03-21 00:53:07',NULL),(4,'UOM-2026-0004','Box','box','Packaged by box',1,NULL,NULL,'2026-03-21 00:53:08',NULL),(5,'UOM-2026-0005','Lot','lot','Procured as one lot',1,NULL,NULL,'2026-03-21 00:53:08',NULL),(6,'UOM-2026-0006','Ream','ream','Paper sold per ream',1,NULL,NULL,'2026-03-21 03:11:54',NULL),(7,'UOM-2026-0007','Pack','pack','Packed items',1,NULL,NULL,'2026-03-21 03:11:54',NULL),(8,'UOM-2026-0008','Bottle','btl','Liquid item container',1,NULL,NULL,'2026-03-21 03:11:54',NULL),(9,'UOM-2026-0009','Roll','roll','Items procured per roll',1,NULL,NULL,'2026-03-21 03:11:54',NULL),(10,'UOM-2026-0010','Can','can','Items procured per can',1,NULL,NULL,'2026-03-21 03:11:54',NULL);
/*!40000 ALTER TABLE `unit_of_measures` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `role_id` bigint(20) unsigned DEFAULT NULL,
  `employee_id` bigint(20) unsigned DEFAULT NULL,
  `office_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_username` (`username`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role_id` (`role_id`),
  KEY `idx_users_employee_id` (`employee_id`),
  KEY `idx_users_office_id` (`office_id`),
  CONSTRAINT `fk_users_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_users_office_id` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_users_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','admin@spams.local','$2y$10$9WlVwC5X0KV/kZgZ82WWbuwHMKN6wGSTfFjfOOHm2gzn2a6FIINFW','System Administrator',1,NULL,NULL,1,'2026-03-22 23:12:24','2026-03-20 23:50:54','2026-03-22 23:12:24'),(2,'encoder','encoder@ua.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.i8mYERZgWk4KqQe','Data Encoder',NULL,13,NULL,1,NULL,'2026-03-22 04:47:55',NULL),(3,'itadmin','it@ua.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.i8mYERZgWk4KqQe','IT Admin',NULL,14,NULL,1,NULL,'2026-03-22 04:47:55',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-23  1:05:50
