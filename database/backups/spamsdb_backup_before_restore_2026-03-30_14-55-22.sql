-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: spamsdb
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
-- Table structure for table `_migrations`
--

DROP TABLE IF EXISTS `_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `_migrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `checksum` char(64) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migrations_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `_migrations`
--

LOCK TABLES `_migrations` WRITE;
/*!40000 ALTER TABLE `_migrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `account_codes`
--

DROP TABLE IF EXISTS `account_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `account_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_code` varchar(50) NOT NULL,
  `account_name` varchar(200) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `account_group` varchar(50) NOT NULL DEFAULT 'asset',
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_code` (`account_code`)
) ENGINE=InnoDB AUTO_INCREMENT=170 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_codes`
--

LOCK TABLES `account_codes` WRITE;
/*!40000 ALTER TABLE `account_codes` DISABLE KEYS */;
INSERT INTO `account_codes` VALUES (15,'5.02.03.010.00','Office Supplies Expenses',1,'2026-03-26 06:50:38','supply','COA/UACS supplies expense code',NULL,NULL,NULL),(16,'5.02.03.020.00','Accountable Forms Expenses',1,'2026-03-26 06:50:38','supply','COA/UACS supplies expense code',NULL,NULL,NULL),(17,'5.02.03.090.00','Fuel, Oil and Lubricants Expenses',1,'2026-03-26 06:50:38','supply','COA/UACS supplies expense code',NULL,NULL,NULL),(18,'5.02.03.990.00','Other Supplies and Materials Expenses',1,'2026-03-26 06:50:38','supply','COA/UACS supplies expense code',NULL,NULL,NULL),(19,'5.02.03.210.00','Semi-Expendable Machinery and Equipment Expenses',1,'2026-03-26 06:50:38','semi_expendable','COA/UACS semi-expendable code',NULL,NULL,NULL),(20,'5.02.03.210.02','Semi-Expendable ME - Office Equipment',1,'2026-03-26 06:50:38','semi_expendable','COA/UACS semi-expendable sub-object code',NULL,NULL,NULL),(21,'5.02.03.210.03','Semi-Expendable ME - Information & Communications Technology Equipment',1,'2026-03-26 06:50:38','semi_expendable','COA/UACS semi-expendable sub-object code',NULL,NULL,NULL),(22,'5.02.03.210.07','Semi-Expendable ME - Communications Equipment',1,'2026-03-26 06:50:38','semi_expendable','COA/UACS semi-expendable sub-object code',NULL,NULL,NULL),(23,'5.02.03.210.11','Semi-Expendable ME - Printing Equipment',1,'2026-03-26 06:50:38','semi_expendable','COA/UACS semi-expendable sub-object code',NULL,NULL,NULL),(24,'5.02.03.210.99','Semi-Expendable ME - Other Machinery and Equipment',1,'2026-03-26 06:50:38','semi_expendable','COA/UACS semi-expendable sub-object code',NULL,NULL,NULL),(25,'1.06.05.010.00','Machinery',1,'2026-03-26 06:50:38','asset','COA/UACS equipment outlay code',NULL,NULL,NULL),(26,'1.06.05.020.00','Office Equipment',1,'2026-03-26 06:50:38','asset','COA/UACS equipment sub-object code',NULL,NULL,NULL),(27,'1.06.05.030.00','Information and Communication Technology Equipment',1,'2026-03-26 06:50:38','asset','COA/UACS equipment sub-object code',NULL,NULL,NULL),(28,'1.06.05.070.00','Communication Equipment',1,'2026-03-26 06:50:38','asset','COA/UACS equipment sub-object code',NULL,NULL,NULL),(29,'1.06.05.120.00','Printing Equipment',1,'2026-03-26 06:50:38','asset','COA/UACS equipment sub-object code',NULL,NULL,NULL),(30,'1.06.05.990.00','Other Machinery and Equipment',1,'2026-03-26 06:50:38','asset','COA/UACS equipment sub-object code',NULL,NULL,NULL),(31,'1.06.07.010.00','Furniture and Fixtures',1,'2026-03-26 06:50:38','asset','COA/UACS furniture outlay code',NULL,NULL,NULL),(32,'1.06.07.020.00','Books',1,'2026-03-26 06:50:38','asset','COA/UACS furniture, fixtures and books outlay code',NULL,NULL,NULL),(33,'1.06.99.990.00','Other Property, Plant and Equipment',1,'2026-03-26 06:50:38','asset','COA/UACS PPE outlay code',NULL,NULL,NULL),(34,'1.06.01.010.00','Land',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(35,'1.06.02.010.00','Land Improvements, Aquaculture Structures',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(36,'1.06.02.020.00','Land Improvements, Reforestation Projects',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(37,'1.06.02.990.00','Other Land Improvements',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(38,'1.06.03.010.00','Road Networks',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(39,'1.06.03.020.00','Flood Control Systems',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(40,'1.06.03.030.00','Sewer Systems',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(41,'1.06.03.040.00','Water Supply Systems',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(42,'1.06.03.050.00','Power Supply Systems',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(43,'1.06.03.060.00','Communication Networks',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(44,'1.06.03.070.00','Seaport Systems',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(45,'1.06.03.090.00','Parks, Plazas and Monuments',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(46,'1.06.03.100.00','Other Infrastructure Assets',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(47,'1.06.04.010.00','Buildings',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(48,'1.06.04.020.00','School Buildings',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(49,'1.06.04.030.00','Hospitals and Health Centers',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(50,'1.06.04.040.00','Markets',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(51,'1.06.04.050.00','Slaughterhouses',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(52,'1.06.04.060.00','Hostels and Dormitories',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(53,'1.06.04.990.00','Other Structures',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(54,'1.06.05.040.00','Agricultural and Forestry Equipment',1,'2026-03-26 07:03:11','asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(55,'1.06.05.050.00','Marine and Fishery Equipment',1,'2026-03-26 07:03:11','asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(56,'1.06.05.060.00','Airport Equipment',1,'2026-03-26 07:03:11','asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(57,'1.06.05.080.00','Construction and Heavy Equipment',1,'2026-03-26 07:03:11','asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(58,'1.06.05.090.00','Disaster Response and Rescue Equipment',1,'2026-03-26 07:03:11','asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(59,'1.06.05.100.00','Military, Police and Security Equipment',1,'2026-03-26 07:03:11','asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(60,'1.06.05.110.00','Medical Equipment',1,'2026-03-26 07:03:11','asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(61,'1.06.05.130.00','Sports Equipment',1,'2026-03-26 07:03:11','asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(62,'1.06.05.140.00','Technical and Scientific Equipment',1,'2026-03-26 07:03:11','asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(63,'1.06.06.010.00','Motor Vehicles',1,'2026-03-26 07:03:11','asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(64,'1.06.06.990.00','Other Transportation Equipment',1,'2026-03-26 07:03:11','asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(65,'1.06.10.010.00','Construction in Progress - Land Improvements',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(66,'1.06.10.020.00','Construction in Progress - Infrastructure Assets',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(67,'1.06.10.030.00','Construction in Progress - Buildings and Other Structures',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(68,'1.06.11.010.00','Historical Buildings',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(69,'1.06.11.020.00','Works of Arts and Archeological Specimens',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(70,'1.06.11.990.00','Other Heritage Assets',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(71,'1.08.01.010.00','Patents/Copyrights',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(72,'1.08.01.020.00','Computer Software',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(73,'1.08.01.030.00','Other Intangible Assets',1,'2026-03-26 07:03:11','fixed_asset','COA/UACS seed from provided chart',NULL,NULL,NULL),(74,'5.02.01.010.00','Travelling Expenses - Local',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(75,'5.02.01.020.00','Travelling Expenses - Foreign',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(76,'5.02.02.010.00','Training Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(77,'5.02.02.020.00','Scholarship/Grants Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(78,'5.02.03.030.00','Non-Accountable Forms Expenses',1,'2026-03-26 07:03:11','supply','COA/UACS seed from provided chart',NULL,NULL,NULL),(79,'5.02.03.040.00','Animal/Zoological Supplies Expenses',1,'2026-03-26 07:03:11','supply','COA/UACS seed from provided chart',NULL,NULL,NULL),(80,'5.02.03.050.00','Food Supplies Expenses',1,'2026-03-26 07:03:11','supply','COA/UACS seed from provided chart',NULL,NULL,NULL),(81,'5.02.03.060.00','Welfare Goods Expenses',1,'2026-03-26 07:03:11','supply','COA/UACS seed from provided chart',NULL,NULL,NULL),(82,'5.02.03.070.00','Drugs and Medicines Expenses',1,'2026-03-26 07:03:11','supply','COA/UACS seed from provided chart',NULL,NULL,NULL),(83,'5.02.03.080.00','Medical, Dental and Laboratory Supplies Expenses',1,'2026-03-26 07:03:11','supply','COA/UACS seed from provided chart',NULL,NULL,NULL),(84,'5.02.03.100.00','Agricultural and Marine Supplies Expenses',1,'2026-03-26 07:03:11','supply','COA/UACS seed from provided chart',NULL,NULL,NULL),(85,'5.02.03.110.01','Textbooks and Instructional Materials Expenses',1,'2026-03-26 07:03:11','supply','COA/UACS seed from provided chart',NULL,NULL,NULL),(86,'5.02.03.120.00','Military, Police and Traffic Supplies Expenses',1,'2026-03-26 07:03:11','supply','COA/UACS seed from provided chart',NULL,NULL,NULL),(87,'5.02.03.130.00','Chemical and Filtering Supplies Expenses',1,'2026-03-26 07:03:11','supply','COA/UACS seed from provided chart',NULL,NULL,NULL),(88,'5.02.03.210.01','Semi-Expendable ME - Machinery',1,'2026-03-26 07:03:11','semi_expendable','COA/UACS seed from provided chart',NULL,NULL,NULL),(89,'5.02.03.210.04','Semi-Expendable ME - Agricultural and Forestry Equipment',1,'2026-03-26 07:03:11','semi_expendable','COA/UACS seed from provided chart',NULL,NULL,NULL),(90,'5.02.03.210.05','Semi-Expendable ME - Marine and Fishery Equipment',1,'2026-03-26 07:03:11','semi_expendable','COA/UACS seed from provided chart',NULL,NULL,NULL),(91,'5.02.03.210.08','Semi-Expendable ME - Disaster Response and Rescue Equipment',1,'2026-03-26 07:03:11','semi_expendable','COA/UACS seed from provided chart',NULL,NULL,NULL),(92,'5.02.03.210.10','Semi-Expendable ME - Medical Equipment',1,'2026-03-26 07:03:11','semi_expendable','COA/UACS seed from provided chart',NULL,NULL,NULL),(93,'5.02.03.210.12','Semi-Expendable ME - Sports Equipment',1,'2026-03-26 07:03:11','semi_expendable','COA/UACS seed from provided chart',NULL,NULL,NULL),(94,'5.02.03.210.13','Semi-Expendable ME - Technical and Scientific Equipment',1,'2026-03-26 07:03:11','semi_expendable','COA/UACS seed from provided chart',NULL,NULL,NULL),(95,'5.02.04.010.00','Water Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(96,'5.02.04.020.00','Electricity Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(97,'5.02.05.010.00','Postage and Courier Services',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(98,'5.02.05.020.01','Telephone Expenses - Mobile',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(99,'5.02.05.020.02','Telephone Expenses - Landline',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(100,'5.02.05.030.00','Internet Subscription Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(101,'5.02.05.040.00','Cable, Satellite, Telegraph and Radio Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(102,'5.02.06.010.01','Awards/Rewards Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(103,'5.02.06.010.02','Rewards and Incentives',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(104,'5.02.06.020.00','Prizes',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(105,'5.02.07.010.00','Survey Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(106,'5.02.07.020.00','Research, Exploration and Development Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(107,'5.02.08.010.00','Demolition and Relocation Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(108,'5.02.08.020.00','Desilting and Dredging Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(109,'5.02.09.010.00','Generation, Transmission and Distribution Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(110,'5.02.10.010.00','Confidential Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(111,'5.02.10.020.00','Intelligence Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(112,'5.02.10.030.00','Extraordinary and Miscellaneous Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(113,'5.02.11.010.00','Legal Services',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(114,'5.02.11.020.00','Auditing Services',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(115,'5.02.11.030.00','Consultancy Services',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(116,'5.02.11.990.01','Other Professional Services - Part-time',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(117,'5.02.11.990.02','Other Professional Services - Others',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(118,'5.02.11.990.03','Other Professional Services - Summer',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(119,'5.02.12.010.00','Environment/Sanitary Services',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(120,'5.02.12.020.00','Janitorial Services',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(121,'5.02.12.030.00','Security Services',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(122,'5.02.12.990.00','Other General Services',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(123,'5.02.13.010.00','Repairs and Maintenance - Investment Property',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(124,'5.02.13.020.01','Repairs and Maintenance - Land Improvement - Aquaculture',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(125,'5.02.13.020.02','Repairs and Maintenance - Land Improvement - Reforestation',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(126,'5.02.13.020.99','Repairs and Maintenance - Land Improvement - Other Land Improvements',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(127,'5.02.13.030.00','Repairs and Maintenance - Infrastructure Assets',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(128,'5.02.13.040.01','Repairs and Maintenance - Buildings',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(129,'5.02.13.040.02','Repairs and Maintenance - School Buildings',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(130,'5.02.13.040.06','Repairs and Maintenance - Hostels and Dormitories',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(131,'5.02.13.040.99','Repairs and Maintenance - Other Structures',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(132,'5.02.13.050.01','Repairs and Maintenance - Machinery',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(133,'5.02.13.050.02','Repairs and Maintenance - Office Equipment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(134,'5.02.13.050.03','Repairs and Maintenance - ICT Equipment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(135,'5.02.13.050.04','Repairs and Maintenance - Agricultural and Forestry Equipment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(136,'5.02.13.050.05','Repairs and Maintenance - Marine and Fishery Equipment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(137,'5.02.13.050.07','Repairs and Maintenance - Communication Equipment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(138,'5.02.13.050.10','Repairs and Maintenance - Military, Police and Security Equipment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(139,'5.02.13.050.11','Repairs and Maintenance - Medical Equipment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(140,'5.02.13.050.13','Repairs and Maintenance - Sports Equipment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(141,'5.02.13.050.14','Repairs and Maintenance - Technical and Scientific Equipment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(142,'5.02.13.050.99','Repairs and Maintenance - Other Machinery and Equipment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(143,'5.02.13.060.01','Repairs and Maintenance - Motor Vehicle',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(144,'5.02.13.070.00','Repairs and Maintenance - Furniture and Fixtures',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(145,'5.02.13.080.00','Repairs and Maintenance - Leased Assets',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(146,'5.02.13.090.00','Repairs and Maintenance - Leased Assets Improvement',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(147,'5.02.13.100.00','Repairs and Maintenance - Heritage Assets',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(148,'5.02.13.990.99','Repairs and Maintenance - Other Property, Plant and Equipment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(149,'5.02.14.010.00','Subsidy to NGA\'s',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(150,'5.02.14.020.00','Financial Assistance to NGA\'s',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(151,'5.02.14.030.00','Financial Assistance to LGU\'s',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(152,'5.02.14.040.00','Budgetary Support to GOCC\'s',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(153,'5.02.14.050.00','Financial Assistance to NGO\'s/PO\'s',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(154,'5.02.14.060.00','Internal Revenue Allotment',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(155,'5.02.14.990.00','Subsidies - Others',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(156,'5.02.15.010.00','Taxes, Duties and Licenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(157,'5.02.15.020.00','Fidelity Bond Premiums',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(158,'5.02.15.030.00','Insurance Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(159,'5.02.16.010.00','Labor and Wages',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(160,'5.02.99.010.00','Advertising Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(161,'5.02.99.020.00','Printing and Publication Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(162,'5.02.99.030.00','Representation Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(163,'5.02.99.040.00','Transportation Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(164,'5.02.99.050.00','Rent/Lease Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(165,'5.02.99.060.00','Membership Dues and Contributions to Organizations',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(166,'5.02.99.070.00','Subscription Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(167,'5.02.99.080.00','Donations',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(168,'5.02.99.090.00','Litigation/Acquired Assets Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL),(169,'5.02.99.990.99','Other Maintenance and Operating Expenses',1,'2026-03-26 07:03:11','expense','COA/UACS seed from provided chart',NULL,NULL,NULL);
/*!40000 ALTER TABLE `account_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_photos`
--

DROP TABLE IF EXISTS `asset_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `asset_source` enum('system','legacy') NOT NULL,
  `asset_id` int(10) unsigned NOT NULL,
  `photo_path` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_asset_photos_asset` (`asset_source`,`asset_id`),
  KEY `idx_asset_photos_primary` (`asset_source`,`asset_id`,`is_primary`),
  KEY `idx_asset_photos_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_photos`
--

LOCK TABLES `asset_photos` WRITE;
/*!40000 ALTER TABLE `asset_photos` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_photos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_transfers`
--

DROP TABLE IF EXISTS `asset_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asset_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `transfer_date` date NOT NULL,
  `source_type` enum('system','legacy') NOT NULL,
  `distribution_item_detail_id` bigint(20) unsigned DEFAULT NULL,
  `legacy_asset_id` bigint(20) unsigned DEFAULT NULL,
  `property_number` varchar(100) DEFAULT NULL,
  `from_office_id` int(10) unsigned DEFAULT NULL,
  `from_employee_id` int(10) unsigned DEFAULT NULL,
  `from_responsibility_code_id` int(10) unsigned DEFAULT NULL,
  `to_office_id` int(10) unsigned DEFAULT NULL,
  `to_employee_id` int(10) unsigned DEFAULT NULL,
  `to_responsibility_code_id` int(10) unsigned DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('posted','cancelled') NOT NULL DEFAULT 'posted',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_transfers`
--

LOCK TABLES `asset_transfers` WRITE;
/*!40000 ALTER TABLE `asset_transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(100) DEFAULT NULL,
  `reference_id` int(10) unsigned DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` varchar(100) DEFAULT NULL,
  `old_values` longtext DEFAULT NULL,
  `new_values` longtext DEFAULT NULL,
  `module_name` varchar(100) DEFAULT NULL,
  `record_type` varchar(100) DEFAULT NULL,
  `action_name` varchar(100) DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,1,'insert','offices','1',NULL,'{\"office_code\":\"OFF-2026-0001\",\"office_name\":\"College of Industrial Technology\",\"office_head_employee_id\":null,\"description\":\"\",\"is_active\":1}','offices','office','create_office','Created office record.','::1','2026-03-30 06:36:48');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `brands`
--

DROP TABLE IF EXISTS `brands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `brands` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `brand_name` varchar(200) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `brand_code` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `brands`
--

LOCK TABLES `brands` WRITE;
/*!40000 ALTER TABLE `brands` DISABLE KEYS */;
/*!40000 ALTER TABLE `brands` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `channel_messages`
--

DROP TABLE IF EXISTS `channel_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `channel_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `channel_key` varchar(50) NOT NULL,
  `sender_user_id` int(10) unsigned NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `message_body` text NOT NULL,
  `related_table` varchar(50) DEFAULT NULL,
  `related_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_channel_created` (`channel_key`,`created_at`),
  KEY `idx_sender` (`sender_user_id`),
  KEY `idx_related` (`related_table`,`related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `channel_messages`
--

LOCK TABLES `channel_messages` WRITE;
/*!40000 ALTER TABLE `channel_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `channel_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classifications`
--

DROP TABLE IF EXISTS `classifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `classifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `classification_code` varchar(50) DEFAULT NULL,
  `system_reference` varchar(50) DEFAULT NULL,
  `classification_name` varchar(200) NOT NULL,
  `classification_family` varchar(150) DEFAULT NULL,
  `classification_group` varchar(100) DEFAULT NULL,
  `abbreviation` varchar(10) DEFAULT NULL,
  `requires_serial` tinyint(1) NOT NULL DEFAULT 0,
  `useful_life_years` tinyint(3) unsigned DEFAULT NULL,
  `account_code_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_classifications_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classifications`
--

LOCK TABLES `classifications` WRITE;
/*!40000 ALTER TABLE `classifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `classifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `disposals`
--

DROP TABLE IF EXISTS `disposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `disposals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `disposal_date` date NOT NULL,
  `distribution_item_detail_id` bigint(20) unsigned DEFAULT NULL,
  `disposal_type` varchar(100) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'posted',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_reference` (`system_reference`),
  KEY `idx_disposals_distribution_item_detail_id` (`distribution_item_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `disposals`
--

LOCK TABLES `disposals` WRITE;
/*!40000 ALTER TABLE `disposals` DISABLE KEYS */;
/*!40000 ALTER TABLE `disposals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `distribution_item_details`
--

DROP TABLE IF EXISTS `distribution_item_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `distribution_item_details` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `distribution_item_id` int(10) unsigned NOT NULL,
  `receiving_item_detail_id` int(10) unsigned DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `serial_no` varchar(100) DEFAULT NULL,
  `property_number` varchar(100) DEFAULT NULL,
  `current_office_id` int(10) unsigned DEFAULT NULL,
  `current_employee_id` int(10) unsigned DEFAULT NULL,
  `current_responsibility_code_id` int(10) unsigned DEFAULT NULL,
  `is_distributed` tinyint(1) NOT NULL DEFAULT 1,
  `is_disposed` tinyint(1) NOT NULL DEFAULT 0,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_distribution_item` (`distribution_item_id`),
  KEY `idx_distribution_item_details_is_disposed` (`is_disposed`),
  KEY `idx_distribution_item_details_is_distributed` (`is_distributed`),
  KEY `idx_distribution_item_details_current_office_id` (`current_office_id`),
  KEY `idx_distribution_item_details_current_employee_id` (`current_employee_id`),
  CONSTRAINT `fk_did_distribution_item` FOREIGN KEY (`distribution_item_id`) REFERENCES `distribution_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `distribution_id` int(10) unsigned NOT NULL,
  `issuance_item_id` int(10) unsigned DEFAULT NULL,
  `receiving_item_id` int(10) unsigned DEFAULT NULL,
  `quantity_distributed` decimal(15,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `property_number` varchar(100) DEFAULT NULL,
  `is_disposed` tinyint(1) NOT NULL DEFAULT 0,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_distribution` (`distribution_id`),
  KEY `fk_di_receiving_item` (`receiving_item_id`),
  CONSTRAINT `fk_di_distribution` FOREIGN KEY (`distribution_id`) REFERENCES `distributions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_di_receiving_item` FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `document_type` enum('ics','par') NOT NULL DEFAULT 'ics',
  `semi_expendable_type` enum('high_value','low_value') DEFAULT NULL,
  `document_no` varchar(50) DEFAULT NULL,
  `distribution_date` date NOT NULL,
  `office_id` int(10) unsigned NOT NULL,
  `employee_id` int(10) unsigned DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'posted',
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_reference` (`system_reference`),
  KEY `idx_office` (`office_id`),
  KEY `fk_dist_employee` (`employee_id`),
  KEY `idx_distributions_status` (`status`),
  CONSTRAINT `fk_dist_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dist_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `employee_no` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix_name` varchar(20) DEFAULT NULL,
  `position_title` varchar(200) DEFAULT NULL,
  `office_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_unit_head` tinyint(1) NOT NULL DEFAULT 0,
  `email` varchar(150) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `responsibility_code_id` int(10) unsigned DEFAULT NULL,
  `employment_status` varchar(50) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_employee_no` (`employee_no`),
  KEY `idx_office` (`office_id`),
  KEY `idx_employees_is_active` (`is_active`),
  CONSTRAINT `fk_employees_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `funds`
--

DROP TABLE IF EXISTS `funds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `funds` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fund_code` varchar(50) NOT NULL,
  `fund_name` varchar(200) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `fund_source` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fund_code` (`fund_code`),
  KEY `idx_funds_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=117 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `funds`
--

LOCK TABLES `funds` WRITE;
/*!40000 ALTER TABLE `funds` DISABLE KEYS */;
INSERT INTO `funds` VALUES (3,'GAA-GAS','General Fund - General Administration and Support',1,'2026-03-27 07:08:16','01','UA fund seed',NULL,NULL,NULL),(4,'TFCF','Tuition Fees - Common Fund',1,'2026-03-27 07:08:16','05','UA fund seed',NULL,NULL,NULL),(5,'TICT','Tuition Fees - Common Fund',1,'2026-03-27 07:08:16','05','UA fund seed (legacy code)',NULL,NULL,NULL),(6,'IGP','Income Generating Projects',1,'2026-03-27 07:08:16','06','UA fund seed',NULL,NULL,NULL),(7,'GAA-STO','General Fund - Support to Operations',1,'2026-03-27 07:08:16','01','UA fund seed',NULL,NULL,NULL),(8,'GAA-HEP','General Fund - Higher Education Program',1,'2026-03-27 07:08:16','01','UA fund seed',NULL,NULL,NULL),(9,'GAA-RP','General Fund - Research Program',1,'2026-03-27 07:08:16','01','UA fund seed',NULL,NULL,NULL),(10,'GAA-TAEP','General Fund - Technical Advisory Extension Program',1,'2026-03-27 07:08:16','01','UA fund seed',NULL,NULL,NULL),(11,'GAA-AEP','General Fund - Advanced Education Program',1,'2026-03-27 07:08:16','01','UA fund seed',NULL,NULL,NULL),(12,'TFHEP','Tuition Fees - Higher Education Program',1,'2026-03-27 07:08:16','05','UA fund seed',NULL,NULL,NULL),(13,'THETP','Tuition Fees - Higher Education Program',1,'2026-03-27 07:08:16','05','UA fund seed (legacy code)',NULL,NULL,NULL),(14,'TFNSTP','Tuition Fees - National Service Training Program',1,'2026-03-27 07:08:16','05','UA fund seed',NULL,NULL,NULL),(15,'TNSTP','Tuition Fees - National Service Training Program',1,'2026-03-27 07:08:16','05','UA fund seed (legacy code)',NULL,NULL,NULL),(16,'TFOL','Tuition Fees - Open Learning Center',1,'2026-03-27 07:08:16','05','UA fund seed',NULL,NULL,NULL),(17,'TOL','Tuition Fees - Open Learning Center',1,'2026-03-27 07:08:16','05','UA fund seed (legacy code)',NULL,NULL,NULL),(18,'TFPR','Tuition Fees - Production',1,'2026-03-27 07:08:16','05','UA fund seed',NULL,NULL,NULL),(19,'TPR','Tuition Fees - Production',1,'2026-03-27 07:08:16','05','UA fund seed (legacy code)',NULL,NULL,NULL),(20,'TFGS','Tuition Fees - Graduate School',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(21,'TGS','Tuition Fees - Graduate School',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(22,'TFRP','Tuition Fees - Research Program',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(23,'TRP','Tuition Fees - Research Program',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(24,'TFTAEP','Tuition Fees - Technical Advisory Extension Program',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(25,'TTAEP','Tuition Fees - Technical Advisory Extension Program',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(26,'OI','Other Income',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(27,'AADM','Admission Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(28,'ACEF','Certification Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(29,'ADIP','Diploma',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(30,'ADEP','Diploma',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(31,'AENT','Entrance Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(32,'AFAP','Fines and Penalties',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(33,'AREG','Registration Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(34,'AIREG','Registration Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(35,'ASID','Student ID',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(36,'ATOR','Transcript of Records',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(37,'BOND','Performance/Security Bond',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(38,'CIT','Tuition Fees - Certificate in Teaching',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(39,'COCN','Cocoon - Main Campus Yearbook',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(40,'ECED','Tuition Fees - Early Childhood Education',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(41,'FCALF','Laboratory Fees - Caluya Extension Campus',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(42,'LCAL','Laboratory Fees - Caluya Extension Campus',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(43,'FCLF','Crime Lab Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(44,'LCL','Crime Lab Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(45,'FCUF','Recreational, Social and Cultural Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(46,'LCUR','Recreational, Social and Cultural Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(47,'FDEPF','Department Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(48,'LDEP','Department Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(49,'FELF','Engineering Lab Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(50,'LEL','Engineering Lab Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(51,'FFLP','FLP Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(52,'LFLP','FLP Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(53,'FGSJ','Graduate School Journal',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(54,'LGS','Graduate School Journal',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(55,'FGUF','Guidance Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(56,'LGUI','Guidance Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(57,'AHB','Handbook Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(58,'LHB','Handbook Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(59,'FITF','Computer Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(60,'LIT','Computer Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(61,'FLHS','Student Development Fee - Lab High School',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(62,'LHS','Student Development Fee - Lab High School',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(63,'FLIF','Library Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(64,'LLF','Library Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(65,'FLLF','Laboratory Fees - Libertad Extension Campus',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(66,'LLL','Laboratory Fees - Libertad Extension Campus',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(67,'FMAINT','Maintenance and Development Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(68,'LMAINT','Maintenance and Development Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(69,'FMDF','Medical/Dental Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(70,'LMDI','Medical/Dental Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(71,'FMLF','Maritime Lab Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(72,'LML','Maritime Lab Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(73,'FOJT','Practicum Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(74,'LOT','Practicum Fee',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(75,'FOLMF','Module Fee - Open Learning',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(76,'TOLM','Module Fee - Open Learning',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(77,'FPRSM','The Prism',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(78,'TPRISM','The Prism',1,'2026-03-27 07:08:17','05','UA fund seed (legacy code)',NULL,NULL,NULL),(79,'FPUB','College Publication',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(80,'FSDF','Sports Development Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(81,'FSG','Student Government Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(82,'FSLF','Science Lab Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(83,'FTLF','Techno Lab Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(84,'SPECK','Tuition Fees - SPECK',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(85,'TFADM','Tuition Fees - Admin Services',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(86,'TFMR','Tuition Fees - Mandatory Reserve',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(87,'TFLHS','Tuition Fees - Senior High School',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(88,'FWHL','The Wheel',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(89,'AAUT','Authentication Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(90,'FIDADM','Fiduciary - Admin Services',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(91,'FIDMR','Fiduciary - Mandatory Reserve',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(92,'FSTLF','Steno Lab Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(93,'BID','Bidding Fees',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(94,'FGRAD','Graduation Fee and Yearbook',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(95,'FPLF','PE Lab Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(96,'TFHC','Tuition Fees - Hamtic Campus',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(97,'TFTLM','Tuition Fees - Tario Lim Memorial Campus',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(98,'FIDCF','Fiduciary - Common Fund',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(99,'SOFAD','Service and Other Fees - Administrative',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(100,'FCOM','Communication Lab Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(101,'FPSY','Psychology Laboratory Fee',1,'2026-03-27 07:08:17','05','UA fund seed',NULL,NULL,NULL),(102,'DOST1','CEST-Pottery',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(103,'DOST2','PCIEERD-Watermelon',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(104,'DOST3','PCIEERD-IRDL',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(105,'DOST4','PCAARRD-RAISE',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(106,'DOST5','GIA-Nutribun',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(107,'DOST6','CEST-CLIBA',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(108,'DOST7','ATHEINA',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(109,'CHED1','IAS-TES',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(110,'CHED2','IAS-TD',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(111,'CHED3','STUFAP',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(112,'CHED4','IAS-Juan-A',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(113,'CHED5','IAS-Sipal',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(114,'CHED6','SMART Campus',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(115,'CHED7','IAS-iLab',1,'2026-03-27 07:08:17','07','UA fund seed',NULL,NULL,NULL),(116,'DA1','PAFES',1,'2026-03-27 07:08:18','07','UA fund seed',NULL,NULL,NULL);
/*!40000 ALTER TABLE `funds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_count_items`
--

DROP TABLE IF EXISTS `inventory_count_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_count_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int(10) unsigned NOT NULL,
  `source_type` enum('system','legacy') NOT NULL,
  `distribution_item_detail_id` int(10) unsigned DEFAULT NULL,
  `legacy_asset_id` bigint(20) unsigned DEFAULT NULL,
  `property_number` varchar(120) NOT NULL,
  `item_type` enum('equipment','semi_expendable') NOT NULL,
  `office_id` int(10) unsigned DEFAULT NULL,
  `employee_id` int(10) unsigned DEFAULT NULL,
  `classification_name` varchar(255) DEFAULT NULL,
  `item_description` varchar(255) NOT NULL,
  `brand` varchar(120) DEFAULT NULL,
  `model` varchar(120) DEFAULT NULL,
  `serial_no` varchar(120) DEFAULT NULL,
  `accountable_name` varchar(255) DEFAULT NULL,
  `status` enum('pending','found','missing','for_repair','for_disposal','wrong_office','wrong_accountable') NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `resolution_status` enum('unresolved','resolved') NOT NULL DEFAULT 'unresolved',
  `resolution_action` varchar(50) DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `checked_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_count_system_item` (`session_id`,`distribution_item_detail_id`),
  UNIQUE KEY `uq_inventory_count_legacy_item` (`session_id`,`legacy_asset_id`),
  KEY `idx_inventory_count_items_session_status` (`session_id`,`status`),
  KEY `idx_inventory_count_items_property` (`property_number`),
  KEY `idx_inventory_count_items_system_asset` (`distribution_item_detail_id`),
  KEY `idx_inventory_count_items_legacy_asset` (`legacy_asset_id`),
  KEY `fk_inventory_count_items_office` (`office_id`),
  KEY `fk_inventory_count_items_employee` (`employee_id`),
  KEY `fk_inventory_count_items_checked_by` (`checked_by`),
  KEY `idx_inventory_count_items_resolution` (`session_id`,`resolution_status`),
  KEY `fk_inventory_count_items_resolved_by` (`resolved_by`),
  CONSTRAINT `fk_inventory_count_items_checked_by` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_count_items_distribution_detail` FOREIGN KEY (`distribution_item_detail_id`) REFERENCES `distribution_item_details` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_count_items_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_count_items_legacy_asset` FOREIGN KEY (`legacy_asset_id`) REFERENCES `legacy_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_count_items_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_count_items_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_count_items_session` FOREIGN KEY (`session_id`) REFERENCES `inventory_count_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_count_items`
--

LOCK TABLES `inventory_count_items` WRITE;
/*!40000 ALTER TABLE `inventory_count_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_count_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_count_sessions`
--

DROP TABLE IF EXISTS `inventory_count_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_count_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `count_type` enum('annual','surprise') NOT NULL DEFAULT 'annual',
  `office_id` int(10) unsigned NOT NULL,
  `count_date` date NOT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_reference` (`system_reference`),
  KEY `idx_inventory_count_sessions_office_status` (`office_id`,`status`),
  KEY `idx_inventory_count_sessions_count_date` (`count_date`),
  KEY `fk_inventory_count_sessions_created_by` (`created_by`),
  KEY `fk_inventory_count_sessions_closed_by` (`closed_by`),
  CONSTRAINT `fk_inventory_count_sessions_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_count_sessions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_count_sessions_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_count_sessions`
--

LOCK TABLES `inventory_count_sessions` WRITE;
/*!40000 ALTER TABLE `inventory_count_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_count_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `issuance_items`
--

DROP TABLE IF EXISTS `issuance_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `issuance_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `issuance_id` int(10) unsigned NOT NULL,
  `receiving_item_id` int(10) unsigned DEFAULT NULL,
  `stock_item_id` int(10) unsigned DEFAULT NULL,
  `quantity_issued` decimal(15,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_issuance` (`issuance_id`),
  KEY `idx_receiving_item` (`receiving_item_id`),
  CONSTRAINT `fk_ii_issuance` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ii_receiving_item` FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `document_no` varchar(50) DEFAULT NULL,
  `issuance_date` date NOT NULL,
  `office_id` int(10) unsigned NOT NULL,
  `employee_id` int(10) unsigned DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'posted',
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_reference` (`system_reference`),
  KEY `idx_office` (`office_id`),
  KEY `fk_iss_employee` (`employee_id`),
  CONSTRAINT `fk_iss_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_iss_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `issuances`
--

LOCK TABLES `issuances` WRITE;
/*!40000 ALTER TABLE `issuances` DISABLE KEYS */;
/*!40000 ALTER TABLE `issuances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `legacy_assets`
--

DROP TABLE IF EXISTS `legacy_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `legacy_assets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) DEFAULT NULL,
  `property_number` varchar(100) NOT NULL,
  `item_type` varchar(30) NOT NULL DEFAULT 'equipment',
  `item_description` text NOT NULL,
  `classification_id` int(10) unsigned DEFAULT NULL,
  `account_code_id` int(10) unsigned DEFAULT NULL,
  `fund_id` int(11) DEFAULT NULL,
  `supplier_id` int(10) unsigned DEFAULT NULL,
  `brand_id` int(10) unsigned DEFAULT NULL,
  `model_id` int(10) unsigned DEFAULT NULL,
  `brand` varchar(200) DEFAULT NULL,
  `model` varchar(200) DEFAULT NULL,
  `serial_no` varchar(200) DEFAULT NULL,
  `acquisition_date` date DEFAULT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `acquisition_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `office_id` int(10) unsigned DEFAULT NULL,
  `employee_id` int(10) unsigned DEFAULT NULL,
  `responsibility_code_id` int(10) unsigned DEFAULT NULL,
  `condition_status` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_property_number` (`property_number`),
  KEY `idx_office` (`office_id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_legacy_assets_classification_id` (`classification_id`),
  KEY `idx_legacy_assets_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `legacy_assets`
--

LOCK TABLES `legacy_assets` WRITE;
/*!40000 ALTER TABLE `legacy_assets` DISABLE KEYS */;
/*!40000 ALTER TABLE `legacy_assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `maintenance_logs`
--

DROP TABLE IF EXISTS `maintenance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maintenance_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `maintenance_date` date NOT NULL,
  `distribution_item_detail_id` bigint(20) unsigned DEFAULT NULL,
  `work_description` text NOT NULL,
  `performed_by` varchar(200) DEFAULT NULL,
  `cost` decimal(12,2) DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `status` enum('posted','cancelled') NOT NULL DEFAULT 'posted',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance_logs`
--

LOCK TABLES `maintenance_logs` WRITE;
/*!40000 ALTER TABLE `maintenance_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `maintenance_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `message_channel_hidden`
--

DROP TABLE IF EXISTS `message_channel_hidden`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_channel_hidden` (
  `channel_message_id` bigint(20) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `hidden_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`channel_message_id`,`user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `message_channel_hidden`
--

LOCK TABLES `message_channel_hidden` WRITE;
/*!40000 ALTER TABLE `message_channel_hidden` DISABLE KEYS */;
/*!40000 ALTER TABLE `message_channel_hidden` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `message_channel_reads`
--

DROP TABLE IF EXISTS `message_channel_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_channel_reads` (
  `channel_key` varchar(50) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `last_read_message_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`channel_key`,`user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `message_channel_reads`
--

LOCK TABLES `message_channel_reads` WRITE;
/*!40000 ALTER TABLE `message_channel_reads` DISABLE KEYS */;
/*!40000 ALTER TABLE `message_channel_reads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `message_channels`
--

DROP TABLE IF EXISTS `message_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_channels` (
  `channel_key` varchar(50) NOT NULL,
  `channel_name` varchar(100) NOT NULL,
  `channel_description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`channel_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `message_channels`
--

LOCK TABLES `message_channels` WRITE;
/*!40000 ALTER TABLE `message_channels` DISABLE KEYS */;
/*!40000 ALTER TABLE `message_channels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mode_of_procurements`
--

DROP TABLE IF EXISTS `mode_of_procurements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mode_of_procurements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mode_code` varchar(50) DEFAULT NULL,
  `mode_name` varchar(200) NOT NULL,
  `abbreviation` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mode_of_procurements`
--

LOCK TABLES `mode_of_procurements` WRITE;
/*!40000 ALTER TABLE `mode_of_procurements` DISABLE KEYS */;
/*!40000 ALTER TABLE `mode_of_procurements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `models`
--

DROP TABLE IF EXISTS `models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `models` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `model_name` varchar(200) NOT NULL,
  `brand_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `model_code` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `models`
--

LOCK TABLES `models` WRITE;
/*!40000 ALTER TABLE `models` DISABLE KEYS */;
/*!40000 ALTER TABLE `models` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `offices`
--

DROP TABLE IF EXISTS `offices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `offices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `office_name` varchar(200) NOT NULL,
  `head_employee_id` int(10) unsigned DEFAULT NULL,
  `office_code` varchar(50) DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `office_head_employee_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_department` (`department_id`),
  KEY `fk_offices_head_employee` (`head_employee_id`),
  KEY `idx_offices_is_active` (`is_active`),
  CONSTRAINT `fk_offices_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_offices_head_employee` FOREIGN KEY (`head_employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `offices`
--

LOCK TABLES `offices` WRITE;
/*!40000 ALTER TABLE `offices` DISABLE KEYS */;
INSERT INTO `offices` VALUES (1,'College of Industrial Technology',NULL,'OFF-2026-0001',NULL,1,'2026-03-30 06:36:48',NULL,NULL,'',1,NULL);
/*!40000 ALTER TABLE `offices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `email` varchar(150) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_password_resets_token_hash` (`token_hash`),
  KEY `idx_password_resets_user_id` (`user_id`),
  KEY `idx_password_resets_expires_at` (`expires_at`),
  KEY `idx_password_resets_used_at` (`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `property_thresholds`
--

DROP TABLE IF EXISTS `property_thresholds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_thresholds` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `equipment_min` decimal(15,2) NOT NULL DEFAULT 50000.00,
  `semi_hv_min` decimal(15,2) NOT NULL DEFAULT 5000.01,
  `effective_date` date NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `basis` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `property_thresholds`
--

LOCK TABLES `property_thresholds` WRITE;
/*!40000 ALTER TABLE `property_thresholds` DISABLE KEYS */;
/*!40000 ALTER TABLE `property_thresholds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_delivery_extensions`
--

DROP TABLE IF EXISTS `purchase_order_delivery_extensions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_delivery_extensions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `old_expected_delivery_date` date NOT NULL,
  `new_expected_delivery_date` date NOT NULL,
  `requested_extension_days` int(10) unsigned DEFAULT NULL,
  `reason` text NOT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('posted','cancelled') NOT NULL DEFAULT 'posted',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_delivery_extensions`
--

LOCK TABLES `purchase_order_delivery_extensions` WRITE;
/*!40000 ALTER TABLE `purchase_order_delivery_extensions` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_order_delivery_extensions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_items`
--

DROP TABLE IF EXISTS `purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int(10) unsigned NOT NULL,
  `stock_catalog_id` int(10) unsigned DEFAULT NULL COMMENT 'Reference to stock catalog item if selected from catalog',
  `line_no` int(11) NOT NULL DEFAULT 1,
  `item_type` enum('supply','semi_expendable','equipment') NOT NULL DEFAULT 'supply',
  `item_description` text NOT NULL,
  `classification_id` int(10) unsigned DEFAULT NULL,
  `account_code_id` int(10) unsigned DEFAULT NULL,
  `unit_of_measure_id` int(10) unsigned DEFAULT NULL,
  `quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_po` (`purchase_order_id`),
  KEY `fk_poi_classification` (`classification_id`),
  KEY `fk_poi_account_code` (`account_code_id`),
  KEY `fk_poi_uom` (`unit_of_measure_id`),
  KEY `fk_poi_stock_catalog` (`stock_catalog_id`),
  KEY `idx_purchase_order_items_item_type` (`item_type`),
  CONSTRAINT `fk_poi_account_code` FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_poi_classification` FOREIGN KEY (`classification_id`) REFERENCES `classifications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_poi_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poi_stock_catalog` FOREIGN KEY (`stock_catalog_id`) REFERENCES `stock_catalog` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_poi_uom` FOREIGN KEY (`unit_of_measure_id`) REFERENCES `unit_of_measures` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_items`
--

LOCK TABLES `purchase_order_items` WRITE;
/*!40000 ALTER TABLE `purchase_order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `po_number` varchar(100) NOT NULL,
  `po_date` date NOT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `fund_id` int(10) unsigned DEFAULT NULL,
  `mode_of_procurement_id` int(10) unsigned DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `place_of_delivery` varchar(200) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('encoded','partial','completed','cancelled') NOT NULL DEFAULT 'encoded',
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `supplier_address` text DEFAULT NULL,
  `delivery_term_days` int(11) DEFAULT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_reference` (`system_reference`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_fund` (`fund_id`),
  KEY `fk_po_mode` (`mode_of_procurement_id`),
  CONSTRAINT `fk_po_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_po_mode` FOREIGN KEY (`mode_of_procurement_id`) REFERENCES `mode_of_procurements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_orders`
--

LOCK TABLES `purchase_orders` WRITE;
/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `receiving_item_details`
--

DROP TABLE IF EXISTS `receiving_item_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receiving_item_details` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `receiving_item_id` int(10) unsigned NOT NULL,
  `stock_item_id` int(10) unsigned DEFAULT NULL,
  `brand_id` int(10) unsigned DEFAULT NULL,
  `model_id` int(10) unsigned DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `serial_no` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `is_distributed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_disposed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_receiving_item` (`receiving_item_id`),
  KEY `idx_rid_stock_item_id` (`stock_item_id`),
  CONSTRAINT `fk_rid_receiving_item` FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rid_stock_item` FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rid_stock_item_id` FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `receiving_item_details`
--

LOCK TABLES `receiving_item_details` WRITE;
/*!40000 ALTER TABLE `receiving_item_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `receiving_item_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `receiving_items`
--

DROP TABLE IF EXISTS `receiving_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receiving_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `receiving_id` int(10) unsigned NOT NULL,
  `purchase_order_item_id` int(10) unsigned NOT NULL,
  `stock_no` varchar(20) DEFAULT NULL,
  `quantity_delivered` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quantity_accepted` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quantity_rejected` decimal(15,2) NOT NULL DEFAULT 0.00,
  `item_condition` varchar(100) DEFAULT 'Good Condition',
  `unit_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_receiving` (`receiving_id`),
  KEY `idx_poi` (`purchase_order_item_id`),
  CONSTRAINT `fk_ri_poi` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`),
  CONSTRAINT `fk_ri_receiving` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `receiving_items`
--

LOCK TABLES `receiving_items` WRITE;
/*!40000 ALTER TABLE `receiving_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `receiving_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `receivings`
--

DROP TABLE IF EXISTS `receivings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receivings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `purchase_order_id` int(10) unsigned NOT NULL,
  `ris_no` varchar(100) DEFAULT NULL,
  `received_date` date NOT NULL,
  `delivery_receipt_no` varchar(100) DEFAULT NULL,
  `invoice_no` varchar(100) DEFAULT NULL,
  `inspected_by` varchar(200) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'partial',
  `remarks` text DEFAULT NULL,
  `total_received_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_reference` (`system_reference`),
  KEY `idx_po` (`purchase_order_id`),
  CONSTRAINT `fk_receivings_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `receivings`
--

LOCK TABLES `receivings` WRITE;
/*!40000 ALTER TABLE `receivings` DISABLE KEYS */;
/*!40000 ALTER TABLE `receivings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `responsibility_codes`
--

DROP TABLE IF EXISTS `responsibility_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `responsibility_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `office_id` int(10) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rc_code` (`code`),
  KEY `fk_rc_office` (`office_id`),
  CONSTRAINT `fk_rc_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `responsibility_codes`
--

LOCK TABLES `responsibility_codes` WRITE;
/*!40000 ALTER TABLE `responsibility_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `responsibility_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `returns`
--

DROP TABLE IF EXISTS `returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `returns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `return_date` date NOT NULL,
  `distribution_item_detail_id` bigint(20) unsigned DEFAULT NULL,
  `office_id` int(10) unsigned DEFAULT NULL,
  `employee_id` int(10) unsigned DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'posted',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_reference` (`system_reference`),
  KEY `idx_returns_office_id` (`office_id`),
  KEY `idx_returns_employee_id` (`employee_id`),
  KEY `idx_returns_distribution_item_detail_id` (`distribution_item_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `returns`
--

LOCK TABLES `returns` WRITE;
/*!40000 ALTER TABLE `returns` DISABLE KEYS */;
/*!40000 ALTER TABLE `returns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `name` varchar(100) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `series_numbers`
--

DROP TABLE IF EXISTS `series_numbers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `series_numbers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(100) NOT NULL,
  `prefix` varchar(20) NOT NULL,
  `last_series` int(10) unsigned NOT NULL DEFAULT 0,
  `year` year(4) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `year_value` int(11) DEFAULT NULL,
  `current_value` int(11) NOT NULL DEFAULT 0,
  `padding_length` int(11) NOT NULL DEFAULT 4,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_year` (`module_key`,`year`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `series_numbers`
--

LOCK TABLES `series_numbers` WRITE;
/*!40000 ALTER TABLE `series_numbers` DISABLE KEYS */;
INSERT INTO `series_numbers` VALUES (1,'brands','BRD',0,0000,'2026-03-30 06:36:26',NULL,2026,0,4),(2,'offices','OFF',0,0000,'2026-03-30 06:36:28','2026-03-30 06:36:48',2026,1,4);
/*!40000 ALTER TABLE `series_numbers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_adjustment_items`
--

DROP TABLE IF EXISTS `stock_adjustment_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_adjustment_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `stock_adjustment_id` int(10) unsigned NOT NULL,
  `supply_count_item_id` int(10) unsigned DEFAULT NULL,
  `stock_item_id` int(10) unsigned NOT NULL,
  `system_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
  `counted_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
  `variance_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
  `adjustment_type` enum('increase','decrease') NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stock_adjustment_items_header` (`stock_adjustment_id`),
  KEY `idx_stock_adjustment_items_stock_item` (`stock_item_id`),
  KEY `fk_stock_adjustment_items_count_item` (`supply_count_item_id`),
  CONSTRAINT `fk_stock_adjustment_items_count_item` FOREIGN KEY (`supply_count_item_id`) REFERENCES `supply_count_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_adjustment_items_header` FOREIGN KEY (`stock_adjustment_id`) REFERENCES `stock_adjustments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_adjustment_items_stock_item` FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_adjustment_items`
--

LOCK TABLES `stock_adjustment_items` WRITE;
/*!40000 ALTER TABLE `stock_adjustment_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_adjustment_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_adjustments`
--

DROP TABLE IF EXISTS `stock_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_adjustments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `supply_count_session_id` int(10) unsigned DEFAULT NULL,
  `adjustment_date` date NOT NULL,
  `status` enum('pending','approved','cancelled') NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_reference` (`system_reference`),
  KEY `idx_stock_adjustments_session` (`supply_count_session_id`),
  KEY `idx_stock_adjustments_status_date` (`status`,`adjustment_date`),
  KEY `fk_stock_adjustments_created_by` (`created_by`),
  KEY `idx_stock_adjustments_approved_by` (`approved_by`),
  CONSTRAINT `fk_stock_adjustments_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_adjustments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_stock_adjustments_session` FOREIGN KEY (`supply_count_session_id`) REFERENCES `supply_count_sessions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_adjustments`
--

LOCK TABLES `stock_adjustments` WRITE;
/*!40000 ALTER TABLE `stock_adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_adjustments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_catalog`
--

DROP TABLE IF EXISTS `stock_catalog`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_catalog` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `stock_no` varchar(100) NOT NULL,
  `barcode` varchar(120) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_description` text DEFAULT NULL,
  `item_type` enum('supply','semi_expendable','equipment') NOT NULL DEFAULT 'supply',
  `classification_id` int(10) unsigned DEFAULT NULL,
  `account_code_id` int(10) unsigned DEFAULT NULL,
  `unit_of_measure_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stock_catalog_stock_no` (`stock_no`),
  UNIQUE KEY `uk_stock_catalog_barcode` (`barcode`),
  KEY `idx_stock_catalog_item_type` (`item_type`),
  KEY `idx_stock_catalog_classification_id` (`classification_id`),
  KEY `idx_stock_catalog_account_code_id` (`account_code_id`),
  KEY `fk_sc_uom` (`unit_of_measure_id`),
  KEY `fk_sc_created_by` (`created_by`),
  KEY `fk_sc_updated_by` (`updated_by`),
  KEY `idx_stock_catalog_is_active` (`is_active`),
  CONSTRAINT `fk_sc_account_code` FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_classification` FOREIGN KEY (`classification_id`) REFERENCES `classifications` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_uom` FOREIGN KEY (`unit_of_measure_id`) REFERENCES `unit_of_measures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_catalog`
--

LOCK TABLES `stock_catalog` WRITE;
/*!40000 ALTER TABLE `stock_catalog` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_catalog` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_items`
--

DROP TABLE IF EXISTS `stock_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `stock_catalog_id` int(10) unsigned DEFAULT NULL COMMENT 'Reference to stock catalog item',
  `receiving_id` int(10) unsigned DEFAULT NULL,
  `receiving_item_id` int(10) unsigned DEFAULT NULL,
  `purchase_order_item_id` int(10) unsigned DEFAULT NULL,
  `item_type` enum('supply','semi_expendable','equipment') NOT NULL DEFAULT 'supply',
  `semi_expendable_type` enum('high_value','low_value') DEFAULT NULL,
  `account_code_id` int(10) unsigned DEFAULT NULL,
  `classification_id` int(10) unsigned DEFAULT NULL,
  `unit_of_measure_id` int(10) unsigned DEFAULT NULL,
  `item_description` text DEFAULT NULL,
  `unit_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quantity_received` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quantity_issued` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quantity_on_hand` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_reference` (`system_reference`),
  KEY `idx_receiving_item` (`receiving_item_id`),
  KEY `idx_item_type` (`item_type`),
  KEY `fk_si_stock_catalog` (`stock_catalog_id`),
  CONSTRAINT `fk_si_stock_catalog` FOREIGN KEY (`stock_catalog_id`) REFERENCES `stock_catalog` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_items`
--

LOCK TABLES `stock_items` WRITE;
/*!40000 ALTER TABLE `stock_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_movements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `stock_item_id` int(10) unsigned NOT NULL,
  `movement_type` enum('receipt','issue','return','adjustment','disposal') NOT NULL,
  `movement_date` date NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(10) unsigned DEFAULT NULL,
  `quantity_in` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quantity_out` decimal(15,2) NOT NULL DEFAULT 0.00,
  `balance_after` decimal(15,2) NOT NULL DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stock_item` (`stock_item_id`),
  CONSTRAINT `fk_sm_stock_item` FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_movements`
--

LOCK TABLES `stock_movements` WRITE;
/*!40000 ALTER TABLE `stock_movements` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_numbers`
--

DROP TABLE IF EXISTS `stock_numbers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_numbers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `classification_id` int(10) unsigned DEFAULT NULL,
  `abbreviation` varchar(10) NOT NULL,
  `stock_no` varchar(20) NOT NULL,
  `item_description` text NOT NULL,
  `unit_of_measure_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stock_no` (`stock_no`),
  KEY `idx_classification` (`classification_id`),
  KEY `idx_description` (`item_description`(100)),
  CONSTRAINT `fk_sn_classification` FOREIGN KEY (`classification_id`) REFERENCES `classifications` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_numbers`
--

LOCK TABLES `stock_numbers` WRITE;
/*!40000 ALTER TABLE `stock_numbers` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_numbers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(200) NOT NULL,
  `address` text DEFAULT NULL,
  `contact_person` varchar(200) DEFAULT NULL,
  `contact_no` varchar(50) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `tin` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `supplier_code` varchar(50) DEFAULT NULL,
  `tin_no` varchar(50) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_suppliers_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supply_count_items`
--

DROP TABLE IF EXISTS `supply_count_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supply_count_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int(10) unsigned NOT NULL,
  `stock_item_id` int(10) unsigned NOT NULL,
  `stock_catalog_id` int(10) unsigned DEFAULT NULL,
  `stock_reference` varchar(50) NOT NULL,
  `stock_no` varchar(120) DEFAULT NULL,
  `barcode` varchar(120) DEFAULT NULL,
  `item_description` varchar(255) NOT NULL,
  `classification_name` varchar(255) DEFAULT NULL,
  `account_code` varchar(50) DEFAULT NULL,
  `unit_of_measure` varchar(100) DEFAULT NULL,
  `system_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
  `counted_quantity` decimal(15,2) DEFAULT NULL,
  `variance_quantity` decimal(15,2) DEFAULT NULL,
  `count_status` enum('pending','match','shortage','overage','not_counted') NOT NULL DEFAULT 'pending',
  `remarks` text DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `checked_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supply_count_session_stock_item` (`session_id`,`stock_item_id`),
  KEY `idx_supply_count_items_session_status` (`session_id`,`count_status`),
  KEY `idx_supply_count_items_barcode` (`barcode`),
  KEY `idx_supply_count_items_stock_no` (`stock_no`),
  KEY `fk_supply_count_items_stock_item` (`stock_item_id`),
  KEY `fk_supply_count_items_stock_catalog` (`stock_catalog_id`),
  KEY `fk_supply_count_items_checked_by` (`checked_by`),
  CONSTRAINT `fk_supply_count_items_checked_by` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_supply_count_items_session` FOREIGN KEY (`session_id`) REFERENCES `supply_count_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_supply_count_items_stock_catalog` FOREIGN KEY (`stock_catalog_id`) REFERENCES `stock_catalog` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_supply_count_items_stock_item` FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supply_count_items`
--

LOCK TABLES `supply_count_items` WRITE;
/*!40000 ALTER TABLE `supply_count_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `supply_count_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supply_count_sessions`
--

DROP TABLE IF EXISTS `supply_count_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supply_count_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `system_reference` varchar(50) NOT NULL,
  `count_type` enum('annual','surprise') NOT NULL DEFAULT 'annual',
  `count_date` date NOT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_reference` (`system_reference`),
  KEY `idx_supply_count_sessions_status` (`status`),
  KEY `idx_supply_count_sessions_count_date` (`count_date`),
  KEY `fk_supply_count_sessions_created_by` (`created_by`),
  KEY `fk_supply_count_sessions_closed_by` (`closed_by`),
  CONSTRAINT `fk_supply_count_sessions_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_supply_count_sessions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supply_count_sessions`
--

LOCK TABLES `supply_count_sessions` WRITE;
/*!40000 ALTER TABLE `supply_count_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `supply_count_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `idx_system_settings_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `unit_of_measures`
--

DROP TABLE IF EXISTS `unit_of_measures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `unit_of_measures` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uom_name` varchar(100) NOT NULL,
  `abbreviation` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uom_code` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `unit_of_measures`
--

LOCK TABLES `unit_of_measures` WRITE;
/*!40000 ALTER TABLE `unit_of_measures` DISABLE KEYS */;
INSERT INTO `unit_of_measures` VALUES (24,'Piece','pc',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(25,'Unit','unit',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(26,'Set','set',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(27,'Lot','lot',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(28,'Pair','pair',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(29,'Pack','pack',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(30,'Bundle','bundle',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(31,'Roll','roll',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(32,'Box','box',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(33,'Carton','ctn',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(34,'Ream','ream',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(35,'Sheet','sht',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(36,'Pad','pad',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(37,'Book','book',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(38,'Booklet','booklet',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(39,'Liter','L',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(40,'Milliliter','mL',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(41,'Gallon','gal',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(42,'Drum','drum',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(43,'Bottle','btl',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(44,'Can','can',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(45,'Tube','tube',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(46,'Sachet','sachet',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(47,'Container','cont',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(48,'Kilogram','kg',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(49,'Gram','g',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(50,'Pound','lb',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(51,'Ton','ton',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(52,'Bag','bag',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(53,'Sack','sack',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(54,'Meter','m',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(55,'Centimeter','cm',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(56,'Foot','ft',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(57,'Linear Meter','lm',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(58,'Square Meter','sqm',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(59,'Hour','hr',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(60,'Day','day',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(61,'Month','mo',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(62,'Year','yr',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(63,'Cartridge','ctdg',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(64,'Toner','tnr',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(65,'Token','token',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(66,'License','lic',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL),(67,'Ink Bottle','ink btl',1,'2026-03-27 15:52:06',NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `unit_of_measures` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_messages`
--

DROP TABLE IF EXISTS `user_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sender_user_id` int(10) unsigned NOT NULL,
  `recipient_user_id` int(10) unsigned NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `message_body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `hidden_for_sender` tinyint(1) NOT NULL DEFAULT 0,
  `hidden_for_recipient` tinyint(1) NOT NULL DEFAULT 0,
  `related_table` varchar(50) DEFAULT NULL,
  `related_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sender` (`sender_user_id`),
  KEY `idx_recipient` (`recipient_user_id`),
  KEY `idx_recipient_read` (`recipient_user_id`,`is_read`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_messages`
--

LOCK TABLES `user_messages` WRITE;
/*!40000 ALTER TABLE `user_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_presence`
--

DROP TABLE IF EXISTS `user_presence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_presence` (
  `user_id` int(10) unsigned NOT NULL,
  `last_seen_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`),
  KEY `idx_last_seen_at` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_presence`
--

LOCK TABLES `user_presence` WRITE;
/*!40000 ALTER TABLE `user_presence` DISABLE KEYS */;
INSERT INTO `user_presence` VALUES (1,'2026-03-30 14:36:59');
/*!40000 ALTER TABLE `user_presence` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(200) DEFAULT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `password_hash` varchar(255) DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `failed_login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `employee_id` int(10) unsigned DEFAULT NULL,
  `office_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `fk_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'spamsdb'
--

--
-- Dumping routines for database 'spamsdb'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-30 14:55:29
