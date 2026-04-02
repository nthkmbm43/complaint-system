-- ============================================================
-- Online Complaint Management System - Database Schema
-- Version: 1.0
-- Engine: MariaDB / MySQL
-- Charset: utf8mb4
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ============================================================
-- สร้างฐานข้อมูล (แก้ชื่อตามต้องการ)
-- ============================================================
CREATE DATABASE IF NOT EXISTS `complaint_system`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `complaint_system`;

-- ============================================================
-- ตาราง: type (ประเภทข้อร้องเรียน)
-- ============================================================
CREATE TABLE `type` (
  `Type_id` int(2) NOT NULL AUTO_INCREMENT,
  `Type_infor` varchar(100) NOT NULL COMMENT 'ชื่อประเภทข้อร้องเรียน',
  `Type_icon` varchar(50) DEFAULT NULL COMMENT 'ไอคอน emoji',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`Type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `type` (`Type_id`, `Type_infor`, `Type_icon`) VALUES
(1, 'เรื่องการเรียนการสอน', '📚'),
(2, 'สิ่งอำนวยความสะดวก', '🏢'),
(3, 'เรื่องการเงิน', '💰'),
(4, 'บุคลากร/เจ้าหน้าที่', '👥'),
(5, 'ระบบเทคโนโลยี', '🌐'),
(6, 'การคมนาคม', '🚌'),
(7, 'บริการสุขภาพ', '🏥'),
(8, 'อื่นๆ', '📝');

-- ============================================================
-- ตาราง: organization_unit (หน่วยงาน/คณะ/สาขา)
-- ============================================================
CREATE TABLE `organization_unit` (
  `Unit_id` int(4) NOT NULL AUTO_INCREMENT,
  `Unit_name` varchar(100) NOT NULL COMMENT 'ชื่อหน่วยงาน',
  `Unit_type` varchar(20) NOT NULL COMMENT 'ประเภท: faculty, major, department',
  `Unit_icon` varchar(50) DEFAULT NULL COMMENT 'ไอคอน emoji',
  `Unit_parent_id` int(4) DEFAULT NULL COMMENT 'หน่วยงานต้นสังกัด',
  `Unit_tel` varchar(10) DEFAULT NULL,
  `Unit_email` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`Unit_id`),
  KEY `idx_unit_type` (`Unit_type`),
  KEY `idx_unit_parent` (`Unit_parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตัวอย่างข้อมูลหน่วยงาน (แก้ไขตามโครงสร้างมหาวิทยาลัยจริง)
INSERT INTO `organization_unit` (`Unit_name`, `Unit_type`, `Unit_icon`, `Unit_parent_id`) VALUES
('คณะครุศาสตร์อุตสาหกรรม', 'faculty', '🎓', NULL),
('คณะวิศวกรรมศาสตร์', 'faculty', '⚙️', NULL),
('คณะบริหารธุรกิจ', 'faculty', '💼', NULL),
('งานทะเบียนและประมวลผล', 'department', '📋', NULL),
('งานกิจการนักศึกษา', 'department', '👥', NULL),
('งานอาคารสถานที่', 'department', '🏢', NULL),
('งานเทคโนโลยีสารสนเทศ', 'department', '💻', NULL),
('งานการเงินและบัญชี', 'department', '💰', NULL),
('เทคโนโลยีธุรกิจดิจิทัล', 'major', '💻', 3);

-- ============================================================
-- ตาราง: teacher (เจ้าหน้าที่/อาจารย์)
-- Aj_per: 1=อาจารย์, 2=ผู้ดำเนินการ, 3=ผู้ดูแลระบบ
-- ============================================================
CREATE TABLE `teacher` (
  `Aj_id` int(2) NOT NULL AUTO_INCREMENT,
  `Aj_name` varchar(50) NOT NULL,
  `Aj_password` varchar(255) NOT NULL COMMENT 'ควรเก็บเป็น hash',
  `Aj_position` varchar(20) DEFAULT NULL,
  `Aj_tel` varchar(10) DEFAULT NULL,
  `Aj_email` varchar(100) DEFAULT NULL,
  `Aj_status` int(1) DEFAULT 1 COMMENT '1=ใช้งาน, 0=ระงับ',
  `Unit_id` int(4) DEFAULT NULL,
  `Aj_per` int(1) DEFAULT 1 COMMENT '1=อาจารย์, 2=ผู้ดำเนินการ, 3=ผู้ดูแลระบบ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`Aj_id`),
  KEY `idx_teacher_status` (`Aj_status`),
  KEY `idx_teacher_unit` (`Unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตัวอย่าง: บัญชีผู้ดูแลระบบ (password: admin1234 — ควรเปลี่ยนทันทีหลัง import)
INSERT INTO `teacher` (`Aj_name`, `Aj_password`, `Aj_position`, `Aj_status`, `Aj_per`) VALUES
('ผู้ดูแลระบบ', 'admin1234', 'ผู้ดูแลระบบ', 1, 3),
('อาจารย์ตัวอย่าง', 'teacher1234', 'อาจารย์', 1, 1);

-- ============================================================
-- ตาราง: student (นักศึกษา)
-- ============================================================
CREATE TABLE `student` (
  `Stu_id` varchar(13) NOT NULL COMMENT 'รหัสนักศึกษา',
  `Stu_name` varchar(50) NOT NULL,
  `Stu_password` varchar(255) NOT NULL COMMENT 'ควรเก็บเป็น hash',
  `Stu_tel` varchar(10) DEFAULT NULL,
  `Stu_email` varchar(100) DEFAULT NULL,
  `Stu_status` int(1) DEFAULT 1 COMMENT '1=ใช้งาน, 0=ระงับ',
  `Stu_suspend_reason` text DEFAULT NULL,
  `Stu_suspend_date` date DEFAULT NULL,
  `Stu_suspend_by` int(2) DEFAULT NULL,
  `Unit_id` int(4) DEFAULT NULL COMMENT 'สาขาที่ศึกษา',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`Stu_id`),
  KEY `student_suspend_by_fk` (`Stu_suspend_by`),
  KEY `idx_student_status` (`Stu_status`),
  KEY `student_unit_fk` (`Unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตัวอย่าง: บัญชีนักศึกษาทดสอบ (password: student1234 — ควรเปลี่ยนทันทีหลัง import)
INSERT INTO `student` (`Stu_id`, `Stu_name`, `Stu_password`, `Stu_tel`, `Stu_email`, `Unit_id`) VALUES
('66000000000-0', 'นักศึกษาทดสอบ', 'student1234', '0800000000', 'student@example.com', 9);

-- ============================================================
-- ตาราง: request (ข้อร้องเรียน)
-- Re_status: 0=ยื่นคำร้อง, 1=กำลังดำเนินการ, 2=รอประเมิน, 3=เสร็จสิ้น, 4=ปฏิเสธ
-- Re_level:  0=รอพิจารณา, 1=ไม่เร่งด่วน, 2=ปกติ, 3=เร่งด่วน, 4=เร่งด่วนมาก, 5=วิกฤต
-- Re_iden:   0=ระบุตัวตน, 1=ไม่ระบุตัวตน
-- ============================================================
CREATE TABLE `request` (
  `Re_id` int(15) NOT NULL AUTO_INCREMENT,
  `Re_title` varchar(100) DEFAULT NULL COMMENT 'หัวข้อร้องเรียน',
  `Re_infor` text NOT NULL COMMENT 'รายละเอียดข้อร้องเรียน',
  `Re_status` varchar(1) DEFAULT '0',
  `Re_level` varchar(1) DEFAULT '0',
  `Re_iden` int(1) DEFAULT 0 COMMENT '0=ระบุตัวตน, 1=ไม่ระบุตัวตน',
  `Re_date` date NOT NULL,
  `Aj_id` int(2) DEFAULT NULL COMMENT 'เจ้าหน้าที่ที่รับผิดชอบ',
  `Stu_id` varchar(13) DEFAULT NULL,
  `Type_id` int(2) NOT NULL,
  PRIMARY KEY (`Re_id`),
  KEY `idx_request_student` (`Stu_id`),
  KEY `idx_request_type` (`Type_id`),
  KEY `idx_request_status` (`Re_status`),
  KEY `idx_request_date` (`Re_date`),
  KEY `idx_request_level` (`Re_level`),
  KEY `idx_request_assign_to` (`Aj_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ตาราง: save_request (บันทึกผลการดำเนินงาน)
-- Sv_type: receive=รับเรื่อง, process=ดำเนินการ
-- ============================================================
CREATE TABLE `save_request` (
  `Sv_id` int(15) NOT NULL AUTO_INCREMENT,
  `Sv_infor` text NOT NULL COMMENT 'รายละเอียดการดำเนินงาน',
  `Sv_type` varchar(20) DEFAULT NULL COMMENT 'receive, process',
  `Sv_result` text DEFAULT NULL COMMENT 'ผลการดำเนินงาน',
  `Sv_date` date NOT NULL,
  `Re_id` int(15) NOT NULL,
  `Aj_id` int(2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`Sv_id`),
  KEY `Re_id` (`Re_id`),
  KEY `idx_save_request_teacher` (`Aj_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ตาราง: result_request (ผลการดำเนินงานแนบไฟล์)
-- ============================================================
CREATE TABLE `result_request` (
  `Result_id` int(15) NOT NULL AUTO_INCREMENT,
  `Result_infor` text NOT NULL,
  `Result_date` date NOT NULL,
  `Sv_id` int(15) DEFAULT NULL,
  `Re_id` int(15) NOT NULL,
  `Aj_id` int(2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`Result_id`),
  KEY `Sv_id` (`Sv_id`),
  KEY `Re_id` (`Re_id`),
  KEY `idx_result_request_teacher` (`Aj_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ตาราง: supporting_evidence (ไฟล์แนบหลักฐาน)
-- ============================================================
CREATE TABLE `supporting_evidence` (
  `Sup_id` int(15) NOT NULL AUTO_INCREMENT,
  `Sup_filename` varchar(255) NOT NULL,
  `Sup_filepath` varchar(500) NOT NULL,
  `Sup_filetype` varchar(10) NOT NULL COMMENT 'pdf, png, jpg, jpeg, doc, docx',
  `Sup_filesize` int(11) DEFAULT NULL COMMENT 'bytes',
  `Sup_upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `Sup_upload_by` varchar(13) DEFAULT NULL COMMENT 'รหัสนักศึกษา',
  `Aj_id` int(2) DEFAULT NULL COMMENT 'รหัสอาจารย์ (ถ้าอาจารย์แนบ)',
  `Re_id` int(15) NOT NULL,
  PRIMARY KEY (`Sup_id`),
  KEY `Re_id` (`Re_id`),
  KEY `supporting_evidence_upload_by_fk` (`Sup_upload_by`),
  KEY `idx_supporting_evidence_filetype` (`Sup_filetype`),
  KEY `idx_supporting_evidence_upload_date` (`Sup_upload_date`),
  KEY `idx_supporting_evidence_teacher` (`Aj_id`),
  CONSTRAINT `supporting_evidence_teacher_fk` FOREIGN KEY (`Aj_id`) REFERENCES `teacher` (`Aj_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ตาราง: evaluation (ประเมินความพึงพอใจ)
-- Eva_score: 1=ไม่พอใจ ... 5=ดีที่สุด
-- ============================================================
CREATE TABLE `evaluation` (
  `Eva_id` int(5) NOT NULL AUTO_INCREMENT,
  `Eva_score` int(1) NOT NULL COMMENT '1=ไม่พอใจ, 5=ดีที่สุด',
  `Eva_sug` text DEFAULT NULL COMMENT 'ความเห็นเพิ่มเติม',
  `Re_id` int(15) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`Eva_id`),
  KEY `Re_id` (`Re_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- ตาราง: notification (การแจ้งเตือน)
-- Noti_type: system, email, both
-- ============================================================
CREATE TABLE `notification` (
  `Noti_id` int(15) NOT NULL AUTO_INCREMENT,
  `Noti_title` varchar(100) NOT NULL,
  `Noti_message` text NOT NULL,
  `Noti_type` varchar(20) DEFAULT 'system' COMMENT 'system, email, both',
  `Noti_status` tinyint(1) DEFAULT 0 COMMENT '0=ยังไม่อ่าน, 1=อ่านแล้ว',
  `Noti_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `Re_id` int(15) DEFAULT NULL,
  `Stu_id` varchar(13) DEFAULT NULL,
  `Aj_id` int(2) DEFAULT NULL,
  `created_by` int(2) DEFAULT NULL,
  PRIMARY KEY (`Noti_id`),
  KEY `idx_notification_student` (`Stu_id`),
  KEY `idx_notification_teacher` (`Aj_id`),
  KEY `idx_notification_request` (`Re_id`),
  KEY `idx_notification_status` (`Noti_status`),
  KEY `idx_notification_date` (`Noti_date`),
  KEY `idx_notification_type` (`Noti_type`),
  KEY `notification_ibfk_4` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ตาราง: suspend_history (ประวัติการระงับบัญชี)
-- Sh_user_type: S=นักศึกษา, T=อาจารย์
-- ============================================================
CREATE TABLE `suspend_history` (
  `Sh_id` int(15) NOT NULL AUTO_INCREMENT,
  `Sh_user_type` varchar(1) NOT NULL COMMENT 'S=นักศึกษา, T=อาจารย์',
  `Sh_user_id` varchar(13) NOT NULL,
  `Sh_reason` text NOT NULL,
  `Sh_suspend_date` date NOT NULL,
  `Sh_suspend_by` int(2) NOT NULL,
  `Sh_release_date` date DEFAULT NULL,
  `Sh_release_by` int(2) DEFAULT NULL,
  `Sh_status` int(1) DEFAULT 1 COMMENT '1=กำลังระงับ, 0=ปลดแล้ว',
  `Re_id` int(15) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`Sh_id`),
  KEY `idx_suspend_history_user` (`Sh_user_type`, `Sh_user_id`),
  KEY `idx_suspend_history_status` (`Sh_status`),
  KEY `idx_suspend_history_suspend_date` (`Sh_suspend_date`),
  KEY `suspend_history_suspend_by_fk` (`Sh_suspend_by`),
  KEY `suspend_history_release_by_fk` (`Sh_release_by`),
  KEY `suspend_history_request_fk` (`Re_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- ============================================================
-- หมายเหตุ:
-- 1. เปลี่ยน password ของ teacher และ student หลัง import ทันที
-- 2. ระบบนี้เก็บ password แบบ plain text — แนะนำใช้ password_hash() ใน PHP
-- 3. เพิ่มข้อมูล organization_unit ตามโครงสร้างจริงของมหาวิทยาลัย
-- ============================================================
