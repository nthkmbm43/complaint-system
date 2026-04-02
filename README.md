# 📋 ระบบจัดการข้อร้องเรียนออนไลน์ (Online Complaint Management System)

> พัฒนาด้วย PHP & MySQL สำหรับมหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน วิทยาเขตขอนแก่น

---

## 📌 เกี่ยวกับโปรเจกต์

ระบบจัดการข้อร้องเรียนออนไลน์สำหรับนักศึกษาและบุคลากรของมหาวิทยาลัย ช่วยให้นักศึกษาสามารถส่งข้อร้องเรียน ติดตามสถานะ และประเมินความพึงพอใจได้อย่างสะดวก รวดเร็ว ขณะที่เจ้าหน้าที่สามารถบริหารจัดการข้อร้องเรียนได้อย่างมีประสิทธิภาพผ่านระบบ Permission 3 ระดับ

🌐 **Live Demo:** [complaint-student.great-site.net](https://complaint-student.great-site.net/index.php?i=1)

---

## ✨ ฟีเจอร์หลัก

### 👨‍🎓 ฝั่งนักศึกษา
- **ส่งข้อร้องเรียน** — แนบไฟล์รูปภาพและเอกสารประกอบได้
- **ติดตามสถานะ** — ค้นหาด้วย Fuzzy Search รองรับการค้นหาชื่อ รหัสร้องเรียน และเนื้อหา
- **ประเมินความพึงพอใจ** — ให้คะแนนหลังดำเนินการเสร็จสิ้น
- **จัดการข้อมูลส่วนตัว** — แก้ไขข้อมูลและเปลี่ยนรหัสผ่าน
- **ระบบ Anonymous** — ส่งข้อร้องเรียนแบบไม่ระบุตัวตนได้

### 👨‍💼 ฝั่งเจ้าหน้าที่ (3 ระดับสิทธิ์)

| ระดับ | บทบาท | สิทธิ์ |
|---|---|---|
| 1 | อาจารย์/เจ้าหน้าที่ | บันทึกผลการดำเนินงาน, ดูรายงาน |
| 2 | ผู้ดำเนินการ | จัดการข้อร้องเรียน, มอบหมายงาน, จัดการนักศึกษา |
| 3 | ผู้ดูแลระบบ | จัดการข้อมูลพื้นฐานทั้งหมด, จัดการผู้ใช้ทุกระดับ |

### ⚙️ ระบบหลัก
- **Dashboard** — สรุปสถิติข้อร้องเรียนแบบ Real-time
- **จัดการความสำคัญ** — แบ่งระดับ 5 ระดับ (ปกติ → วิกฤต/ฉุกเฉิน)
- **ระบบแจ้งเตือนอีเมล** — ส่งอีเมลอัตโนมัติผ่าน PHPMailer เมื่อสถานะเปลี่ยนแปลง
- **Export PDF** — ออกรายงานเป็น PDF ด้วย TCPDF รองรับภาษาไทย (TH Sarabun)
- **รายงาน** — สรุปสถิติและการดำเนินงาน
- **ระบบ Access Control** — ตรวจสอบสิทธิ์แบบ Real-time ทุก Navigation

---

## 🛠️ เทคโนโลยีที่ใช้

| Category | Technology |
|---|---|
| Backend | PHP 8.x |
| Database | MySQL |
| Frontend | HTML5, CSS3, JavaScript (Vanilla) |
| PDF Generation | TCPDF (รองรับ TH Sarabun / Sarabun) |
| Email | PHPMailer (SMTP) |
| Hosting | InfinityFree |
| Server | Apache (.htaccess) |

---

## 📁 โครงสร้างโปรเจกต์

```
htdocs/
├── api/                    # REST API endpoints
├── assets/                 # CSS และ JavaScript
├── config/                 # ไฟล์ตั้งค่า (ไม่รวมใน repo)
├── includes/               # Shared components (header, sidebar, footer, auth)
├── staff/                  # หน้าเว็บฝั่งเจ้าหน้าที่
│   └── ajax/               # AJAX handlers
├── students/               # หน้าเว็บฝั่งนักศึกษา
├── tcpdf/                  # PDF library พร้อม Thai fonts
├── vendor/PHPMailer/       # Email library
└── index.php               # Landing page
```

---

## ⚙️ การติดตั้ง

1. **Clone repository**
```bash
git clone https://github.com/nthkmbm43/complaint-system.git
```

2. **ตั้งค่าฐานข้อมูล**
```bash
cp htdocs/config/config.example.php htdocs/config/config.php
```
แก้ไขค่าใน `config.php` ให้ตรงกับ server ของคุณ

3. **Import Database**
   - สร้างฐานข้อมูลชื่อ `complaint_system`
   - Import SQL schema (ติดต่อผู้พัฒนาเพื่อขอไฟล์)

4. **ตั้งค่า Web Server**
   - วางโฟลเดอร์ `htdocs/` ไว้ใน document root ของ Apache
   - เปิดใช้งาน `mod_rewrite`

---

## 🔐 ความปลอดภัย

- ไฟล์ config ที่มีข้อมูลสำคัญถูกแยกออกจาก repository (`.gitignore`)
- ป้องกันการเข้าถึงโดยตรง (`SECURE_ACCESS` constant)
- ปิดการแสดงรายชื่อไฟล์ใน Directory (`Options -Indexes`)
- ระบบ Session-based Authentication
- ตรวจสอบสิทธิ์ทุก request

---

## 👨‍💻 ผู้พัฒนา

**Thanakrit Muubaanmuang (ธนกฤต หมู่บ้านม่วง)**

นักศึกษาสาขาเทคโนโลยีธุรกิจดิจิทัล มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน วิทยาเขตขอนแก่น  
รับผิดชอบในฐานะ **System Analyst** และ **ผู้ทดสอบระบบ (QA)** ของโปรเจกต์นี้

| | |
|---|---|
| 📧 Email | nthkmbm43@gmail.com |
| 📱 Phone | 094-909-9502 |
| 🐙 GitHub | [github.com/nthkmbm43](https://github.com/nthkmbm43) |
| 🌐 Live Demo | [complaint-student.great-site.net](https://complaint-student.great-site.net/index.php?i=1) |

---

> โปรเจกต์นี้พัฒนาเพื่อการศึกษาและใช้งานจริงภายในมหาวิทยาลัย
