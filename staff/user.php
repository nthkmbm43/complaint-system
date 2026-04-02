<?php
// staff/user.php - หน้าจัดการข้อมูลอาจารย์/เจ้าหน้าที่ (ฉบับแก้ไข: จำกัดสิทธิ์ 1 และ 2)
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();
requireStaffAccess();
// ตรวจสอบการล็อกอิน
if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

// ตรวจสอบว่าเป็น Teacher หรือ Admin เท่านั้น
if (!hasRole('teacher')) {
    $accessDeniedMessage = "หน้านี้สำหรับอาจารย์และผู้ดูแลระบบเท่านั้น เนื่องจากคุณไม่มีสิทธิ์เข้าถึงข้อมูลอาจารย์";
    $accessDeniedRedirect = "../index.php";
}

$userPermission = $_SESSION['permission'] ?? 0;
if ($userPermission < 3) {
    $accessDeniedMessage = "หน้านี้สำหรับผู้ดูแลระบบเท่านั้น เนื่องจากการจัดการข้อมูลเจ้าหน้าที่เป็นสิทธิ์เฉพาะผู้ดูแลระบบ (สิทธิ์ระดับ 3)";
    $accessDeniedRedirect = "index.php";
}

$db = getDB();
$user = getCurrentUser();
$currentUserId = $_SESSION['user_id']; // เก็บ ID ผู้ใช้ปัจจุบันเพื่อใช้เช็คใน JS

// --- Permission Logic (3-tier) ---
// 1 = อาจารย์   -> แก้ไขได้แค่ตัวเอง
// 2 = ผู้ดำเนินการ -> จัดการข้อร้องเรียน (ไม่มีสิทธิ์หน้านี้)
// 3 = ผู้ดูแลระบบ -> จัดการข้อมูลพื้นฐานทั้งหมด
$userPermission = $_SESSION['permission'] ?? 1;

// กำหนดตัวแปรเช็คสิทธิ์
$isAdmin = ($userPermission == 3);  // ผู้ดูแลระบบเท่านั้น
$isTeacher = ($userPermission == 1);

// สิทธิ์การจัดการระดับสูง (เพิ่ม/ลบ/ระงับ) ต้องเป็น Super Admin เท่านั้น
$canManageUsers = $isAdmin;

// ดึงข้อมูลพื้นฐาน (สำหรับ fallback)
$currentPage = intval($_GET['page'] ?? 1);
$itemsPerPage = 20;

// ดึงสถิติ
$stats = [
    'total' => 0,
    'active' => 0,
    'suspended' => 0,
    'today' => 0
];

try {
    $stats = [
        'total' => $db->count('teacher'),
        'active' => $db->count('teacher', 'Aj_status = 1'),
        'suspended' => $db->count('teacher', 'Aj_status = 0'),
        'today' => $db->count('teacher', 'DATE(created_at) = CURDATE()')
    ];
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
}

