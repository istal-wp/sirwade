-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 17, 2026 at 07:53 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `loogistics`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `get_checkinout_stats` (IN `start_date` DATE, IN `end_date` DATE)   BEGIN
    SELECT 
        COUNT(*) as total_transactions,
        SUM(CASE WHEN action = 'check_out' THEN 1 ELSE 0 END) as total_checkouts,
        SUM(CASE WHEN action = 'check_in' THEN 1 ELSE 0 END) as total_checkins,
        COUNT(DISTINCT asset_id) as unique_assets,
        COUNT(DISTINCT performed_by) as unique_users,
        DATE(action_date) as transaction_date
    FROM check_in_out_history
    WHERE action_date BETWEEN start_date AND end_date
    GROUP BY DATE(action_date)
    ORDER BY transaction_date DESC;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `update_overdue_maintenance` ()   BEGIN
    UPDATE `maintenance_schedule` 
    SET `status` = 'overdue' 
    WHERE `status` = 'scheduled' 
    AND `scheduled_date` < CURDATE();
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `calculate_straight_line_depreciation` (`purchase_cost` DECIMAL(15,2), `useful_life_years` INT, `age_years` DECIMAL(5,2)) RETURNS DECIMAL(15,2) DETERMINISTIC BEGIN
    DECLARE depreciation DECIMAL(15,2);
    DECLARE annual_depreciation DECIMAL(15,2);
    
    SET annual_depreciation = purchase_cost / useful_life_years;
    SET depreciation = annual_depreciation * age_years;
    
    IF depreciation > purchase_cost THEN
        SET depreciation = purchase_cost;
    END IF;
    
    RETURN GREATEST(0, purchase_cost - depreciation);
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `application_documents`
--

CREATE TABLE `application_documents` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `document_type` varchar(100) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assets`
--

CREATE TABLE `assets` (
  `id` int(11) NOT NULL,
  `asset_code` varchar(50) NOT NULL,
  `asset_name` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(15,2) DEFAULT 0.00,
  `supplier_id` int(11) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `status` enum('active','maintenance','retired','disposed') DEFAULT 'active',
  `condition_rating` enum('excellent','good','fair','poor','critical') DEFAULT 'good',
  `warranty_expiry` date DEFAULT NULL,
  `depreciation_method` enum('straight_line','declining_balance','sum_of_years') DEFAULT 'straight_line',
  `useful_life_years` int(11) DEFAULT 5,
  `current_value` decimal(15,2) DEFAULT 0.00,
  `last_depreciation_date` date DEFAULT NULL,
  `accumulated_depreciation` decimal(15,2) DEFAULT 0.00,
  `next_maintenance` date DEFAULT NULL,
  `maintenance_frequency_days` int(11) DEFAULT 90,
  `assigned_to` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assets`
--

INSERT INTO `assets` (`id`, `asset_code`, `asset_name`, `category`, `brand`, `model`, `serial_number`, `purchase_date`, `purchase_cost`, `supplier_id`, `location`, `status`, `condition_rating`, `warranty_expiry`, `depreciation_method`, `useful_life_years`, `current_value`, `last_depreciation_date`, `accumulated_depreciation`, `next_maintenance`, `maintenance_frequency_days`, `assigned_to`, `notes`, `description`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'AST-2025-09-0001', '1231', 'furniture', '1312', '3112', '312', '2025-09-22', 3123.00, 1, '3123', 'active', 'good', '2025-10-09', 'straight_line', 5, 3123.00, NULL, 0.00, '2025-12-21', 90, NULL, '12321', NULL, 'Dominic Agravante', '2025-09-22 06:44:57', '2025-10-09 05:12:45'),
(2, 'AST-2025-10-0001', 'Wow', 'vehicles', '13', '21', '312312', '2021-10-29', 13.00, NULL, '31232', 'maintenance', 'good', NULL, 'declining_balance', 2, 213.00, NULL, 0.00, '2026-01-07', 90, '21312', '13', '13', 'Josh Cudiamat', '2025-10-09 05:09:46', '2025-10-09 06:21:29'),
(3, 'AST-2025-10-0002', '121', 'vehicles', '1231', '322312', '31', '2025-09-30', 231.00, NULL, '131', 'maintenance', 'good', NULL, 'declining_balance', 12, 123.00, NULL, 108.00, '2925-12-21', 90, '12312', '21', '131', 'Josh Cudiamat', '2025-10-09 05:12:33', '2025-10-09 05:58:18');

