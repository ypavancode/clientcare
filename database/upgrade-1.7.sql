-- =====================================================================
-- Upgrade 1.6 -> 1.7: Client & Website Project Management module
--   * departments (industries) and website types – admin managed
--   * website_projects (new website projects + existing websites) linked to clients and monitored websites
--   * project_status_history (full lifecycle audit)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_name` (`name`),
  KEY `idx_departments_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `website_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` INT UNSIGNED DEFAULT NULL COMMENT 'Optional: type belongs to this department; NULL = any department',
  `name` VARCHAR(100) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_types_department` (`department_id`, `status`, `sort_order`),
  KEY `idx_types_name` (`name`),
  CONSTRAINT `fk_types_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `website_projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `website_id` INT UNSIGNED DEFAULT NULL COMMENT 'Linked monitoring website record (shared with the monitoring module)',
  `project_name` VARCHAR(150) NOT NULL,
  `website_name` VARCHAR(150) DEFAULT NULL,
  `website_url` VARCHAR(255) DEFAULT NULL,
  `project_kind` ENUM('new','existing') NOT NULL DEFAULT 'new',
  `status` ENUM('design','development','testing','client_review','changes_required','ready_for_launch','live','on_hold','cancelled') NOT NULL DEFAULT 'design',
  `department_id` INT UNSIGNED DEFAULT NULL,
  `website_type_id` INT UNSIGNED DEFAULT NULL,
  `technology` VARCHAR(40) DEFAULT NULL,
  `start_date` DATE DEFAULT NULL,
  `expected_launch_date` DATE DEFAULT NULL,
  `actual_launch_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `assigned_user_id` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_projects_client` (`client_id`),
  KEY `idx_projects_website` (`website_id`),
  KEY `idx_projects_status` (`status`, `project_name`),
  KEY `idx_projects_kind` (`project_kind`),
  KEY `idx_projects_department` (`department_id`),
  KEY `idx_projects_type` (`website_type_id`),
  KEY `idx_projects_assigned` (`assigned_user_id`),
  KEY `idx_projects_start` (`start_date`),
  KEY `idx_projects_launch` (`expected_launch_date`),
  KEY `idx_projects_name` (`project_name`),
  FULLTEXT KEY `ft_projects` (`project_name`, `website_name`, `website_url`),
  CONSTRAINT `fk_projects_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_projects_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_projects_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_projects_type` FOREIGN KEY (`website_type_id`) REFERENCES `website_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_projects_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_status_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `from_status` VARCHAR(30) DEFAULT NULL,
  `to_status` VARCHAR(30) NOT NULL,
  `note` VARCHAR(500) DEFAULT NULL,
  `changed_by` INT UNSIGNED DEFAULT NULL,
  `changed_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_history_project` (`project_id`, `changed_at`),
  CONSTRAINT `fk_history_project` FOREIGN KEY (`project_id`) REFERENCES `website_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default departments (editable in Website Projects → Departments & Types)
INSERT IGNORE INTO `departments` (`name`, `sort_order`) VALUES
('Education', 10), ('Hospital', 20), ('Healthcare', 30), ('Real Estate', 40), ('Finance', 50), ('Jewellery', 60), ('Construction', 70),
('Hospitality', 80), ('Restaurant', 90), ('E-commerce', 100), ('IT', 110), ('Manufacturing', 120), ('Corporate', 130), ('Travel', 140),
('Tourism', 150), ('Legal', 160), ('Automobile', 170), ('NGO', 180), ('Government', 190), ('Other', 999);

-- Default website types (grouped by department where it makes sense)
INSERT IGNORE INTO `website_types` (`department_id`, `name`, `sort_order`)
SELECT d.id, t.name, t.so FROM (
  SELECT 'Education' AS dept, 'School' AS name, 10 AS so UNION ALL SELECT 'Education', 'College', 20 UNION ALL SELECT 'Education', 'University', 30 UNION ALL SELECT 'Education', 'Coaching Institute', 40 UNION ALL SELECT 'Education', 'Educational Platform', 50
  UNION ALL SELECT 'Healthcare', 'Hospital', 10 UNION ALL SELECT 'Healthcare', 'Clinic', 20 UNION ALL SELECT 'Healthcare', 'Diagnostic Center', 30 UNION ALL SELECT 'Healthcare', 'Pharmacy', 40
  UNION ALL SELECT 'Hospital', 'Multi-speciality Hospital', 10 UNION ALL SELECT 'Hospital', 'Speciality Hospital', 20
  UNION ALL SELECT 'Real Estate', 'Villas', 10 UNION ALL SELECT 'Real Estate', 'Apartments', 20 UNION ALL SELECT 'Real Estate', 'Commercial', 30 UNION ALL SELECT 'Real Estate', 'Property Listing', 40 UNION ALL SELECT 'Real Estate', 'Villa Project', 50
  UNION ALL SELECT 'Construction', 'Construction Company', 10 UNION ALL SELECT 'Construction', 'Builders & Developers', 20
  UNION ALL SELECT 'E-commerce', 'Fashion', 10 UNION ALL SELECT 'E-commerce', 'Jewellery Store', 20 UNION ALL SELECT 'E-commerce', 'Electronics', 30 UNION ALL SELECT 'E-commerce', 'Grocery', 40 UNION ALL SELECT 'E-commerce', 'Marketplace', 50
  UNION ALL SELECT 'Jewellery', 'Jewellery Showroom', 10 UNION ALL SELECT 'Jewellery', 'Jewellery Brand', 20
  UNION ALL SELECT 'Hospitality', 'Hotel', 10 UNION ALL SELECT 'Hospitality', 'Resort', 20
  UNION ALL SELECT 'Restaurant', 'Restaurant', 10 UNION ALL SELECT 'Restaurant', 'Cafe', 20 UNION ALL SELECT 'Restaurant', 'Cloud Kitchen', 30
  UNION ALL SELECT 'Corporate', 'Corporate Website', 10 UNION ALL SELECT 'Corporate', 'Landing Page', 20 UNION ALL SELECT 'Corporate', 'Portfolio', 30
  UNION ALL SELECT 'IT', 'Software Company', 10 UNION ALL SELECT 'IT', 'SaaS Product', 20
  UNION ALL SELECT 'Finance', 'Bank / NBFC', 10 UNION ALL SELECT 'Finance', 'Financial Advisor', 20
  UNION ALL SELECT 'Travel', 'Travel Agency', 10 UNION ALL SELECT 'Tourism', 'Tourism Portal', 10
  UNION ALL SELECT 'Automobile', 'Car Dealer', 10 UNION ALL SELECT 'Automobile', 'Auto Service', 20
  UNION ALL SELECT 'Manufacturing', 'Manufacturer', 10 UNION ALL SELECT 'Legal', 'Law Firm', 10 UNION ALL SELECT 'NGO', 'NGO / Trust', 10 UNION ALL SELECT 'Government', 'Government Portal', 10
  UNION ALL SELECT 'Other', 'Other', 999
) t JOIN departments d ON d.name = t.dept;