// ดึงข้อมูลหน่วยงานสำหรับ dropdown
$departments = [];
try {
    $departments = $db->fetchAll("SELECT * FROM organization_unit ORDER BY Unit_name");
} catch (Exception $e) {
    error_log("Departments error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อมูลอาจารย์ - ระบบร้องเรียน</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* CSS เดิมทั้งหมด (คงไว้ตามเดิมเพื่อให้หน้าตาสวยงามเหมือนเดิม) */
        body {
            font-family: 'Sarabun', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            margin: 0;
            padding: 0;
        }

        .users-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            color: white;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
        }

        .users-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .users-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 300;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 2rem 1.5rem;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid #f1f5f9;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
        }

        .stat-card.total::before {
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .stat-card.active::before {
            background: linear-gradient(90deg, #56ab2f, #a8e6cf);
        }

        .stat-card.suspended::before {
            background: linear-gradient(90deg, #ff6b6b, #ffa8a8);
        }

        .stat-card.today::before {
            background: linear-gradient(90deg, #4ecdc4, #44a08d);
        }

        .stat-icon {
            font-size: 3rem;
            padding: 1.2rem;
            border-radius: 16px;
            flex-shrink: 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .stat-card.total .stat-icon {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .stat-card.active .stat-icon {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
        }

        .stat-card.suspended .stat-icon {
            background: linear-gradient(135deg, #ff6b6b, #ffa8a8);
            color: white;
        }

        .stat-card.today .stat-icon {
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
            color: white;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2d3748;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 1rem;
            color: #718096;
            font-weight: 500;
        }

        .users-controls {
            background: white;
            padding: 2.5rem;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            border: 1px solid #f1f5f9;
        }

        .filters-section {
            display: grid;
            grid-template-columns: 2fr 1fr 1.5fr auto auto auto;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1.5rem;
            min-height: 60px;
        }

        .filter-group {
            min-width: 0;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
            font-family: 'Sarabun', sans-serif;
            box-sizing: border-box;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            justify-content: flex-end;
            white-space: nowrap;
        }

        .actions-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 1rem 2rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            font-family: 'Sarabun', sans-serif;
        }

        .btn:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .btn-primary {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.3);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
        }

        .btn-success {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
            box-shadow: 0 6px 20px rgba(86, 171, 47, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            box-shadow: 0 6px 20px rgba(240, 147, 251, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3);
        }

        .btn-confirm-delete {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .btn-confirm-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }									

        .btn-info {
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
            color: white;
            box-shadow: 0 6px 20px rgba(78, 205, 196, 0.3);
        }

        .btn-sm {
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
            border-radius: 8px;
        }

        .refresh-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
        }

        .refresh-btn:hover {
            transform: rotate(90deg) scale(1.1);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }

        .users-table-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: 1px solid #f1f5f9;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        .users-table thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .users-table th {
            padding: 1.5rem 1.25rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.75px;
        }

        .users-table tbody tr {
            transition: all 0.3s ease;
            border-bottom: 1px solid #f1f5f9;
        }

        .users-table tbody tr:hover {
            background: linear-gradient(90deg, #f8faff, #ffffff);
            transform: translateX(2px);
        }

        .users-table td {
            padding: 1.5rem 1.25rem;
            vertical-align: middle;
        }

        .teacher-id {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: #4a5568;
            background: #f8faff;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            display: inline-block;
            font-size: 0.9rem;
        }

        .teacher-name strong {
            font-size: 1.1rem;
            color: #2d3748;
            font-weight: 600;
        }

        .position-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
            color: white;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 0.5rem;
        }

        .unit-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .department,
        .faculty {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
        }

        .department {
            color: #4a5568;
            font-weight: 600;
        }

        .faculty {
            color: #718096;
            font-weight: 500;
        }

        .unit-icon {
            font-size: 1.1rem;
            flex-shrink: 0;
            min-width: 1.2rem;
            text-align: center;
        }

        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .phone,
        .email {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            color: #4a5568;
        }

        .status-badge {
            padding: 0.6rem 1.2rem;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
            width: fit-content;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .status-badge.active {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            color: white;
        }

        .status-badge.suspended {
            background: linear-gradient(135deg, #ff6b6b, #ffa8a8);
            color: white;
        }

        .permission-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 0.5rem;
        }

        .permission-badge.perm-1 {
            background: #e6fffa;
            color: #319795;
        }

        .permission-badge.perm-2 {
            background: #fed7d7;
            color: #c53030;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .action-buttons .btn {
            min-width: 3rem;
            height: 3rem;
            padding: 0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .loading {
            text-align: center;
            padding: 4rem;
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            margin: 2rem 0;
        }

        .loading-spinner {
            border: 4px solid #f1f5f9;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1.5rem;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .toast {
            position: fixed;
            top: 2rem;
            right: 2rem;
            padding: 1.25rem 2rem;
            border-radius: 16px;
            color: white;
            font-weight: 600;
            z-index: 1000;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            min-width: 350px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
        }

        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }

        .toast.success {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
        }

        .toast.error {
            background: linear-gradient(135deg, #ff6b6b, #ffa8a8);
        }

        .toast.warning {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }

        /* Enhanced Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1500;
            left: 0;
            top: 0;
            width: 100%;
            height: 100vh;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            animation: fadeIn 0.3s ease;
            overflow-y: auto;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            box-sizing: border-box;
        }

        .modal-content {
            background-color: white;
            border-radius: 20px;
            width: 100%;
            max-width: 700px;
            max-height: calc(100vh - 4rem);
            overflow: hidden;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.4s ease;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2rem 2.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 20px 20px 0 0;
            flex-shrink: 0;
        }

        .modal-title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .close {
            font-size: 1.8rem;
            font-weight: bold;
            cursor: pointer;
            color: rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
            width: 2.5rem;
            height: 2.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            flex-shrink: 0;
        }

        .close:hover {
            color: white;
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg) scale(1.1);
        }

        .modal-content form {
            padding: 2.5rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            overflow-y: auto;
            flex: 1;
        }

        .form-group {
            margin-bottom: 0;
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 600;
            color: #2d3748;
            font-size: 1rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 1.2rem 1.5rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
            font-family: 'Sarabun', sans-serif;
            box-sizing: border-box;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
            flex: 1;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        .modal-actions {
            display: flex;
            gap: 1.5rem;
            justify-content: flex-end;
            margin-top: auto;
            padding-top: 2rem;
            border-top: 2px solid #f1f5f9;
            grid-column: 1 / -1;
            flex-shrink: 0;
        }

        /* Suspend Modal Styles */
        .suspend-modal .modal-content {
            max-width: 600px;
            min-height: 500px;
        }

        .suspend-modal .modal-header {
            background: linear-gradient(135deg, #ff6b6b, #ffa8a8);
        }

        .suspend-form {
            padding: 2.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .teacher-info {
            background: #fff5f5;
            padding: 2rem 1.5rem;
            border-radius: 16px;
            border: 2px solid #fed7d7;
            text-align: center;
            flex-shrink: 0;
        }

        .teacher-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            margin: 0 auto 1rem;
        }

        .teacher-name-display {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0.5rem;
        }

        .teacher-id-display {
            font-family: 'Courier New', monospace;
            color: #718096;
            font-size: 0.9rem;
        }

        .suspend-form .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .suspend-form .form-group textarea {
            flex: 1;
            min-height: 150px;
            resize: vertical;
        }

        .suspend-form .modal-actions {
            margin-top: auto;
            padding-top: 2rem;
            border-top: 2px solid #f1f5f9;
            flex-shrink: 0;
        }

        /* Release Modal Styles */
        .release-modal .modal-content {
            max-width: 600px;
            min-height: 500px;
        }

        .release-modal .modal-header {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
        }

        .release-form {
            padding: 2.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .release-teacher-info {
            background: #f0fff4;
            padding: 2rem 1.5rem;
            border-radius: 16px;
            border: 2px solid #c6f6d5;
            text-align: center;
            flex-shrink: 0;
        }

        .release-teacher-info .teacher-avatar {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
        }

        .suspension-info {
            background: #fff5f5;
            padding: 1.5rem;
            border-radius: 12px;
            border: 2px solid #fed7d7;
            margin-bottom: 1rem;
        }

        .suspension-title {
            font-size: 1rem;
            font-weight: 600;
            color: #c53030;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .suspension-details {
            display: grid;
            gap: 0.75rem;
        }

        .suspension-detail {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .detail-label-small {
            font-size: 0.9rem;
            color: #718096;
            font-weight: 500;
            min-width: 120px;
            flex-shrink: 0;
        }

        .detail-value-small {
            font-size: 0.9rem;
            color: #2d3748;
            flex: 1;
        }

        .suspend-reason-text {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-style: italic;
            color: #4a5568;
            line-height: 1.5;
        }

        .release-form .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .release-form .form-group textarea {
            flex: 1;
            min-height: 120px;
            resize: vertical;
        }

        .release-form .modal-actions {
            margin-top: auto;
            padding-top: 2rem;
            border-top: 2px solid #f1f5f9;
            flex-shrink: 0;
        }

        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 2rem;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            margin-top: 2rem;
            border: 1px solid #f1f5f9;
        }

        .pagination {
            display: flex;
            gap: 0.5rem;
        }

        .page-link {
            padding: 0.8rem 1.2rem;
            background: white;
            border: 2px solid #e2e8f0;
            color: #4a5568;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .page-link:hover {
            background: #f8faff;
            border-color: #667eea;
            color: #667eea;
            transform: translateY(-2px);
        }

        .page-link.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-color: #667eea;
            color: white;
        }

        /* Teacher Detail Modal Styles */
        .teacher-detail-modal .modal-content {
            max-width: 900px;
            max-height: calc(100vh - 2rem);
        }

        .teacher-detail-header {
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
            color: white;
            padding: 2rem 2.5rem;
            border-radius: 20px 20px 0 0;
        }

        .teacher-detail-body {
            padding: 2.5rem;
            overflow-y: auto;
            flex: 1;
        }

        .detail-section {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .detail-section:last-child {
            border-bottom: none;
        }

        .section-title {
            color: #2d3748;
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .detail-item {
            background: #f8faff;
            padding: 1.5rem;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
        }

        .detail-label {
            font-size: 0.9rem;
            color: #718096;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .detail-value {
            font-size: 1.1rem;
            color: #2d3748;
            font-weight: 600;
        }

        /* Custom Confirm Dialog */
        .custom-confirm {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .confirm-content {
            background: white;
            padding: 0;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.4s ease;
        }

        .confirm-header {
            background: linear-gradient(135deg, #ff6b6b, #ffa8a8);
            color: white;
            padding: 1.5rem 2rem;
            border-radius: 20px 20px 0 0;
            text-align: center;
        }

        .confirm-body {
            padding: 2rem;
            text-align: center;
        }

        .confirm-input {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            margin: 1rem 0;
            font-size: 1rem;
            text-align: center;
            font-family: 'Courier New', monospace;
            box-sizing: border-box;
        }

        .confirm-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .filters-section {
                grid-template-columns: 1fr 1fr 1fr;
                gap: 1rem;
            }

            .filter-actions {
                grid-column: 1 / -1;
                justify-content: center;
                margin-top: 1rem;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filters-section {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .filter-actions {
                grid-column: 1;
                justify-content: stretch;
            }

            .filter-actions .btn,
            .filter-actions .refresh-btn {
                flex: 1;
                text-align: center;
            }

            .users-table-container {
                overflow-x: auto;
            }

            .users-table {
                min-width: 600px;
            }

            .pagination-container {
                flex-direction: column;
                gap: 1rem;
            }

            .modal {
                padding: 1rem;
                align-items: flex-start;
                padding-top: 2rem;
            }

            .modal-content {
                max-height: calc(100vh - 2rem);
                width: 100%;
                margin: 0;
            }

            .modal-content form {
                grid-template-columns: 1fr;
                gap: 1.5rem;
                padding: 2rem;
            }

            .suspend-form {
                padding: 2rem;
            }

            .release-form {
                padding: 2rem;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .modal {
                padding: 0.5rem;
                padding-top: 1rem;
            }

            .modal-content form {
                padding: 1.5rem;
            }

            .suspend-form {
                padding: 1.5rem;
            }

            .release-form {
                padding: 1.5rem;
            }
        }

        .no-data {
            text-align: center;
            padding: 4rem 2rem;
            color: #718096;
        }

        .no-data div {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        .no-data p {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
        }

        .department-select .form-group select {
            transform: translateY(0);
            position: relative;
            z-index: 10;
        }

        .department-select select option {
            padding: 0.5rem;
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>
    <?php if (isset($accessDeniedMessage)): ?>
        <script>
            window.addEventListener('DOMContentLoaded', function() {
                showAccessDenied(
                    "<?php echo $accessDeniedMessage; ?>",
                    "<?php echo $accessDeniedRedirect; ?>"
                );
            });
        </script>
    <?php endif; ?>

    <div class="main-content">
        <?php include '../includes/header.php'; ?>

        <div class="page-content">
            <div class="users-header">
                <div class="users-title">
                    <span>👨‍🏫</span>
                    จัดการข้อมูลอาจารย์
                </div>
                <div class="users-subtitle">
                    จัดการข้อมูลอาจารย์และเจ้าหน้าที่ในระบบ
                </div>
            </div>

            <div class="stats-grid" id="statsGrid">
                <div class="stat-card total">
                    <div class="stat-icon">👨‍🏫</div>
                    <div class="stat-content">
                        <div class="stat-number" id="totalTeachers"><?php echo number_format($stats['total']); ?></div>
                        <div class="stat-label">อาจารย์/เจ้าหน้าที่ทั้งหมด</div>
                    </div>
                </div>
                <div class="stat-card active">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="stat-number" id="activeTeachers"><?php echo number_format($stats['active']); ?></div>
                        <div class="stat-label">ใช้งานอยู่</div>
                    </div>
                </div>
                <div class="stat-card suspended">
                    <div class="stat-icon">⛔</div>
                    <div class="stat-content">
                        <div class="stat-number" id="suspendedTeachers"><?php echo number_format($stats['suspended']); ?></div>
                        <div class="stat-label">ถูกระงับ</div>
                    </div>
                </div>
                <div class="stat-card today">
                    <div class="stat-icon">📈</div>
                    <div class="stat-content">
                        <div class="stat-number" id="todayTeachers"><?php echo number_format($stats['today']); ?></div>
                        <div class="stat-label">สมัครวันนี้</div>
                    </div>
                </div>
            </div>

            <div class="users-controls">
                <div class="filters-section">
                    <div class="filter-group">
                        <input type="text" id="searchInput" placeholder="ค้นหาชื่อ ตำแหน่ง หรืออีเมล..."
                            value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                    <div class="filter-group">
                        <select id="statusFilter">
                            <option value="">ทุกสถานะ</option>
                            <option value="1">ใช้งานอยู่</option>
                            <option value="0">ถูกระงับ</option>
                        </select>
                    </div>
                    <div class="filter-group department-select">
                        <select id="departmentFilter">
                            <option value="">ทุกหน่วยงาน</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['Unit_id']; ?>">
                                    <?php echo isset($dept['Unit_icon']) ? $dept['Unit_icon'] . ' ' : '🏢 '; ?>
                                    <?php echo htmlspecialchars($dept['Unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button onclick="searchTeachers()" class="btn btn-primary">ค้นหา</button>
                        <button onclick="clearFilters()" class="btn btn-secondary">ล้างตัวกรอง</button>
                        <button onclick="refreshData()" class="refresh-btn" title="รีเฟรชข้อมูล">🔄</button>
                    </div>
                </div>

                <?php if ($canManageUsers): ?>
                    <div class="actions-section">
                        <button onclick="showAddTeacherModal()" class="btn btn-success">
                            ➕ เพิ่มอาจารย์/เจ้าหน้าที่
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="loading" id="loadingIndicator">
                <div class="loading-spinner"></div>
                <div>กำลังโหลดข้อมูล...</div>
            </div>

            <div class="users-table-container">
                <div class="table-container" id="tableContainer">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>รหัสอาจารย์</th>
                                <th>ชื่อ-นามสกุล</th>
                                <th>ตำแหน่ง</th>
                                <th>หน่วยงาน</th>
                                <th>ติดต่อ</th>
                                <th>สถานะ</th>
                                <th>วันที่สมัคร</th>
                                <th>การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="teachersTableBody">
                            <tr>
                                <td colspan="8" class="no-data">
                                    <div>🔭</div>
                                    <p>กำลังโหลดข้อมูล...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="pagination-container" id="paginationContainer" style="display: none;">
                <div class="pagination-info" id="paginationInfo"></div>
                <div class="pagination" id="paginationLinks"></div>
            </div>
        </div>
    </div>

    <?php if ($canManageUsers): ?>
        <div id="addTeacherModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <span>👨‍🏫</span>
                        เพิ่มอาจารย์/เจ้าหน้าที่ใหม่
                    </h3>
                    <span class="close" onclick="closeModal('addTeacherModal')">&times;</span>
                </div>
                <form id="addTeacherForm">

                    <!-- แถวที่ 1: รหัส (auto) + รหัสผ่าน -->
                    <div class="form-group">
                        <label>🪪 รหัสประจำตัว</label>
                        <div style="position:relative;">
                            <input type="text" id="add_teacher_id" name="teacher_id" readonly
                                style="background:#f0f4ff; color:#3730a3; font-family:'Courier New',monospace; font-weight:700; font-size:1.1rem; letter-spacing:1px; padding-right:110px; cursor:default; border-color:#c7d2fe;">
                            <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:linear-gradient(135deg,#667eea,#764ba2); color:white; font-size:0.75rem; font-weight:600; padding:4px 10px; border-radius:20px; white-space:nowrap;">⚡ รันอัตโนมัติ</span>
                        </div>
                        <small style="color:#6366f1; margin-top:5px; font-size:0.82rem;">🔒 ระบบกำหนดให้อัตโนมัติ ไม่สามารถเปลี่ยนได้</small>
                    </div>
                    <div class="form-group">
                        <label for="add_teacher_password">🔑 รหัสผ่าน *</label>
                        <input type="password" id="add_teacher_password" name="teacher_password" required minlength="6"
                            placeholder="อย่างน้อย 6 ตัวอักษร">
                    </div>

                    <!-- แถวที่ 2: ชื่อ-นามสกุล + ตำแหน่ง -->
                    <div class="form-group">
                        <label for="add_teacher_name">👤 ชื่อ-นามสกุล *</label>
                        <input type="text" id="add_teacher_name" name="teacher_name" required
                            placeholder="กรอกชื่อ-นามสกุล">
                    </div>
                    <div class="form-group">
                        <label for="add_teacher_position">🎓 ตำแหน่ง</label>
                        <select id="add_teacher_position" name="teacher_position">
                            <option value="">เลือกตำแหน่ง</option>
                            <option value="อาจารย์">อาจารย์</option>
                            <option value="ดร.">ดร.</option>
                            <option value="ผศ.ดร.">ผศ.ดร.</option>
                        </select>
                    </div>

                    <!-- แถวที่ 3: หน่วยงาน full-width -->
                    <div class="form-group full-width">
                        <label for="add_unit_id">🏢 หน่วยงาน</label>
                        <select id="add_unit_id" name="unit_id">
                            <option value="">เลือกหน่วยงาน</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['Unit_id']; ?>">
                                    <?php echo isset($dept['Unit_icon']) ? $dept['Unit_icon'] . ' ' : '🏢 '; ?>
                                    <?php echo htmlspecialchars($dept['Unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- แถวที่ 4: เบอร์โทร + อีเมล -->
                    <div class="form-group">
                        <label for="add_teacher_tel">📞 เบอร์โทรศัพท์</label>
                        <input type="tel" id="add_teacher_tel" name="teacher_tel"
                            placeholder="เช่น 0812345678">
                    </div>
                    <div class="form-group">
                        <label for="add_teacher_email">📧 อีเมล</label>
                        <input type="email" id="add_teacher_email" name="teacher_email"
                            placeholder="เช่น teacher@rmuti.ac.th">
                    </div>

                    <!-- แถวที่ 5: ระดับสิทธิ์ full-width -->
                    <div class="form-group full-width">
                        <label for="add_permission">🔐 ระดับสิทธิ์</label>
                        <select id="add_permission" name="permission" required>
                            <option value="1">👨‍🏫 อาจารย์</option>
                            <option value="2">🧑‍💻 ผู้ดำเนินการ</option>
                            <option value="3">👨‍💼 ผู้ดูแลระบบ</option>
                        </select>
                    </div>

                    <div class="modal-actions">
                        <button type="button" onclick="closeModal('addTeacherModal')" class="btn btn-secondary">ยกเลิก</button>
                        <button type="submit" class="btn btn-success">➕ เพิ่มอาจารย์/เจ้าหน้าที่</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div id="editTeacherModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <span>✏️</span>
                    แก้ไขข้อมูลอาจารย์/เจ้าหน้าที่
                </h3>
                <span class="close" onclick="closeModal('editTeacherModal')">&times;</span>
            </div>
            <form id="editTeacherForm">
                <input type="hidden" id="edit_teacher_id" name="teacher_id">

                <!-- แถวที่ 1: รหัส (readonly) + ชื่อ-นามสกุล -->
                <div class="form-group">
                    <label>🪪 รหัสอาจารย์ / Username</label>
                    <input type="text" id="edit_teacher_id_display" readonly
                        style="background:#f8fafc; color:#4a5568; font-family:'Courier New',monospace; font-weight:600; cursor:not-allowed; border-color:#e2e8f0;">
                    <small style="color:#718096; margin-top:6px; font-size:0.85rem;">ไม่สามารถเปลี่ยนรหัสได้</small>
                </div>
                <div class="form-group">
                    <label for="edit_teacher_name">👤 ชื่อ-นามสกุล *</label>
                    <input type="text" id="edit_teacher_name" name="teacher_name" required>
                </div>

                <!-- แถวที่ 2: ตำแหน่ง + หน่วยงาน -->
                <div class="form-group">
                    <label for="edit_teacher_position">🎓 ตำแหน่ง</label>
                    <select id="edit_teacher_position" name="teacher_position">
                        <option value="">เลือกตำแหน่ง</option>
                        <option value="อาจารย์">อาจารย์</option>
                        <option value="ดร.">ดร.</option>
                        <option value="ผศ.ดร.">ผศ.ดร.</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_unit_id">หน่วยงาน</label>
                    <select id="edit_unit_id" name="unit_id">
                        <option value="">เลือกหน่วยงาน</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['Unit_id']; ?>">
                                <?php echo isset($dept['Unit_icon']) ? $dept['Unit_icon'] . ' ' : '🏢 '; ?>
                                <?php echo htmlspecialchars($dept['Unit_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_teacher_tel">เบอร์โทรศัพท์</label>
                    <input type="tel" id="edit_teacher_tel" name="teacher_tel">
                </div>
                <div class="form-group">
                    <label for="edit_teacher_email">อีเมล</label>
                    <input type="email" id="edit_teacher_email" name="teacher_email">
                </div>
                <div class="form-group">
                    <label for="edit_permission">ระดับสิทธิ์</label>
                    <select id="edit_permission" name="permission" required <?php echo (!$isAdmin) ? 'disabled' : ''; ?>>
                        <option value="1">👨‍🏫 อาจารย์</option>
                        <option value="2">🧑‍💻 ผู้ดำเนินการ</option>
                        <option value="3">👨‍💼 ผู้ดูแลระบบ</option>
                    </select>
                    <?php if (!$isAdmin): ?>
                        <input type="hidden" name="permission" id="edit_permission_hidden">
                    <?php endif; ?>
                </div>
                <div class="form-group full-width">
                    <label for="edit_teacher_password">รหัสผ่านใหม่ (เว้นว่างหากไม่ต้องการเปลี่ยน)</label>
                    <input type="password" id="edit_teacher_password" name="teacher_password" minlength="6">
                </div>
                <div class="modal-actions">
                    <button type="button" onclick="closeModal('editTeacherModal')" class="btn btn-secondary">ยกเลิก</button>
                    <button type="submit" class="btn btn-success">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($canManageUsers): ?>
        <div id="suspendTeacherModal" class="modal suspend-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <span>⛔</span>
                        ระงับบัญชีอาจารย์/เจ้าหน้าที่
                    </h3>
                    <span class="close" onclick="closeModal('suspendTeacherModal')">&times;</span>
                </div>
                <form id="suspendTeacherForm" class="suspend-form">
                    <input type="hidden" id="suspend_teacher_id" name="teacher_id">

                    <div class="teacher-info">
                        <div class="teacher-avatar">👨‍🏫</div>
                        <div class="teacher-name-display" id="suspend_teacher_name_display"></div>
                        <div class="teacher-id-display" id="suspend_teacher_id_display"></div>
                    </div>

                    <div class="form-group">
                        <label for="suspend_reason">เหตุผลในการระงับบัญชี *</label>
                        <textarea id="suspend_reason" name="suspend_reason" required
                            placeholder="กรอกเหตุผลในการระงับบัญชี"></textarea>
                    </div>

                    <div class="modal-actions">
                        <button type="button" onclick="closeModal('suspendTeacherModal')" class="btn btn-secondary">ยกเลิก</button>
                        <button type="submit" class="btn btn-success">ยืนยันระงับ</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="releaseTeacherModal" class="modal release-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <span>✅</span>
                        ปลดการระงับบัญชี
                    </h3>
                    <span class="close" onclick="closeModal('releaseTeacherModal')">&times;</span>
                </div>
                <form id="releaseTeacherForm" class="release-form">
                    <input type="hidden" id="release_teacher_id" name="teacher_id">

                    <div class="release-teacher-info">
                        <div class="teacher-avatar">✅</div>
                        <div class="teacher-name-display" id="release_teacher_name_display"></div>
                        <div class="teacher-id-display" id="release_teacher_id_display"></div>
                    </div>

                    <div class="suspension-info">
                        <div class="suspension-title">
                            <span>⛔</span>
                            ข้อมูลการระงับปัจจุบัน
                        </div>
                        <div class="suspension-details">
                            <div class="suspension-detail">
                                <div class="detail-label-small">วันที่ระงับ:</div>
                                <div class="detail-value-small" id="suspension_date_display">-</div>
                            </div>
                            <div class="suspension-detail">
                                <div class="detail-label-small">ระงับโดย:</div>
                                <div class="detail-value-small" id="suspended_by_display">-</div>
                            </div>
                            <div class="suspension-detail">
                                <div class="detail-label-small">เหตุผล:</div>
                                <div class="detail-value-small">
                                    <div class="suspend-reason-text" id="suspension_reason_display">-</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="release_reason">เหตุผลในการปลดการระงับ</label>
                        <textarea id="release_reason" name="release_reason"
                            placeholder="กรอกเหตุผลในการปลดการระงับ"></textarea>
                    </div>

                    <div class="modal-actions">
                        <button type="button" onclick="closeModal('releaseTeacherModal')" class="btn btn-secondary">ยกเลิก</button>
                        <button type="submit" class="btn btn-success">ปลดการระงับ</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div id="teacherDetailModal" class="modal teacher-detail-modal">
        <div class="modal-content">
            <div class="teacher-detail-header">
                <h3 class="modal-title">
                    <span>👨‍🏫</span>
                    รายละเอียดอาจารย์/เจ้าหน้าที่
                </h3>
                <span class="close" onclick="closeModal('teacherDetailModal')">&times;</span>
            </div>
            <div class="teacher-detail-body">
                <div id="teacherDetailContent">
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let currentFilters = {
            search: '',
            status: '',
            department: '',
            page: 1
        };

        const canManageUsers = <?php echo $canManageUsers ? 'true' : 'false'; ?>;
        const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        const currentUserId = <?php echo json_encode($currentUserId); ?>;

        // Debug function
        function debugFormData(formData, formType) {
            console.log(`=== ${formType} Form Data ===`);
            if (formData instanceof FormData) {
                for (let [key, value] of formData.entries()) {
                    console.log(`${key}: ${value}`);
                }
            } else {
                console.log(formData);
            }
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            loadInitialData();
            setupEventListeners();
        });

        function setupEventListeners() {
            // Search on Enter key
            document.getElementById('searchInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchTeachers();
                }
            });

            // Auto-search after typing (debounced)
            let searchTimeout;
            document.getElementById('searchInput').addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(searchTeachers, 500);
            });

            // Filter changes
            document.getElementById('statusFilter').addEventListener('change', searchTeachers);
            document.getElementById('departmentFilter').addEventListener('change', searchTeachers);

            // Form submissions
            document.getElementById('editTeacherForm').addEventListener('submit', handleEditTeacher);

            if (canManageUsers) {
                document.getElementById('addTeacherForm').addEventListener('submit', handleAddTeacher);
                document.getElementById('suspendTeacherForm').addEventListener('submit', handleSuspendTeacher);
                document.getElementById('releaseTeacherForm').addEventListener('submit', handleReleaseTeacher);
            }
        }

        async function loadInitialData() {
            await Promise.all([
                loadTeachers(),
                loadStats()
            ]);
        }

        async function loadTeachers(resetPage = false) {
            if (resetPage) currentFilters.page = 1;

            showLoading(true);

            try {
                const params = new URLSearchParams({
                    action: 'search',
                    search: document.getElementById('searchInput').value || '',
                    status: document.getElementById('statusFilter').value || '',
                    department: document.getElementById('departmentFilter').value || '',
                    page: currentFilters.page,
                    limit: 20
                });

                const response = await fetch(`ajax/teachers_search.php?${params}`);
                const result = await response.json();

                if (result.success) {
                    displayTeachers(result.data);
                    displayPagination(result.pagination);
                    currentFilters = result.filters;
                } else {
                    showToast('เกิดข้อผิดพลาด: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Load teachers error:', error);
                showToast('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
            } finally {
                showLoading(false);
            }
        }

        async function loadStats() {
            try {
                const response = await fetch('ajax/teachers_search.php?action=get_stats');
                const result = await response.json();

                if (result.success) {
                    updateStats(result.stats);
                }
            } catch (error) {
                console.error('Load stats error:', error);
            }
        }

        function displayTeachers(teachers) {
            const tbody = document.getElementById('teachersTableBody');

            if (teachers.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="no-data">
                            <div>🔭</div>
                            <p>ไม่พบข้อมูลอาจารย์/เจ้าหน้าที่</p>
                            <button onclick="clearFilters()" class="btn btn-secondary">ล้างตัวกรอง</button>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = teachers.map(teacher => {
                // Logic: 
                // 1. อาจารย์ทั่วไป (1): เห็นปุ่มแก้ไขได้เฉพาะของตัวเอง (currentUserId == teacher.Aj_id)
                // 2. ผู้ดูแลระบบ (2): เห็นปุ่มจัดการทุกคน แต่ไม่สามารถ ลบ/ระงับ ตัวเองได้

                const isOwner = (teacher.Aj_id == currentUserId);

                // สิทธิ์แก้ไข: Admin หรือ เจ้าของบัญชี
                const canEdit = (isAdmin || isOwner);

                // สิทธิ์จัดการ (ระงับ/ลบ): Admin เท่านั้น และต้องไม่ใช่ตัวเอง
                const canManage = (isAdmin && !isOwner);

                return `
                <tr class="teacher-row ${teacher.status_class}">
                    <td>
                        <div class="teacher-id">${teacher.Aj_id}</div>
                    </td>
                    <td>
                        <div class="teacher-name">
                            <strong>${escapeHtml(teacher.Aj_name)}</strong>
                            ${teacher.Aj_status == 0 ? '<span class="suspended-badge">ระงับ</span>' : ''}
                        </div>
                    </td>
                    <td>
                        <div class="position-info">
                            ${teacher.Aj_position ? `<div class="position-badge">${escapeHtml(teacher.Aj_position)}</div>` : ''}
                            <div class="permission-badge perm-${teacher.Aj_per}">
                                ${teacher.Aj_per == 3 ? '👨‍💼 ' : teacher.Aj_per == 2 ? '🧑‍💻 ' : '👨‍🏫 '} ${getPermissionText(teacher.Aj_per)}
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="unit-info">
                            ${teacher.unit_name ? `
                                <div class="department">
                                    <span class="unit-icon">${teacher.unit_icon || '🏢'}</span>
                                    ${escapeHtml(teacher.unit_name)}
                                </div>
                            ` : 'ไม่ระบุ'}
                        </div>
                    </td>
                    <td>
                        <div class="contact-info">
                            ${teacher.Aj_tel ? `<div class="phone">📞 ${escapeHtml(teacher.Aj_tel)}</div>` : ''}
                            ${teacher.Aj_email ? `<div class="email">📧 ${escapeHtml(teacher.Aj_email)}</div>` : ''}
                        </div>
                    </td>
                    <td>
                        <div class="status-info">
                            <span class="status-badge ${teacher.status_class}">${teacher.status_text}</span>
                        </div>
                    </td>
                    <td>
                        <div class="date-info">${teacher.created_at_formatted}</div>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button onclick="viewTeacherDetail('${teacher.Aj_id}')" 
                                    class="btn btn-info btn-sm" title="ดูรายละเอียด">
                                👁️
                            </button>
                            
                            ${canEdit ? `
                                <button onclick="editTeacher('${teacher.Aj_id}')" 
                                        class="btn btn-warning btn-sm" title="แก้ไข">
                                    ✏️
                                </button>
                            ` : ''}
                            
                            ${canManage ? `
                                ${teacher.Aj_status == 1 ? `
                                    <button onclick="showSuspendModal('${teacher.Aj_id}', '${escapeHtml(teacher.Aj_name)}')" 
                                            class="btn btn-warning btn-sm" title="ระงับบัญชี">
                                        ⛔
                                    </button>
                                ` : `
                                    <button onclick="showReleaseModal('${teacher.Aj_id}', '${escapeHtml(teacher.Aj_name)}')" 
                                            class="btn btn-success btn-sm" title="ปลดการระงับ">
                                        ✅
                                    </button>
                                `}
                                <button onclick="deleteTeacherWithConfirm('${teacher.Aj_id}', '${escapeHtml(teacher.Aj_name)}')" 
                                        class="btn btn-danger btn-sm" title="ลบ">
                                    🗑️
                                </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `
            }).join('');
        }

        function getPermissionText(permission) {
            const permissionTexts = {
                1: 'อาจารย์',
                2: 'ผู้ดำเนินการ',
                3: 'ผู้ดูแลระบบ'
            };
            return permissionTexts[permission] || 'ไม่ระบุ';
        }

        function displayPagination(pagination) {
            const container = document.getElementById('paginationContainer');
            const info = document.getElementById('paginationInfo');
            const links = document.getElementById('paginationLinks');

            if (pagination.total_pages <= 1) {
                container.style.display = 'none';
                return;
            }

            container.style.display = 'flex';

            info.textContent = `แสดง ${pagination.start_item.toLocaleString()} - ${pagination.end_item.toLocaleString()} จาก ${pagination.total_items.toLocaleString()} รายการ`;

            let paginationHTML = '';

            // Previous buttons
            if (pagination.current_page > 1) {
                paginationHTML += `<a href="#" onclick="goToPage(1)" class="page-link">หน้าแรก</a>`;
                paginationHTML += `<a href="#" onclick="goToPage(${pagination.current_page - 1})" class="page-link">ก่อนหน้า</a>`;
            }

            // Page numbers
            const startPage = Math.max(1, pagination.current_page - 2);
            const endPage = Math.min(pagination.total_pages, pagination.current_page + 2);

            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === pagination.current_page ? 'active' : '';
                paginationHTML += `<a href="#" onclick="goToPage(${i})" class="page-link ${activeClass}">${i}</a>`;
            }

            // Next buttons
            if (pagination.current_page < pagination.total_pages) {
                paginationHTML += `<a href="#" onclick="goToPage(${pagination.current_page + 1})" class="page-link">ถัดไป</a>`;
                paginationHTML += `<a href="#" onclick="goToPage(${pagination.total_pages})" class="page-link">หน้าสุดท้าย</a>`;
            }

            links.innerHTML = paginationHTML;
        }

        function updateStats(stats) {
            document.getElementById('totalTeachers').textContent = stats.total.toLocaleString();
            document.getElementById('activeTeachers').textContent = stats.active.toLocaleString();
            document.getElementById('suspendedTeachers').textContent = stats.suspended.toLocaleString();
            document.getElementById('todayTeachers').textContent = stats.today.toLocaleString();
        }

        // Navigation functions
        function searchTeachers() {
            loadTeachers(true);
        }

        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('departmentFilter').value = '';
            loadTeachers(true);
        }

        function refreshData() {
            loadInitialData();
        }

        function goToPage(page) {
            currentFilters.page = page;
            loadTeachers();
        }

        // Teacher management functions
        async function viewTeacherDetail(teacherId) {
            try {
                const response = await fetch(`ajax/teachers_search.php?action=get_teacher_detail&teacher_id=${teacherId}`);
                const result = await response.json();

                if (result.success) {
                    displayTeacherDetail(result);
                    showModal('teacherDetailModal');
                } else {
                    showToast('ไม่สามารถโหลดข้อมูลอาจารย์/เจ้าหน้าที่ได้: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('View teacher detail error:', error);
                showToast('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
            }
        }

        function displayTeacherDetail(data) {
            const content = document.getElementById('teacherDetailContent');
            const teacher = data.teacher;

            let html = `
                <div class="detail-section">
                    <h4 class="section-title">📋 ข้อมูลพื้นฐาน</h4>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">รหัสอาจารย์</div>
                            <div class="detail-value">${teacher.Aj_id}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">ชื่อ-นามสกุล</div>
                            <div class="detail-value">${escapeHtml(teacher.Aj_name)}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">ตำแหน่ง</div>
                            <div class="detail-value">${teacher.Aj_position ? escapeHtml(teacher.Aj_position) : 'ไม่ระบุ'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">หน่วยงาน</div>
                            <div class="detail-value">${teacher.unit_name ? `${teacher.unit_icon || '🏢'} ${escapeHtml(teacher.unit_name)}` : 'ไม่ระบุ'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">เบอร์โทรศัพท์</div>
                            <div class="detail-value">${teacher.Aj_tel ? `📞 ${escapeHtml(teacher.Aj_tel)}` : 'ไม่ระบุ'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">อีเมล</div>
                            <div class="detail-value">${teacher.Aj_email ? `📧 ${escapeHtml(teacher.Aj_email)}` : 'ไม่ระบุ'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">สถานะ</div>
                            <div class="detail-value">
                                <span class="status-badge ${teacher.status_class}">${teacher.status_text}</span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">ระดับสิทธิ์</div>
                            <div class="detail-value">
                                <span class="permission-badge perm-${teacher.Aj_per}">${getPermissionText(teacher.Aj_per)}</span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">วันที่สมัคร</div>
                            <div class="detail-value">${teacher.created_at_formatted}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">อัปเดตล่าสุด</div>
                            <div class="detail-value">${teacher.updated_at_formatted || '-'}</div>
                        </div>
                    </div>
                </div>
            `;

            content.innerHTML = html;
        }

        // Teacher CRUD functions (only for users with edit permissions)
        async function handleAddTeacher(e) {
            e.preventDefault();

            if (!canManageUsers) {
                showToast('คุณไม่มีสิทธิ์ในการเพิ่มอาจารย์/เจ้าหน้าที่', 'error');
                return;
            }

            const form = e.target;
            const formData = new FormData(form);

            debugFormData(formData, 'Add Teacher');

            // Convert FormData to object
            const data = {};
            for (let [key, value] of formData.entries()) {
                data[key] = value.trim();
            }
            data.action = 'add_teacher';

            console.log('Sending add teacher data:', data);

            try {
                const response = await fetch('ajax/teachers_crud.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(data)
                });

                console.log('Response status:', response.status);

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('HTTP Error Response:', errorText);
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                console.log('Add teacher result:', result);

                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal('addTeacherModal');
                    await loadTeachers();
                    await loadStats();
                } else {
                    showToast(result.message || 'เกิดข้อผิดพลาดในการเพิ่มอาจารย์/เจ้าหน้าที่', 'error');
                }
            } catch (error) {
                console.error('Add teacher error:', error);
                showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + error.message, 'error');
            }
        }

        async function handleEditTeacher(e) {
            e.preventDefault();
            // อนุญาตให้แก้ไขได้ถ้าเป็น Admin หรือเป็นข้อมูลตัวเอง
            // แต่ Logic ที่เข้มงวดต้องอยู่ที่ไฟล์ teachers_crud.php ด้วย

            const form = e.target;
            const formData = new FormData(form);

            debugFormData(formData, 'Edit Teacher');

            const data = {};
            for (let [key, value] of formData.entries()) {
                data[key] = value.trim();
            }
            data.action = 'edit_teacher';

            console.log('Sending edit teacher data:', data);

            try {
                const response = await fetch('ajax/teachers_crud.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(data)
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('HTTP Error Response:', errorText);
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                console.log('Edit teacher result:', result);

                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal('editTeacherModal');
                    await loadTeachers();
                } else {
                    showToast(result.message || 'เกิดข้อผิดพลาดในการแก้ไขข้อมูล', 'error');
                }
            } catch (error) {
                console.error('Edit teacher error:', error);
                showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + error.message, 'error');
            }
        }

        function showAddTeacherModal() {
            if (!canManageUsers) {
                showToast('คุณไม่มีสิทธิ์ในการเพิ่มอาจารย์/เจ้าหน้าที่', 'error');
                return;
            }

            document.getElementById('addTeacherForm').reset();

            // โหลด Next ID จาก server
            const idField = document.getElementById('add_teacher_id');
            idField.value = 'กำลังโหลด...';
            fetch('ajax/teachers_crud.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'get_next_id' })
            })
            .then(r => r.json())
            .then(result => {
                idField.value = result.next_id ?? '—';
            })
            .catch(() => { idField.value = '—'; });

            showModal('addTeacherModal');
        }

        async function editTeacher(teacherId) {
            // เช็คสิทธิ์เบื้องต้น (Admin หรือ เจ้าของบัญชี)
            if (!isAdmin && teacherId != currentUserId) {
                showToast('คุณไม่มีสิทธิ์ในการแก้ไขข้อมูลผู้อื่น', 'error');
                return;
            }

            try {
                const response = await fetch(`ajax/teachers_search.php?action=get_teacher&teacher_id=${teacherId}`);
                const result = await response.json();

                if (result.success) {
                    const teacher = result.teacher;

                    document.getElementById('edit_teacher_id').value = teacher.Aj_id;
                    document.getElementById('edit_teacher_id_display').value = teacher.Aj_id;
                    document.getElementById('edit_teacher_name').value = teacher.Aj_name;
                    document.getElementById('edit_teacher_position').value = teacher.Aj_position || '';
                    document.getElementById('edit_unit_id').value = teacher.Unit_id || '';
                    document.getElementById('edit_teacher_tel').value = teacher.Aj_tel || '';
                    document.getElementById('edit_teacher_email').value = teacher.Aj_email || '';
                    document.getElementById('edit_permission').value = teacher.Aj_per || '1';
                    document.getElementById('edit_teacher_password').value = '';

                    // ตั้งค่า hidden field สำหรับ Teacher ที่ไม่มีสิทธิ์เปลี่ยน Permission
                    if (!isAdmin) {
                        document.getElementById('edit_permission_hidden').value = teacher.Aj_per;
                    }

                    // หาก Admin พยายามแก้ตัวเอง ห้ามเปลี่ยน Permission ตัวเอง (เพื่อป้องกัน Locked out)
                    if (isAdmin && teacher.Aj_id == currentUserId) {
                        document.getElementById('edit_permission').disabled = true;
                    } else if (isAdmin) {
                        document.getElementById('edit_permission').disabled = false;
                    }

                    showModal('editTeacherModal');
                } else {
                    showToast('ไม่สามารถโหลดข้อมูลอาจารย์/เจ้าหน้าที่ได้: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Edit teacher error:', error);
                showToast('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
            }
        }

        function showSuspendModal(teacherId, teacherName) {
            if (!canManageUsers) {
                showToast('คุณไม่มีสิทธิ์ในการระงับบัญชีอาจารย์/เจ้าหน้าที่', 'error');
                return;
            }

            document.getElementById('suspend_teacher_id').value = teacherId;
            document.getElementById('suspend_teacher_name_display').textContent = teacherName;
            document.getElementById('suspend_teacher_id_display').textContent = `รหัส: ${teacherId}`;
            document.getElementById('suspend_reason').value = '';

            showModal('suspendTeacherModal');

            // Focus on textarea
            setTimeout(() => {
                document.getElementById('suspend_reason').focus();
            }, 300);
        }

        async function handleSuspendTeacher(e) {
            e.preventDefault();

            if (!canManageUsers) {
                showToast('คุณไม่มีสิทธิ์ในการระงับบัญชีอาจารย์/เจ้าหน้าที่', 'error');
                return;
            }

            const formData = new FormData(e.target);
            const teacherId = formData.get('teacher_id');
            const suspendReason = formData.get('suspend_reason');

            if (!suspendReason.trim()) {
                showToast('กรุณากรอกเหตุผลในการระงับบัญชี', 'error');
                return;
            }

            await toggleTeacherStatus(teacherId, 0, suspendReason.trim());
            closeModal('suspendTeacherModal');
        }

        // Release suspension functions
        async function showReleaseModal(teacherId, teacherName) {
            if (!canManageUsers) {
                showToast('คุณไม่มีสิทธิ์ในการปลดการระงับ', 'error');
                return;
            }

            try {
                // Load current suspension info
                const response = await fetch(`ajax/teachers_search.php?action=get_suspension_info&teacher_id=${teacherId}`);
                const result = await response.json();

                if (result.success) {
                    const suspensionInfo = result.suspension_info;

                    document.getElementById('release_teacher_id').value = teacherId;
                    document.getElementById('release_teacher_name_display').textContent = teacherName;
                    document.getElementById('release_teacher_id_display').textContent = `รหัส: ${teacherId}`;
                    document.getElementById('release_reason').value = '';

                    // Populate suspension details
                    document.getElementById('suspension_date_display').textContent =
                        suspensionInfo.suspend_date_formatted || 'ไม่ระบุ';
                    document.getElementById('suspended_by_display').textContent =
                        suspensionInfo.suspended_by_name || 'ไม่ระบุ';
                    document.getElementById('suspension_reason_display').textContent =
                        suspensionInfo.suspend_reason || 'ไม่ระบุเหตุผล';

                    showModal('releaseTeacherModal');

                    // Focus on textarea
                    setTimeout(() => {
                        document.getElementById('release_reason').focus();
                    }, 300);
                } else {
                    showToast('ไม่สามารถโหลดข้อมูลการระงับได้: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Load suspension info error:', error);
                showToast('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
            }
        }

        async function handleReleaseTeacher(e) {
            e.preventDefault();

            if (!canManageUsers) {
                showToast('คุณไม่มีสิทธิ์ในการปลดการระงับ', 'error');
                return;
            }

            const formData = new FormData(e.target);
            const teacherId = formData.get('teacher_id');
            const releaseReason = formData.get('release_reason');

            await toggleTeacherStatus(teacherId, 1, null, releaseReason.trim());
            closeModal('releaseTeacherModal');
        }

        function deleteTeacherWithConfirm(teacherId, teacherName) {
            if (!canManageUsers) {
                showToast('คุณไม่มีสิทธิ์ในการลบอาจารย์/เจ้าหน้าที่', 'error');
                return;
            }

            // Note: Last Admin Protection logic is handled in the backend (ajax/teachers_crud.php).
            // Frontend assumes that if button is visible, the action is attempted.
            showCustomConfirm(
                `พิมพ์ "DELETE" เพื่อยืนยันการลบอาจารย์/เจ้าหน้าที่: ${teacherName}`,
                'DELETE',
                () => deleteTeacher(teacherId)
            );
        }

        async function deleteTeacher(teacherId) {
            try {
                const response = await fetch('ajax/teachers_crud.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'delete_teacher',
                        teacher_id: teacherId,
                        confirm_delete: 'DELETE'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    await loadTeachers();
                    await loadStats();
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                console.error('Delete teacher error:', error);
                showToast('เกิดข้อผิดพลาดในการลบอาจารย์/เจ้าหน้าที่', 'error');
            }
        }

        async function toggleTeacherStatus(teacherId, newStatus, suspendReason = '', releaseReason = '') {
            try {
                const response = await fetch('ajax/teachers_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'toggle_status',
                        teacher_id: teacherId,
                        new_status: newStatus,
                        suspend_reason: suspendReason,
                        release_reason: releaseReason
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    await loadTeachers();
                    await loadStats();
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                console.error('Toggle status error:', error);
                showToast('เกิดข้อผิดพลาดในการเปลี่ยนสถานะ', 'error');
            }
        }

        // Custom confirm dialog
        function showCustomConfirm(message, confirmText, callback) {
            const confirmDialog = document.createElement('div');
            confirmDialog.className = 'custom-confirm';
            confirmDialog.innerHTML = `
                <div class="confirm-content">
                    <div class="confirm-header">
                        <h3>⚠️ ยืนยันการดำเนินการ</h3>
                    </div>
                    <div class="confirm-body">
                        <p>${message}</p>
                        <input type="text" class="confirm-input" placeholder="พิมพ์ '${confirmText}' เพื่อยืนยัน">
                        <div class="confirm-actions">
                            <button class="btn btn-secondary" onclick="closeCustomConfirm()">ยกเลิก</button>
                            <button class="btn btn-success" onclick="confirmAction()">ยืนยัน</button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(confirmDialog);

            const input = confirmDialog.querySelector('.confirm-input');
            input.focus();

            window.closeCustomConfirm = function() {
                document.body.removeChild(confirmDialog);
                delete window.closeCustomConfirm;
                delete window.confirmAction;
            };

            window.confirmAction = function() {
                if (input.value === confirmText) {
                    callback();
                    window.closeCustomConfirm();
                } else {
                    showToast('กรุณาพิมพ์ข้อความยืนยันให้ถูกต้อง', 'error');
                    input.focus();
                }
            };

            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    window.confirmAction();
                }
            });
        }

        // Utility functions
        function showLoading(show) {
            document.getElementById('loadingIndicator').style.display = show ? 'block' : 'none';
        }

        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            setTimeout(() => {
                const firstInput = modal.querySelector('input, textarea, select, button');
                if (firstInput && firstInput.type !== 'hidden') {
                    firstInput.focus();
                }
            }, 300);
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.display = 'none';
            document.body.style.overflow = '';

            const form = modal.querySelector('form');
            if (form) {
                form.reset();
            }
        }

        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;

            document.body.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('show');
            }, 100);

            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    if (document.body.contains(toast)) {
                        document.body.removeChild(toast);
                    }
                }, 300);
            }, 4000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            });
        }
    </script>
</body>

</html>