--
-- Triggers `assets`
--
DELIMITER $$
CREATE TRIGGER `log_asset_value_change` AFTER UPDATE ON `assets` FOR EACH ROW BEGIN
    IF OLD.current_value <> NEW.current_value THEN
        INSERT INTO depreciation_history (
            asset_id, 
            calculation_date, 
            depreciation_method,
            opening_value,
            depreciation_amount,
            closing_value,
            accumulated_depreciation,
            remaining_life_years,
            notes,
            created_at
        ) VALUES (
            NEW.id,
            CURDATE(),
            NEW.depreciation_method,
            OLD.current_value,
            OLD.current_value - NEW.current_value,
            NEW.current_value,
            NEW.purchase_cost - NEW.current_value,
            GREATEST(0, NEW.useful_life_years - TIMESTAMPDIFF(YEAR, NEW.purchase_date, CURDATE())),
            CONCAT('Value changed from ', OLD.current_value, ' to ', NEW.current_value),
            NOW()
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `asset_movements`
--

CREATE TABLE `asset_movements` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `movement_type` enum('deployment','transfer','return','maintenance','disposal') NOT NULL,
  `from_location` varchar(255) DEFAULT NULL,
  `to_location` varchar(255) DEFAULT NULL,
  `from_person` varchar(255) DEFAULT NULL,
  `to_person` varchar(255) DEFAULT NULL,
  `movement_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `condition_before` enum('excellent','good','fair','poor','critical') DEFAULT NULL,
  `condition_after` enum('excellent','good','fair','poor','critical') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `performed_by` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `asset_movements`
--

INSERT INTO `asset_movements` (`id`, `asset_id`, `movement_type`, `from_location`, `to_location`, `from_person`, `to_person`, `movement_date`, `reason`, `condition_before`, `condition_after`, `notes`, `performed_by`, `created_at`) VALUES
(1, 1, 'transfer', '1321', '312312', '3123123', '23123123', '2025-10-28', '3123123', 'good', 'excellent', '1131', 'Josh Cudiamat', '2025-10-07 11:03:38'),
(2, 1, 'deployment', '12312', '31', '23123', '123123', '2025-10-28', '1313', 'good', 'good', '131', 'Josh Cudiamat', '2025-10-07 11:04:03'),
(3, 1, 'return', '31', '3123', NULL, NULL, '1212-12-12', 'Asset Check-In', NULL, NULL, '123', 'Cudiamatcute21@gmail.com', '2025-10-09 05:12:45'),
(4, 2, 'deployment', '1313', '31232', '313', '21312', '1312-12-31', 'Asset Check-Out', 'good', 'good', '12312', 'Cudiamatcute21@gmail.com', '2025-10-09 06:21:29'),
(5, 2, 'deployment', '31232', '31232', '21312', '21312', '1312-12-31', 'Asset Check-Out', 'good', 'good', '12312', 'Cudiamatcute21@gmail.com', '2025-10-09 06:21:29');

--
-- Triggers `asset_movements`
--
DELIMITER $$
CREATE TRIGGER `check_movement_date_before_insert` BEFORE INSERT ON `asset_movements` FOR EACH ROW BEGIN
    IF NEW.movement_date > CURDATE() THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Movement date cannot be in the future';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `asset_summary_view`
-- (See below for the actual view)
--
CREATE TABLE `asset_summary_view` (
`id` int(11)
,`asset_code` varchar(50)
,`asset_name` varchar(255)
,`category` varchar(100)
,`status` enum('active','maintenance','retired','disposed')
,`condition_rating` enum('excellent','good','fair','poor','critical')
,`location` varchar(255)
,`assigned_to` varchar(255)
,`purchase_date` date
,`purchase_cost` decimal(15,2)
,`current_value` decimal(15,2)
,`depreciation_method` enum('straight_line','declining_balance','sum_of_years')
,`useful_life_years` int(11)
,`next_maintenance` date
,`age_years` decimal(12,4)
,`remaining_life_years` decimal(15,4)
,`total_depreciation` decimal(16,2)
,`depreciation_percentage` decimal(25,6)
,`scheduled_maintenance_count` bigint(21)
,`overdue_maintenance_count` bigint(21)
,`total_movements` bigint(21)
);

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` int(11) NOT NULL,
  `action` enum('CREATE','UPDATE','DELETE','VIEW') NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `performed_by` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `checkinout_summary_view`
-- (See below for the actual view)
--
CREATE TABLE `checkinout_summary_view` (
`id` int(11)
,`asset_id` int(11)
,`action` enum('check_in','check_out')
,`action_date` date
,`assigned_to` varchar(255)
,`previous_assigned_to` varchar(255)
,`location` varchar(255)
,`previous_location` varchar(255)
,`notes` text
,`condition_before` enum('excellent','good','fair','poor','critical')
,`condition_after` enum('excellent','good','fair','poor','critical')
,`performed_by` varchar(255)
,`created_at` timestamp
,`asset_code` varchar(50)
,`asset_name` varchar(255)
,`category` varchar(100)
,`current_location` varchar(255)
,`current_assigned_to` varchar(255)
,`current_status` enum('active','maintenance','retired','disposed')
,`current_condition` enum('excellent','good','fair','poor','critical')
);

-- --------------------------------------------------------

--
-- Table structure for table `check_in_out_history`
--

CREATE TABLE `check_in_out_history` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `action` enum('check_in','check_out') NOT NULL,
  `action_date` date NOT NULL,
  `assigned_to` varchar(255) DEFAULT NULL,
  `previous_assigned_to` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `previous_location` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `condition_before` enum('excellent','good','fair','poor','critical') DEFAULT NULL,
  `condition_after` enum('excellent','good','fair','poor','critical') DEFAULT NULL,
  `performed_by` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tracks all asset check-in and check-out transactions with historical data';

--
-- Dumping data for table `check_in_out_history`
--

INSERT INTO `check_in_out_history` (`id`, `asset_id`, `action`, `action_date`, `assigned_to`, `previous_assigned_to`, `location`, `previous_location`, `notes`, `condition_before`, `condition_after`, `performed_by`, `created_at`) VALUES
(4, 1, 'check_in', '1212-12-12', '132', NULL, '3123', NULL, '123', NULL, NULL, '', '2025-10-09 05:12:45'),
(5, 2, 'check_out', '1312-12-31', '21312', '313', '31232', '1313', '12312', NULL, NULL, 'Cudiamatcute21@gmail.com', '2025-10-09 06:21:29'),
(6, 2, 'check_out', '1312-12-31', '21312', '21312', '31232', '31232', '12312', NULL, NULL, 'Cudiamatcute21@gmail.com', '2025-10-09 06:21:29');

--
-- Triggers `check_in_out_history`
--
DELIMITER $$
CREATE TRIGGER `check_checkinout_date_before_insert` BEFORE INSERT ON `check_in_out_history` FOR EACH ROW BEGIN
    IF NEW.action_date > CURDATE() THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Check in/out date cannot be in the future';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `compliance_requirements`
--

CREATE TABLE `compliance_requirements` (
  `id` int(11) NOT NULL,
  `requirement_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `regulatory_body` varchar(255) DEFAULT NULL,
  `requirement_type` enum('mandatory','optional','recommended') DEFAULT 'mandatory',
  `applicable_document_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`applicable_document_types`)),
  `deadline_type` enum('fixed','recurring','event_based') DEFAULT 'fixed',
  `recurring_period_days` int(11) DEFAULT NULL,
  `penalty_description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `compliance_requirements`
--

INSERT INTO `compliance_requirements` (`id`, `requirement_name`, `description`, `regulatory_body`, `requirement_type`, `applicable_document_types`, `deadline_type`, `recurring_period_days`, `penalty_description`, `is_active`, `created_at`) VALUES
(1, 'needle', '21', '23', 'mandatory', '[\"1\"]', 'fixed', NULL, '12', 1, '2025-09-20 14:10:38');

-- --------------------------------------------------------

--
-- Table structure for table `delivery_status`
--

CREATE TABLE `delivery_status` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `delivery_item` varchar(255) NOT NULL,
  `expected_delivery_date` date NOT NULL,
  `actual_delivery_date` date DEFAULT NULL,
  `delivery_status` enum('pending','in_transit','delivered','delayed','cancelled') DEFAULT 'pending',
  `tracking_number` varchar(100) DEFAULT NULL,
  `supplier` varchar(255) DEFAULT NULL,
  `quantity` decimal(15,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `department_coordination`
--

CREATE TABLE `department_coordination` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `department_name` varchar(255) NOT NULL,
  `coordinator_name` varchar(255) NOT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `responsibility` text DEFAULT NULL,
  `status` enum('assigned','in_progress','completed','blocked') DEFAULT 'assigned',
  `last_update_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `depreciation_calculations`
--

CREATE TABLE `depreciation_calculations` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `calculation_date` date NOT NULL,
  `method` enum('straight_line','declining_balance','sum_of_years') NOT NULL,
  `purchase_cost` decimal(15,2) NOT NULL,
  `age_years` decimal(10,4) NOT NULL,
  `useful_life_years` int(11) NOT NULL,
  `previous_value` decimal(15,2) NOT NULL,
  `calculated_value` decimal(15,2) NOT NULL,
  `annual_depreciation` decimal(15,2) NOT NULL,
  `accumulated_depreciation` decimal(15,2) NOT NULL,
  `depreciation_rate` decimal(10,4) DEFAULT NULL,
  `calculation_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `depreciation_history`
--

CREATE TABLE `depreciation_history` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `calculation_date` date NOT NULL,
  `depreciation_method` enum('straight_line','declining_balance','sum_of_years') DEFAULT 'straight_line',
  `opening_value` decimal(15,2) DEFAULT 0.00,
  `depreciation_amount` decimal(15,2) DEFAULT 0.00,
  `closing_value` decimal(15,2) DEFAULT 0.00,
  `accumulated_depreciation` decimal(15,2) DEFAULT 0.00,
  `remaining_life_years` decimal(5,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `document_code` varchar(100) NOT NULL,
  `document_type_id` int(11) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `version` decimal(3,2) DEFAULT 1.00,
  `status` enum('draft','active','archived','expired','pending_approval') DEFAULT 'draft',
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `tags` text DEFAULT NULL,
  `created_by` varchar(255) NOT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `related_project_id` int(11) DEFAULT NULL,
  `related_po_id` int(11) DEFAULT NULL,
  `related_supplier_id` int(11) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`id`, `document_code`, `document_type_id`, `title`, `description`, `file_path`, `file_name`, `file_size`, `file_type`, `version`, `status`, `priority`, `tags`, `created_by`, `approved_by`, `approved_at`, `expiry_date`, `related_project_id`, `related_po_id`, `related_supplier_id`, `metadata`, `created_at`, `updated_at`) VALUES
(1, 'DOC-20250920-4476', 1, 'ad', 's', 'uploads/documents/DOC-20250920-4476.docx', 'Leap Video.docx', 355135, 'application/vnd.openxmlformats-officedocument.word', 1.00, 'pending_approval', 'critical', 'Lol', 'Dominic Agravante', NULL, NULL, '2025-10-28', 1, NULL, 1, NULL, '2025-09-20 13:58:44', '2025-09-20 13:58:44');

-- --------------------------------------------------------

--
-- Table structure for table `document_access_logs`
--

CREATE TABLE `document_access_logs` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `accessed_by` varchar(255) NOT NULL,
  `access_type` enum('view','download','edit','share','delete') NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `access_details` text DEFAULT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_access_logs`
--

INSERT INTO `document_access_logs` (`id`, `document_id`, `accessed_by`, `access_type`, `ip_address`, `user_agent`, `access_details`, `accessed_at`) VALUES
(1, 1, 'Dominic Agravante', 'view', '::1', NULL, 'Document viewed via web interface', '2025-09-20 14:09:33'),
(2, 1, 'Dominic Agravante', 'download', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'File downloaded: Leap Video.docx', '2025-09-20 14:09:44'),
(3, 1, 'Dominic Agravante', 'view', '::1', NULL, 'Document viewed via web interface', '2025-09-20 14:27:09'),
(4, 1, 'Dominic Agravante', 'view', '::1', NULL, 'Document viewed via web interface', '2025-09-21 02:28:56'),
(5, 1, 'Josh Cudiamat', 'view', '::1', NULL, 'Document viewed via web interface', '2025-09-26 14:13:30'),
(6, 1, 'Josh Cudiamat', 'view', '::1', NULL, 'Document viewed via web interface', '2025-09-27 09:47:56'),
(7, 1, 'Josh Cudiamat', 'view', '::1', NULL, 'Document viewed via web interface', '2025-09-27 10:11:22'),
(8, 1, 'Josh Cudiamat', 'view', '::1', NULL, 'Document viewed via web interface', '2025-09-27 10:16:47');

-- --------------------------------------------------------

--
-- Table structure for table `document_compliance`
--

CREATE TABLE `document_compliance` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `requirement_id` int(11) NOT NULL,
  `compliance_status` enum('compliant','non_compliant','pending','not_applicable') DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `evidence_document_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `last_reviewed_by` varchar(255) DEFAULT NULL,
  `last_reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_compliance`
--

INSERT INTO `document_compliance` (`id`, `document_id`, `requirement_id`, `compliance_status`, `due_date`, `completed_date`, `evidence_document_id`, `notes`, `last_reviewed_by`, `last_reviewed_at`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'pending', '2025-10-28', NULL, NULL, '', NULL, NULL, '2025-09-21 02:29:23', '2025-09-21 02:29:23');

-- --------------------------------------------------------

--
-- Table structure for table `document_notifications`
--

CREATE TABLE `document_notifications` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `notification_type` enum('expiry_reminder','compliance_due','approval_needed','review_due') NOT NULL,
  `recipient` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `scheduled_date` date NOT NULL,
  `sent_date` timestamp NULL DEFAULT NULL,
  `status` enum('pending','sent','failed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_relationships`
--

CREATE TABLE `document_relationships` (
  `id` int(11) NOT NULL,
  `parent_document_id` int(11) NOT NULL,
  `child_document_id` int(11) NOT NULL,
  `relationship_type` enum('parent_child','related','supersedes','reference') DEFAULT 'related',
  `description` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_types`
--

CREATE TABLE `document_types` (
  `id` int(11) NOT NULL,
  `type_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `required_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_fields`)),
  `retention_period_days` int(11) DEFAULT 365,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_types`
--

INSERT INTO `document_types` (`id`, `type_name`, `description`, `required_fields`, `retention_period_days`, `is_active`, `created_at`) VALUES
(1, 'Wow', 'needed', '[\"approval_required\",\"expiry_date\",\"digital_signature\",\"version_control\"]', 365, 1, '2025-09-20 13:32:58');

-- --------------------------------------------------------

--
-- Table structure for table `document_versions`
--

CREATE TABLE `document_versions` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `version_number` decimal(3,2) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `changes_description` text DEFAULT NULL,
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_workflows`
--

CREATE TABLE `document_workflows` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `step_name` varchar(255) NOT NULL,
  `step_order` int(11) NOT NULL,
  `assigned_to` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected','skipped') DEFAULT 'pending',
  `comments` text DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_workflows`
--

INSERT INTO `document_workflows` (`id`, `document_id`, `step_name`, `step_order`, `assigned_to`, `status`, `comments`, `completed_at`, `due_date`, `created_at`) VALUES
(1, 1, '12', 1, '34', 'approved', '', '2025-09-20 22:06:17', '2025-10-28', '2025-09-21 04:06:12'),
(2, 1, 'ab', 1, 'cd', 'approved', '', '2025-09-20 22:08:56', '2025-10-28', '2025-09-21 04:08:50');

-- --------------------------------------------------------

--
-- Table structure for table `goods_receipts`
--

CREATE TABLE `goods_receipts` (
  `id` int(11) NOT NULL,
  `receipt_number` varchar(50) NOT NULL,
  `po_id` int(11) NOT NULL,
  `supplier_delivery_note` varchar(100) DEFAULT NULL,
  `receipt_date` date NOT NULL,
  `received_by` int(11) DEFAULT NULL,
  `status` enum('partial','complete','over_delivered','damaged') DEFAULT 'partial',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `goods_receipt_items`
--

CREATE TABLE `goods_receipt_items` (
  `id` int(11) NOT NULL,
  `receipt_id` int(11) NOT NULL,
  `po_item_id` int(11) NOT NULL,
  `quantity_received` decimal(15,4) NOT NULL,
  `quantity_accepted` decimal(15,4) NOT NULL,
  `quantity_rejected` decimal(15,4) DEFAULT 0.0000,
  `rejection_reason` text DEFAULT NULL,
  `unit_price` decimal(15,4) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_alerts`
--

CREATE TABLE `inventory_alerts` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `alert_type` enum('low_stock','overstock','expiry','damage','reorder') NOT NULL,
  `current_quantity` int(11) NOT NULL,
  `threshold_quantity` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `priority` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `status` enum('active','acknowledged','resolved','ignored') NOT NULL DEFAULT 'active',
  `acknowledged_by` varchar(255) DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `resolved_by` varchar(255) DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_edit_history`
--

CREATE TABLE `inventory_edit_history` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `edited_by` varchar(255) NOT NULL,
  `edit_reason` text DEFAULT NULL,
  `edited_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_items`
--

CREATE TABLE `inventory_items` (
  `id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_code` varchar(100) NOT NULL,
  `category` varchar(100) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `supplier` varchar(255) DEFAULT NULL,
  `location` varchar(255) NOT NULL,
  `reorder_level` int(11) NOT NULL DEFAULT 10,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive','discontinued') NOT NULL DEFAULT 'active',
  `created_by` varchar(255) NOT NULL,
  `last_edited_by` varchar(255) DEFAULT NULL,
  `edit_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventory_items`
--

INSERT INTO `inventory_items` (`id`, `item_name`, `item_code`, `category`, `quantity`, `unit_price`, `supplier`, `location`, `reorder_level`, `description`, `status`, `created_by`, `last_edited_by`, `edit_count`, `created_at`, `last_updated`) VALUES
(1, 'Phone', 'ELE-PHON-984', 'Electronics', 8, 5000.00, 'Wawas', 'Bestlink College of the Philippines', 2, 'Wow', 'active', 'dominicagravante21@gmail.com', NULL, 0, '2025-09-22 04:45:28', '2025-10-07 05:30:07');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_locations`
--

CREATE TABLE `inventory_locations` (
  `id` int(11) NOT NULL,
  `location_code` varchar(50) NOT NULL,
  `location_name` varchar(255) NOT NULL,
  `location_type` enum('warehouse','store','depot','temporary') NOT NULL DEFAULT 'warehouse',
  `address` text DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `manager` varchar(255) DEFAULT NULL,
  `contact_info` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `id` int(11) NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `unit_of_measure` varchar(20) DEFAULT 'pcs',
  `standard_cost` decimal(15,4) DEFAULT 0.0000,
  `reorder_level` int(11) DEFAULT 0,
  `status` enum('active','inactive','discontinued') DEFAULT 'active',
  `specifications` text DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_categories`
--

CREATE TABLE `item_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_category_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `maintenance_dashboard_view`
-- (See below for the actual view)
--
CREATE TABLE `maintenance_dashboard_view` (
`id` int(11)
,`asset_id` int(11)
,`maintenance_title` varchar(255)
,`maintenance_type` enum('preventive','corrective','emergency','routine')
,`scheduled_date` date
,`status` enum('scheduled','in_progress','completed','overdue')
,`priority` enum('low','medium','high')
,`estimated_cost` decimal(15,2)
,`actual_cost` decimal(15,2)
,`assigned_technician` varchar(255)
,`asset_code` varchar(50)
,`asset_name` varchar(255)
,`category` varchar(100)
,`location` varchar(255)
,`days_until_due` int(7)
,`days_overdue` int(7)
);

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_schedule`
--

CREATE TABLE `maintenance_schedule` (
  `id` int(11) NOT NULL,
  `maintenance_id` varchar(50) DEFAULT NULL,
  `asset_id` int(11) NOT NULL,
  `maintenance_title` varchar(255) NOT NULL,
  `maintenance_type` enum('preventive','corrective','emergency','routine') DEFAULT 'preventive',
  `description` text DEFAULT NULL,
  `scheduled_date` date NOT NULL,
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `status` enum('scheduled','in_progress','completed','overdue') DEFAULT 'scheduled',
  `estimated_cost` decimal(15,2) DEFAULT 0.00,
  `actual_cost` decimal(15,2) DEFAULT 0.00,
  `assigned_technician` varchar(255) DEFAULT NULL,
  `parts_used` text DEFAULT NULL,
  `work_performed` text DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `next_maintenance_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maintenance_schedule`
--

INSERT INTO `maintenance_schedule` (`id`, `maintenance_id`, `asset_id`, `maintenance_title`, `maintenance_type`, `description`, `scheduled_date`, `priority`, `status`, `estimated_cost`, `actual_cost`, `assigned_technician`, `parts_used`, `work_performed`, `completed_date`, `next_maintenance_date`, `notes`, `created_at`, `updated_at`) VALUES
(1, NULL, 3, '123', 'corrective', '21312', '2925-12-21', 'medium', 'scheduled', 121.00, 0.00, '321321', NULL, NULL, NULL, NULL, NULL, '2025-10-09 05:13:44', '2025-10-09 05:13:44');

--
-- Triggers `maintenance_schedule`
--
DELIMITER $$
CREATE TRIGGER `check_maintenance_date_before_insert` BEFORE INSERT ON `maintenance_schedule` FOR EACH ROW BEGIN
    IF NEW.scheduled_date < CURDATE() THEN
        SET NEW.status = 'overdue';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_schedules`
--

CREATE TABLE `maintenance_schedules` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `maintenance_type` varchar(100) NOT NULL,
  `maintenance_title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `scheduled_date` date NOT NULL,
  `completed_date` date DEFAULT NULL,
  `status` enum('scheduled','in-progress','completed','overdue') NOT NULL DEFAULT 'scheduled',
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `estimated_cost` decimal(15,2) DEFAULT 0.00,
  `actual_cost` decimal(15,2) DEFAULT 0.00,
  `assigned_technician` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `project_code` varchar(50) NOT NULL,
  `project_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `client_name` varchar(255) DEFAULT NULL,
  `project_manager_id` int(11) DEFAULT NULL,
  `start_date` date NOT NULL,
  `expected_end_date` date NOT NULL,
  `actual_end_date` date DEFAULT NULL,
  `status` enum('planning','active','on_hold','completed','cancelled') DEFAULT 'planning',
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `budget` decimal(15,2) DEFAULT 0.00,
  `actual_cost` decimal(15,2) DEFAULT 0.00,
  `progress_percentage` decimal(5,2) DEFAULT 0.00,
  `location` varchar(255) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'Philippines',
  `region` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `project_code`, `project_name`, `description`, `client_name`, `project_manager_id`, `start_date`, `expected_end_date`, `actual_end_date`, `status`, `priority`, `budget`, `actual_cost`, `progress_percentage`, `location`, `country`, `region`, `city`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Was', '123', 'Mahirap \'tp', 'John', NULL, '2025-09-19', '2025-09-20', NULL, 'planning', 'critical', 10000.00, 0.00, 40.00, 'Bestlink College of the Philippines', 'Philippines', NULL, NULL, 'Need', '0', '2025-09-19 13:55:14', '2025-10-03 13:35:25'),
(2, 'PROJ-2025-10-0001', '123', '131', 'wow', NULL, '2025-10-02', '2025-11-07', NULL, 'planning', 'medium', 13131.00, 0.00, 2.00, 'B31 L1 Bougainvilla St. Maligaya Park Subd. Q.C', 'Philippines', 'NCR', 'Quezon City', 'dad', '7', '2025-10-02 05:03:29', '2025-10-02 05:03:29'),
(3, 'PROJ-2025-10-0002', '123', '131', 'wow', NULL, '2025-10-02', '2025-11-07', NULL, 'planning', 'medium', 13131.00, 0.00, 2.00, 'B31 L1 Bougainvilla St. Maligaya Park Subd. Q.C', 'Philippines', 'NCR', 'Quezon City', 'dad', '7', '2025-10-02 05:05:07', '2025-10-02 05:05:07'),
(4, 'PROJ-2025-10-0003', '123', '131', 'wow', NULL, '2025-10-02', '2025-11-07', NULL, 'planning', 'medium', 13131.00, 0.00, 2.00, 'B31 L1 Bougainvilla St. Maligaya Park Subd. Q.C', 'Philippines', 'NCR', 'Quezon City', 'dad', '7', '2025-10-02 05:05:18', '2025-10-02 05:05:18'),
(5, 'PROJ-2025-10-0004', '123', '131', 'wow', NULL, '2025-10-02', '2025-11-07', NULL, 'planning', 'medium', 13131.00, 0.00, 2.00, 'B31 L1 Bougainvilla St. Maligaya Park Subd. Q.C', 'Philippines', 'NCR', 'Quezon City', 'dad', '7', '2025-10-02 05:07:31', '2025-10-02 05:07:31'),
(6, 'PROJ-2025-10-0005', '123', '131', 'wow', NULL, '2025-10-02', '2025-11-07', NULL, 'planning', 'medium', 13131.00, 0.00, 60.00, 'B31 L1 Bougainvilla St. Maligaya Park Subd. Q.C', 'Philippines', 'NCR', 'Quezon City', 'dad', '7', '2025-10-02 05:08:20', '2025-10-09 04:36:32');

-- --------------------------------------------------------

--
-- Table structure for table `project_milestones`
--

CREATE TABLE `project_milestones` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `milestone_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` date NOT NULL,
  `completion_date` date DEFAULT NULL,
  `status` enum('pending','completed','missed') DEFAULT 'pending',
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `deliverables` text DEFAULT NULL,
  `completion_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `project_milestones`
--

INSERT INTO `project_milestones` (`id`, `project_id`, `milestone_name`, `description`, `due_date`, `completion_date`, `status`, `priority`, `deliverables`, `completion_notes`, `created_at`) VALUES
(2, 6, 'ada', 'waw', '2025-10-28', '2025-10-09', 'completed', 'critical', '21', 'wa', '2025-10-09 04:36:26');

-- --------------------------------------------------------

--
-- Table structure for table `project_resources`
--

CREATE TABLE `project_resources` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `resource_type` enum('inventory_item','human','equipment','service') NOT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `resource_name` varchar(255) NOT NULL,
  `quantity_required` decimal(15,4) DEFAULT 1.0000,
  `quantity_allocated` decimal(15,4) DEFAULT 0.0000,
  `quantity_used` decimal(15,4) DEFAULT 0.0000,
  `unit_cost` decimal(15,4) DEFAULT 0.0000,
  `total_cost` decimal(15,2) GENERATED ALWAYS AS (`quantity_used` * `unit_cost`) STORED,
  `allocation_date` date DEFAULT NULL,
  `status` enum('planned','allocated','in_use','completed','returned') DEFAULT 'planned',
  `notes` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_tasks`
--

CREATE TABLE `project_tasks` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `task_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `start_date` date NOT NULL,
  `due_date` date NOT NULL,
  `completion_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed','cancelled','overdue') DEFAULT 'pending',
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `progress_percentage` decimal(5,2) DEFAULT 0.00,
  `estimated_hours` decimal(8,2) DEFAULT 0.00,
  `actual_hours` decimal(8,2) DEFAULT 0.00,
  `dependencies` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `project_tasks`
--

INSERT INTO `project_tasks` (`id`, `project_id`, `task_name`, `description`, `assigned_to`, `start_date`, `due_date`, `completion_date`, `status`, `priority`, `progress_percentage`, `estimated_hours`, `actual_hours`, `dependencies`, `notes`, `created_at`, `updated_at`) VALUES
(2, 6, 'wow', 'naks', NULL, '2025-10-09', '2025-10-10', NULL, 'in_progress', 'low', 0.00, 3.00, 0.00, NULL, 'wow', '2025-10-09 04:34:25', '2025-10-09 04:34:25');

-- --------------------------------------------------------

--
-- Table structure for table `project_timeline`
--

CREATE TABLE `project_timeline` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `event_name` varchar(255) NOT NULL,
  `event_type` enum('milestone','task','delivery','meeting','other') DEFAULT 'other',
  `event_date` date NOT NULL,
  `status` enum('scheduled','in_progress','completed','delayed','cancelled') DEFAULT 'scheduled',
  `description` text DEFAULT NULL,
  `responsible_person` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL,
  `po_number` varchar(100) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `order_date` date NOT NULL,
  `expected_delivery` date DEFAULT NULL,
  `status` enum('draft','sent','confirmed','partial','completed','cancelled') NOT NULL DEFAULT 'draft',
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `shipping_cost` decimal(10,2) DEFAULT 0.00,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` varchar(255) NOT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity_ordered` int(11) NOT NULL,
  `quantity_received` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_requests`
--

CREATE TABLE `purchase_requests` (
  `id` int(11) NOT NULL,
  `request_number` varchar(50) NOT NULL,
  `requester_id` int(11) NOT NULL,
  `department` varchar(100) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `request_date` datetime NOT NULL,
  `status` enum('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_request_items`
--

CREATE TABLE `purchase_request_items` (
  `id` int(11) NOT NULL,
  `pr_id` int(11) NOT NULL,
  `supplier_item_id` int(11) DEFAULT NULL,
  `item_description` varchar(500) NOT NULL,
  `quantity` decimal(15,4) NOT NULL,
  `unit_of_measure` varchar(20) DEFAULT 'pcs',
  `estimated_unit_price` decimal(15,4) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quotation_items`
--

CREATE TABLE `quotation_items` (
  `id` int(11) NOT NULL,
  `quotation_id` int(11) NOT NULL,
  `rfq_item_id` int(11) NOT NULL,
  `quantity` decimal(15,4) NOT NULL,
  `unit_price` decimal(15,4) NOT NULL,
  `discount_percent` decimal(5,2) DEFAULT 0.00,
  `line_total` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `unit_price` * (1 - `discount_percent` / 100)) STORED,
  `delivery_date` date DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `specifications` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `report_templates`
--

CREATE TABLE `report_templates` (
  `id` int(11) NOT NULL,
  `template_name` varchar(255) NOT NULL,
  `report_type` enum('supplier_performance','cost_analysis','purchase_summary','rfq_analysis','custom') NOT NULL,
  `description` text DEFAULT NULL,
  `sql_query` text DEFAULT NULL,
  `parameters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parameters`)),
  `created_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rfq_items`
--

CREATE TABLE `rfq_items` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `item_description` varchar(500) NOT NULL,
  `quantity` decimal(15,4) NOT NULL,
  `unit_of_measure` varchar(20) DEFAULT 'pcs',
  `specifications` text DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rfq_requests`
--

CREATE TABLE `rfq_requests` (
  `id` int(11) NOT NULL,
  `rfq_number` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `request_date` date NOT NULL,
  `response_deadline` date NOT NULL,
  `delivery_required_date` date DEFAULT NULL,
  `status` enum('draft','sent','under_review','completed','cancelled') DEFAULT 'draft',
  `terms_conditions` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rfq_suppliers`
--

CREATE TABLE `rfq_suppliers` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `sent_date` date DEFAULT NULL,
  `response_received` tinyint(1) DEFAULT 0,
  `response_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `movement_type` enum('IN','OUT','TRANSFER','ADJUSTMENT') NOT NULL,
  `quantity` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `from_location` varchar(255) DEFAULT NULL,
  `to_location` varchar(255) DEFAULT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `total_cost` decimal(10,2) DEFAULT NULL,
  `performed_by` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `stock_movements`
--

INSERT INTO `stock_movements` (`id`, `item_id`, `movement_type`, `quantity`, `reason`, `reference_number`, `from_location`, `to_location`, `unit_cost`, `total_cost`, `performed_by`, `notes`, `created_at`) VALUES
(1, 1, 'OUT', 3, 'Stock In - Purchase', NULL, NULL, NULL, NULL, NULL, 'dominicagravante21@gmail.com', NULL, '2025-09-22 04:55:42'),
(2, 1, 'OUT', 1, 'Asset Check-Out', NULL, 'Wow', NULL, NULL, NULL, 'Cudiamatcute21@gmail.com', NULL, '2025-10-07 05:30:07');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `supplier_code` varchar(50) NOT NULL,
  `supplier_name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `payment_terms` varchar(100) DEFAULT NULL,
  `tax_id` varchar(50) DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT NULL,
  `status` enum('active','inactive','blacklisted') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `supplier_code`, `supplier_name`, `contact_person`, `email`, `phone`, `address`, `payment_terms`, `tax_id`, `rating`, `status`, `created_at`, `updated_at`) VALUES
(1, 'SUP0001', 'Wawas', 'Dominic Agravante', 'dominicagravante21@gmail.com', '639859165317', 'B31 L1 Bougainvilla St. Maligaya Park Subd. Q.C', 'Cash on Delivery', 'TAX-12345678', 5.00, 'active', '2025-09-18 06:16:37', '2025-09-18 07:37:22'),
(2, 'SUP0002', 'Love You', 'Abakada', 'iloveyou21@gmail.com', '09232342232', 'Harvard College Street', 'Net 15', 'TAX-202500000002', 3.00, 'active', '2025-09-26 13:28:56', '2025-10-07 05:12:07');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_evaluations`
--

CREATE TABLE `supplier_evaluations` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `evaluation_period_start` date NOT NULL,
  `evaluation_period_end` date NOT NULL,
  `quality_rating` decimal(3,2) DEFAULT 0.00,
  `delivery_rating` decimal(3,2) DEFAULT 0.00,
  `service_rating` decimal(3,2) DEFAULT 0.00,
  `price_rating` decimal(3,2) DEFAULT 0.00,
  `overall_rating` decimal(3,2) DEFAULT 0.00,
  `total_orders` int(11) DEFAULT 0,
  `on_time_deliveries` int(11) DEFAULT 0,
  `total_value` decimal(15,2) DEFAULT 0.00,
  `quality_issues` int(11) DEFAULT 0,
  `comments` text DEFAULT NULL,
  `evaluated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplier_evaluations`
--

INSERT INTO `supplier_evaluations` (`id`, `supplier_id`, `evaluation_period_start`, `evaluation_period_end`, `quality_rating`, `delivery_rating`, `service_rating`, `price_rating`, `overall_rating`, `total_orders`, `on_time_deliveries`, `total_value`, `quality_issues`, `comments`, `evaluated_by`, `created_at`) VALUES
(2, 2, '2025-10-07', '2025-10-22', 3.00, 3.00, 3.00, 3.00, 3.00, 1, 1, 500.00, 10, '', 7, '2025-10-07 05:12:07');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_items`
--

CREATE TABLE `supplier_items` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `supplier_item_code` varchar(100) DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `lead_time_days` int(11) DEFAULT 7,
  `minimum_order_quantity` int(11) DEFAULT 1,
  `is_available` tinyint(1) DEFAULT 1,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_quotations`
--

CREATE TABLE `supplier_quotations` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `quotation_number` varchar(50) DEFAULT NULL,
  `quotation_date` date NOT NULL,
  `validity_date` date DEFAULT NULL,
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `currency` varchar(3) DEFAULT 'PHP',
  `payment_terms` varchar(100) DEFAULT NULL,
  `delivery_terms` varchar(255) DEFAULT NULL,
  `status` enum('received','under_review','accepted','rejected') DEFAULT 'received',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('admin','employee','manager','staff') DEFAULT 'staff',
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `application_status` enum('pending','approved','rejected') DEFAULT 'approved',
  `resume_path` varchar(500) DEFAULT NULL,
  `resume_filename` varchar(255) DEFAULT NULL,
  `application_date` timestamp NULL DEFAULT NULL,
  `reviewed_by` varchar(255) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `first_name`, `last_name`, `phone`, `role`, `status`, `application_status`, `resume_path`, `resume_filename`, `application_date`, `reviewed_by`, `reviewed_at`, `rejection_reason`, `created_at`, `updated_at`, `last_login`) VALUES
(5, 'admin@loogistics.com', 'admin1', 'System', 'Administrator', '+639123456789', 'admin', 'active', 'approved', NULL, NULL, NULL, NULL, NULL, NULL, '2025-09-21 04:45:42', '2026-03-17 18:47:35', '2026-03-17 18:47:35'),
(7, 'Cudiamatcute21@gmail.com', 'cudiamatcutie123', 'Josh', 'Cudiamat', NULL, 'staff', 'active', 'approved', NULL, NULL, NULL, NULL, NULL, NULL, '2025-09-26 14:04:10', '2025-10-09 03:46:55', '2025-10-09 03:46:55'),
(8, 'dominicagravante21@gmail.com', 'password123', 'Dominic', 'Agravante', '639859165317', 'staff', 'active', 'approved', 'uploads/resumes/68f3210349338_1760764163.docx', 'Research-Paper-Template.docx', '2025-10-18 05:09:23', 'System Administrator', '2025-10-18 05:23:27', NULL, '2025-10-18 05:09:23', '2026-03-17 18:45:47', '2026-03-17 18:45:47');

-- --------------------------------------------------------

--
-- Structure for view `asset_summary_view`
--
DROP TABLE IF EXISTS `asset_summary_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `asset_summary_view`  AS SELECT `a`.`id` AS `id`, `a`.`asset_code` AS `asset_code`, `a`.`asset_name` AS `asset_name`, `a`.`category` AS `category`, `a`.`status` AS `status`, `a`.`condition_rating` AS `condition_rating`, `a`.`location` AS `location`, `a`.`assigned_to` AS `assigned_to`, `a`.`purchase_date` AS `purchase_date`, `a`.`purchase_cost` AS `purchase_cost`, `a`.`current_value` AS `current_value`, `a`.`depreciation_method` AS `depreciation_method`, `a`.`useful_life_years` AS `useful_life_years`, `a`.`next_maintenance` AS `next_maintenance`, (to_days(curdate()) - to_days(`a`.`purchase_date`)) / 365.25 AS `age_years`, greatest(0,`a`.`useful_life_years` - (to_days(curdate()) - to_days(`a`.`purchase_date`)) / 365.25) AS `remaining_life_years`, `a`.`purchase_cost`- `a`.`current_value` AS `total_depreciation`, CASE WHEN `a`.`purchase_cost` > 0 THEN (`a`.`purchase_cost` - `a`.`current_value`) / `a`.`purchase_cost` * 100 ELSE 0 END AS `depreciation_percentage`, (select count(0) from `maintenance_schedule` `ms` where `ms`.`asset_id` = `a`.`id` and `ms`.`status` = 'scheduled') AS `scheduled_maintenance_count`, (select count(0) from `maintenance_schedule` `ms` where `ms`.`asset_id` = `a`.`id` and `ms`.`status` = 'overdue') AS `overdue_maintenance_count`, (select count(0) from `asset_movements` `am` where `am`.`asset_id` = `a`.`id`) AS `total_movements` FROM `assets` AS `a` ;

-- --------------------------------------------------------

--
-- Structure for view `checkinout_summary_view`
--
DROP TABLE IF EXISTS `checkinout_summary_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `checkinout_summary_view`  AS SELECT `ch`.`id` AS `id`, `ch`.`asset_id` AS `asset_id`, `ch`.`action` AS `action`, `ch`.`action_date` AS `action_date`, `ch`.`assigned_to` AS `assigned_to`, `ch`.`previous_assigned_to` AS `previous_assigned_to`, `ch`.`location` AS `location`, `ch`.`previous_location` AS `previous_location`, `ch`.`notes` AS `notes`, `ch`.`condition_before` AS `condition_before`, `ch`.`condition_after` AS `condition_after`, `ch`.`performed_by` AS `performed_by`, `ch`.`created_at` AS `created_at`, `a`.`asset_code` AS `asset_code`, `a`.`asset_name` AS `asset_name`, `a`.`category` AS `category`, `a`.`location` AS `current_location`, `a`.`assigned_to` AS `current_assigned_to`, `a`.`status` AS `current_status`, `a`.`condition_rating` AS `current_condition` FROM (`check_in_out_history` `ch` left join `assets` `a` on(`ch`.`asset_id` = `a`.`id`)) ORDER BY `ch`.`action_date` DESC, `ch`.`created_at` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `maintenance_dashboard_view`
--
DROP TABLE IF EXISTS `maintenance_dashboard_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `maintenance_dashboard_view`  AS SELECT `ms`.`id` AS `id`, `ms`.`asset_id` AS `asset_id`, `ms`.`maintenance_title` AS `maintenance_title`, `ms`.`maintenance_type` AS `maintenance_type`, `ms`.`scheduled_date` AS `scheduled_date`, `ms`.`status` AS `status`, `ms`.`priority` AS `priority`, `ms`.`estimated_cost` AS `estimated_cost`, `ms`.`actual_cost` AS `actual_cost`, `ms`.`assigned_technician` AS `assigned_technician`, `a`.`asset_code` AS `asset_code`, `a`.`asset_name` AS `asset_name`, `a`.`category` AS `category`, `a`.`location` AS `location`, to_days(`ms`.`scheduled_date`) - to_days(curdate()) AS `days_until_due`, CASE WHEN `ms`.`status` = 'overdue' THEN to_days(curdate()) - to_days(`ms`.`scheduled_date`) ELSE 0 END AS `days_overdue` FROM (`maintenance_schedule` `ms` left join `assets` `a` on(`ms`.`asset_id` = `a`.`id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `application_documents`
--
ALTER TABLE `application_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `asset_code` (`asset_code`),
  ADD KEY `fk_assets_supplier_safe` (`supplier_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_next_maintenance` (`next_maintenance`),
  ADD KEY `idx_assets_status_category` (`status`,`category`),
  ADD KEY `idx_status_purchase` (`status`,`purchase_cost`,`useful_life_years`);

--
-- Indexes for table `asset_movements`
--
ALTER TABLE `asset_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `asset_id` (`asset_id`),
  ADD KEY `idx_movement_date` (`movement_date`),
  ADD KEY `idx_asset_status` (`asset_id`,`movement_type`),
  ADD KEY `idx_movements_type_date` (`movement_type`,`movement_date`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_table_record` (`table_name`,`record_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_performed_by` (`performed_by`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `check_in_out_history`
--
ALTER TABLE `check_in_out_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_action_date` (`action_date`),
  ADD KEY `idx_asset_id` (`asset_id`),
  ADD KEY `idx_asset_action_date` (`asset_id`,`action_date`),
  ADD KEY `idx_performed_by` (`performed_by`),
  ADD KEY `idx_asset_action` (`asset_id`,`action`),
  ADD KEY `idx_action_date_desc` (`action_date`);

--
-- Indexes for table `compliance_requirements`
--
ALTER TABLE `compliance_requirements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `requirement_name` (`requirement_name`);

--
-- Indexes for table `delivery_status`
--
ALTER TABLE `delivery_status`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `department_coordination`
--
ALTER TABLE `department_coordination`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `depreciation_calculations`
--
ALTER TABLE `depreciation_calculations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_asset_calc_date` (`asset_id`,`calculation_date`),
  ADD KEY `idx_calculation_date` (`calculation_date`),
  ADD KEY `idx_asset_calculation_date` (`asset_id`,`calculation_date`);

--
-- Indexes for table `depreciation_history`
--
ALTER TABLE `depreciation_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `asset_id` (`asset_id`),
  ADD KEY `idx_calculation_date` (`calculation_date`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `document_code` (`document_code`),
  ADD KEY `document_type_id` (`document_type_id`),
  ADD KEY `status` (`status`),
  ADD KEY `priority` (`priority`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `related_project_id` (`related_project_id`),
  ADD KEY `related_po_id` (`related_po_id`),
  ADD KEY `related_supplier_id` (`related_supplier_id`);

--
-- Indexes for table `document_access_logs`
--
ALTER TABLE `document_access_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `accessed_by` (`accessed_by`),
  ADD KEY `access_type` (`access_type`),
  ADD KEY `accessed_at` (`accessed_at`);

--
-- Indexes for table `document_compliance`
--
ALTER TABLE `document_compliance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_document_requirement` (`document_id`,`requirement_id`),
  ADD KEY `requirement_id` (`requirement_id`),
  ADD KEY `compliance_status` (`compliance_status`),
  ADD KEY `due_date` (`due_date`),
  ADD KEY `evidence_document_id` (`evidence_document_id`);

--
-- Indexes for table `document_notifications`
--
ALTER TABLE `document_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `notification_type` (`notification_type`),
  ADD KEY `recipient` (`recipient`),
  ADD KEY `scheduled_date` (`scheduled_date`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `document_relationships`
--
ALTER TABLE `document_relationships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_relationship` (`parent_document_id`,`child_document_id`,`relationship_type`),
  ADD KEY `child_document_id` (`child_document_id`),
  ADD KEY `relationship_type` (`relationship_type`);

--
-- Indexes for table `document_types`
--
ALTER TABLE `document_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `type_name` (`type_name`);

--
-- Indexes for table `document_versions`
--
ALTER TABLE `document_versions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `version_number` (`version_number`);

--
-- Indexes for table `document_workflows`
--
ALTER TABLE `document_workflows`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `goods_receipts`
--
ALTER TABLE `goods_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `po_id` (`po_id`);

--
-- Indexes for table `goods_receipt_items`
--
ALTER TABLE `goods_receipt_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `receipt_id` (`receipt_id`),
  ADD KEY `po_item_id` (`po_item_id`);

--
-- Indexes for table `inventory_alerts`
--
ALTER TABLE `inventory_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_inventory_alerts_item` (`item_id`),
  ADD KEY `idx_alert_type` (`alert_type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_priority` (`priority`);

--
-- Indexes for table `inventory_edit_history`
--
ALTER TABLE `inventory_edit_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_edit_history_item` (`item_id`),
  ADD KEY `idx_edited_at` (`edited_at`),
  ADD KEY `idx_edited_by` (`edited_by`);

--
-- Indexes for table `inventory_items`
--
ALTER TABLE `inventory_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `item_code` (`item_code`),
  ADD KEY `idx_item_code` (`item_code`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_location` (`location`),
  ADD KEY `idx_quantity` (`quantity`),
  ADD KEY `idx_reorder_level` (`reorder_level`),
  ADD KEY `idx_last_updated` (`last_updated`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_last_edited_by` (`last_edited_by`);

--
-- Indexes for table `inventory_locations`
--
ALTER TABLE `inventory_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `location_code` (`location_code`),
  ADD KEY `idx_location_code` (`location_code`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `item_code` (`item_code`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `item_categories`
--
ALTER TABLE `item_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_category_id` (`parent_category_id`);

--
-- Indexes for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scheduled_date` (`scheduled_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_asset_id` (`asset_id`),
  ADD KEY `idx_maintenance_status_date` (`status`,`scheduled_date`);

--
-- Indexes for table `maintenance_schedules`
--
ALTER TABLE `maintenance_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `asset_id` (`asset_id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `project_code` (`project_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_priority` (`priority`),
  ADD KEY `idx_start_date` (`start_date`);

--
-- Indexes for table `project_milestones`
--
ALTER TABLE `project_milestones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `idx_due_date` (`due_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `project_resources`
--
ALTER TABLE `project_resources`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `idx_resource_type` (`resource_type`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `project_tasks`
--
ALTER TABLE `project_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_due_date` (`due_date`);

--
-- Indexes for table `project_timeline`
--
ALTER TABLE `project_timeline`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `po_number` (`po_number`),
  ADD KEY `fk_purchase_orders_supplier` (`supplier_id`),
  ADD KEY `idx_po_number` (`po_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_order_date` (`order_date`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_po_items_po` (`po_id`),
  ADD KEY `fk_po_items_item` (`item_id`);

--
-- Indexes for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `request_number` (`request_number`),
  ADD KEY `fk_pr_supplier` (`supplier_id`);

--
-- Indexes for table `purchase_request_items`
--
ALTER TABLE `purchase_request_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pr_id` (`pr_id`),
  ADD KEY `fk_pr_items_supplier_item` (`supplier_item_id`);

--
-- Indexes for table `quotation_items`
--
ALTER TABLE `quotation_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quotation_id` (`quotation_id`),
  ADD KEY `rfq_item_id` (`rfq_item_id`);

--
-- Indexes for table `report_templates`
--
ALTER TABLE `report_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rfq_items`
--
ALTER TABLE `rfq_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rfq_id` (`rfq_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `rfq_requests`
--
ALTER TABLE `rfq_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `rfq_number` (`rfq_number`);

--
-- Indexes for table `rfq_suppliers`
--
ALTER TABLE `rfq_suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_rfq_supplier` (`rfq_id`,`supplier_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_stock_movements_item` (`item_id`),
  ADD KEY `idx_movement_type` (`movement_type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_performed_by` (`performed_by`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_code` (`supplier_code`),
  ADD KEY `idx_supplier_code` (`supplier_code`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `supplier_evaluations`
--
ALTER TABLE `supplier_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `supplier_items`
--
ALTER TABLE `supplier_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_supplier_item` (`supplier_id`,`item_id`),
  ADD KEY `fk_supplier_items_supplier` (`supplier_id`),
  ADD KEY `fk_supplier_items_item` (`item_id`);

--
-- Indexes for table `supplier_quotations`
--
ALTER TABLE `supplier_quotations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rfq_id` (`rfq_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `application_documents`
--
ALTER TABLE `application_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assets`
--
ALTER TABLE `assets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `asset_movements`
--
ALTER TABLE `asset_movements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `check_in_out_history`
--
ALTER TABLE `check_in_out_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `compliance_requirements`
--
ALTER TABLE `compliance_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `delivery_status`
--
ALTER TABLE `delivery_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `department_coordination`
--
ALTER TABLE `department_coordination`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `depreciation_calculations`
--
ALTER TABLE `depreciation_calculations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `depreciation_history`
--
ALTER TABLE `depreciation_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `document_access_logs`
--
ALTER TABLE `document_access_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `document_compliance`
--
ALTER TABLE `document_compliance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `document_notifications`
--
ALTER TABLE `document_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_relationships`
--
ALTER TABLE `document_relationships`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_types`
--
ALTER TABLE `document_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `document_versions`
--
ALTER TABLE `document_versions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_workflows`
--
ALTER TABLE `document_workflows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `goods_receipts`
--
ALTER TABLE `goods_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `goods_receipt_items`
--
ALTER TABLE `goods_receipt_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_alerts`
--
ALTER TABLE `inventory_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_edit_history`
--
ALTER TABLE `inventory_edit_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_items`
--
ALTER TABLE `inventory_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `inventory_locations`
--
ALTER TABLE `inventory_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `item_categories`
--
ALTER TABLE `item_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `maintenance_schedules`
--
ALTER TABLE `maintenance_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `project_milestones`
--
ALTER TABLE `project_milestones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `project_resources`
--
ALTER TABLE `project_resources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `project_tasks`
--
ALTER TABLE `project_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `project_timeline`
--
ALTER TABLE `project_timeline`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_request_items`
--
ALTER TABLE `purchase_request_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotation_items`
--
ALTER TABLE `quotation_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `report_templates`
--
ALTER TABLE `report_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rfq_items`
--
ALTER TABLE `rfq_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rfq_requests`
--
ALTER TABLE `rfq_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rfq_suppliers`
--
ALTER TABLE `rfq_suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `supplier_evaluations`
--
ALTER TABLE `supplier_evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `supplier_items`
--
ALTER TABLE `supplier_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_quotations`
--
ALTER TABLE `supplier_quotations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `application_documents`
--
ALTER TABLE `application_documents`
  ADD CONSTRAINT `fk_application_docs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `assets`
--
ALTER TABLE `assets`
  ADD CONSTRAINT `assets_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_assets_supplier_safe` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `asset_movements`
--
ALTER TABLE `asset_movements`
  ADD CONSTRAINT `fk_asset_movements_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `check_in_out_history`
--
ALTER TABLE `check_in_out_history`
  ADD CONSTRAINT `check_in_out_history_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_status`
--
ALTER TABLE `delivery_status`
  ADD CONSTRAINT `delivery_status_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `department_coordination`
--
ALTER TABLE `department_coordination`
  ADD CONSTRAINT `department_coordination_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `depreciation_calculations`
--
ALTER TABLE `depreciation_calculations`
  ADD CONSTRAINT `fk_depreciation_calc_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `depreciation_history`
--
ALTER TABLE `depreciation_history`
  ADD CONSTRAINT `fk_depreciation_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_documents_po` FOREIGN KEY (`related_po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_documents_project` FOREIGN KEY (`related_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_documents_supplier` FOREIGN KEY (`related_supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_documents_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `document_access_logs`
--
ALTER TABLE `document_access_logs`
  ADD CONSTRAINT `fk_access_logs_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `document_compliance`
--
ALTER TABLE `document_compliance`
  ADD CONSTRAINT `fk_compliance_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_compliance_evidence` FOREIGN KEY (`evidence_document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_compliance_requirement` FOREIGN KEY (`requirement_id`) REFERENCES `compliance_requirements` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `document_notifications`
--
ALTER TABLE `document_notifications`
  ADD CONSTRAINT `fk_notifications_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `document_relationships`
--
ALTER TABLE `document_relationships`
  ADD CONSTRAINT `fk_relationship_child` FOREIGN KEY (`child_document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_relationship_parent` FOREIGN KEY (`parent_document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `document_versions`
--
ALTER TABLE `document_versions`
  ADD CONSTRAINT `fk_versions_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `document_workflows`
--
ALTER TABLE `document_workflows`
  ADD CONSTRAINT `fk_workflows_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `goods_receipts`
--
ALTER TABLE `goods_receipts`
  ADD CONSTRAINT `goods_receipts_ibfk_1` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `goods_receipt_items`
--
ALTER TABLE `goods_receipt_items`
  ADD CONSTRAINT `goods_receipt_items_ibfk_1` FOREIGN KEY (`receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `goods_receipt_items_ibfk_2` FOREIGN KEY (`po_item_id`) REFERENCES `purchase_order_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_alerts`
--
ALTER TABLE `inventory_alerts`
  ADD CONSTRAINT `fk_inventory_alerts_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `inventory_edit_history`
--
ALTER TABLE `inventory_edit_history`
  ADD CONSTRAINT `fk_edit_history_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `items_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `item_categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `item_categories`
--
ALTER TABLE `item_categories`
  ADD CONSTRAINT `item_categories_ibfk_1` FOREIGN KEY (`parent_category_id`) REFERENCES `item_categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  ADD CONSTRAINT `maintenance_schedule_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `project_milestones`
--
ALTER TABLE `project_milestones`
  ADD CONSTRAINT `fk_project_milestones_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `project_resources`
--
ALTER TABLE `project_resources`
  ADD CONSTRAINT `fk_project_resources_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `project_tasks`
--
ALTER TABLE `project_tasks`
  ADD CONSTRAINT `fk_project_tasks_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `project_timeline`
--
ALTER TABLE `project_timeline`
  ADD CONSTRAINT `project_timeline_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_purchase_orders_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `fk_po_items_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_po_items_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD CONSTRAINT `fk_pr_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `purchase_request_items`
--
ALTER TABLE `purchase_request_items`
  ADD CONSTRAINT `fk_pr_items` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pr_items_supplier_item` FOREIGN KEY (`supplier_item_id`) REFERENCES `supplier_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `quotation_items`
--
ALTER TABLE `quotation_items`
  ADD CONSTRAINT `quotation_items_ibfk_1` FOREIGN KEY (`quotation_id`) REFERENCES `supplier_quotations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `quotation_items_ibfk_2` FOREIGN KEY (`rfq_item_id`) REFERENCES `rfq_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rfq_items`
--
ALTER TABLE `rfq_items`
  ADD CONSTRAINT `rfq_items_ibfk_1` FOREIGN KEY (`rfq_id`) REFERENCES `rfq_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `rfq_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `rfq_suppliers`
--
ALTER TABLE `rfq_suppliers`
  ADD CONSTRAINT `rfq_suppliers_ibfk_1` FOREIGN KEY (`rfq_id`) REFERENCES `rfq_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `rfq_suppliers_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD CONSTRAINT `fk_stock_movements_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `supplier_evaluations`
--
ALTER TABLE `supplier_evaluations`
  ADD CONSTRAINT `supplier_evaluations_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_items`
--
ALTER TABLE `supplier_items`
  ADD CONSTRAINT `fk_supplier_items_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_supplier_items_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `supplier_quotations`
--
ALTER TABLE `supplier_quotations`
  ADD CONSTRAINT `supplier_quotations_ibfk_1` FOREIGN KEY (`rfq_id`) REFERENCES `rfq_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supplier_quotations_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`root`@`localhost` EVENT `daily_maintenance_check` ON SCHEDULE EVERY 1 DAY STARTS '2025-10-10 00:00:00' ON COMPLETION NOT PRESERVE ENABLE DO CALL `update_overdue_maintenance`()$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
