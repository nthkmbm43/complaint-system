<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Footer with Social Icons</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="footer-content">
            <div class="footer-section">
                <div class="footer-logo">
                    <div class="footer-logo-icon">🎓</div>
                    <div class="footer-logo-text">
                        <h3>มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน</h3>
                        <p>วิทยาเขตขอนแก่น</p>
                    </div>
                </div>
                <p class="footer-description">
                    ระบบข้อร้องเรียนนักศึกษาออนไลน์ เพื่อให้บริการที่รวดเร็ว โปร่งใส และมีประสิทธิภาพ
                </p>
            </div>

            <div class="footer-section">
                <h4>เมนูด่วน</h4>
                <ul class="footer-links">
                    <li><a href="../index.php">หน้าหลัก</a></li>
                    <li><a href="students/login.php">เข้าสู่ระบบนักศึกษา</a></li>
                    <li><a href="students/complaint.php">ส่งข้อร้องเรียน</a></li>
                    <li><a href="tracking.php">ติดตามสถานะ</a></li>
                </ul>
            </div>

            <div class="footer-section">
                <h4>ติดต่อเรา</h4>
                <ul class="footer-contact">
                    <li>
                        <span class="contact-icon">📍</span>
                        <span>มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน วิทยาเขตขอนแก่น 150 ถ.ศรีจันทร์ ต.ในเมือง อ.เมือง จ.ขอนแก่น Khon Kaen, Thailand, Khon Kaen 40000</span>
                    </li>
                    <li>
                        <span class="contact-icon">📞</span>
                        <span>043-283-700</span>
                    </li>
                    <li>
                        <span class="contact-icon">📧</span>
                        <span>kkcprrmuti@gmail.com</span>
                    </li>
                    <li>
                        <span class="contact-icon">🌐</span>
                        <span>www.rmuti.ac.th</span>
                    </li>
                </ul>
            </div>

            <div class="footer-section">
                <h4>ข้อมูลระบบ</h4>
                <ul class="footer-info">
                    <li>
                        <span class="info-icon">⏰</span>
                        <span>เวลาปัจจุบัน: <span id="currentTime"></span></span>
                    </li>
                    <li>
                        <span class="info-icon">👥</span>
                        <span>ผู้ใช้ออนไลน์: <span id="onlineUsers">1</span> คน</span>
                    </li>
                    <li>
                        <span class="info-icon">📊</span>
                        <span>เวอร์ชัน: 1.0.0</span>
                    </li>
                    <li>
                        <span class="info-icon">🔧</span>
                        <span>อัพเดตล่าสุด: 23 ก.ค. 2568</span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="footer-copyright">
                <p>&copy; 2025 มหาวิทยาลัยเทคโนโลยีราจมงคลอีสาน วิทยาเขตขอนแก่น</p>
                <p>พัฒนาโดย ภาควิชาเทคโนโลยีสารสนเทศ</p>
            </div>

            <div class="footer-social">
                <a href="https://www.facebook.com/Rmutikhonkaen" target="_blank" class="social-link facebook" title="Facebook">
                    <i class="fab fa-facebook-f"></i>
                </a>
                <a href="https://m.me/2069153226707289" target="_blank" class="social-link messenger" title="Messenger">
                    <i class="fab fa-facebook-messenger"></i>
                </a>
                <a href="https://line.me/R/ti/p/@935lxpwg" target="_blank" class="social-link line" title="Line">
                    <i class="fab fa-line"></i>
                </a>
                <a href="mailto:info@rmuti.ac.th" class="social-link google" title="Gmail">
                    <i class="fab fa-google"></i>
                </a>
                <a href="https://www.youtube.com/@kkcprrmuti3916" target="_blank" class="social-link youtube" title="YouTube">
                    <i class="fab fa-youtube"></i>
                </a>
                <a href="https://ess-register.rmuti.ac.th/AppKK/announce" target="_blank" class="social-link website" title="เว็บไซต์หลัก">
                    <i class="fas fa-globe"></i>
                </a>
            </div>
        </div>

        <!-- Back to Top Button -->
        <button class="back-to-top" id="backToTop" onclick="scrollToTop()" title="กลับไปด้านบน">
            <i class="fas fa-chevron-up"></i>
        </button>
    </footer>

    <style>
        /* Footer Styles */
        .main-footer {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            margin-top: 50px;
            position: relative;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            padding: 50px 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .footer-section h4 {
            color: white;
            margin-bottom: 20px;
            font-size: 1.2rem;
            position: relative;
            padding-bottom: 10px;
        }

        .footer-section h4::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 30px;
            height: 2px;
            background: rgba(255, 255, 255, 0.5);
        }

        /* Footer Logo */
        .footer-logo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .footer-logo-icon {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            flex-shrink: 0;
        }

        .footer-logo-text h3 {
            margin: 0 0 5px 0;
            font-size: 1.1rem;
            line-height: 1.3;
        }

        .footer-logo-text p {
            margin: 0;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .footer-description {
            opacity: 0.9;
            line-height: 1.6;
            margin-bottom: 0;
        }

        /* Footer Links */
        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-links li {
            margin-bottom: 12px;
        }

        .footer-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
            position: relative;
        }

        .footer-links a::before {
            content: '▶';
            margin-right: 8px;
            font-size: 0.7rem;
            opacity: 0;
            transition: all 0.3s ease;
        }

        .footer-links a:hover {
            color: white;
            transform: translateX(5px);
        }

        .footer-links a:hover::before {
            opacity: 1;
        }

        /* Footer Contact */
        .footer-contact {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-contact li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 15px;
            opacity: 0.9;
        }

        .contact-icon {
            font-size: 1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* Footer Info */
        .footer-info {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-info li {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .info-icon {
            font-size: 1rem;
            flex-shrink: 0;
        }

        /* Footer Bottom */
        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        .footer-copyright {
            flex: 1;
        }

        .footer-copyright p {
            margin: 0;
            opacity: 0.8;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        /* Social Media Icons */
        .footer-social {
            display: flex;
            gap: 12px;
        }

        .social-link {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .social-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            transition: all 0.3s ease;
            border-radius: 50%;
            z-index: 0;
        }

        .social-link i {
            position: relative;
            z-index: 1;
        }

        .social-link:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        /* Individual Social Media Colors */
        .social-link.facebook::before {
            background: linear-gradient(135deg, #3b5998, #8b9dc3);
        }

        .social-link.facebook:hover::before {
            background: linear-gradient(135deg, #3b5998, #2d4373);
        }

        .social-link.messenger::before {
            background: linear-gradient(135deg, #0084ff, #44bec7);
        }

        .social-link.messenger:hover::before {
            background: linear-gradient(135deg, #0084ff, #006dcc);
        }

        .social-link.line::before {
            background: linear-gradient(135deg, #00c300, #00b900);
        }

        .social-link.line:hover::before {
            background: linear-gradient(135deg, #00c300, #009a00);
        }

        .social-link.google::before {
            background: linear-gradient(135deg, #dd4b39, #ea4335);
        }

        .social-link.google:hover::before {
            background: linear-gradient(135deg, #dd4b39, #c23321);
        }

        .social-link.youtube::before {
            background: linear-gradient(135deg, #ff0000, #cc0000);
        }

        .social-link.youtube:hover::before {
            background: linear-gradient(135deg, #ff0000, #b30000);
        }

        .social-link.website::before {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .social-link.website:hover::before {
            background: linear-gradient(135deg, #5a6fd8, #6b47a0);
        }

        /* Back to Top Button */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 50%;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            opacity: 0;
            visibility: hidden;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .back-to-top.show {
            opacity: 1;
            visibility: visible;
        }

        .back-to-top:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .footer-content {
                grid-template-columns: 1fr;
                gap: 30px;
                padding: 30px 20px;
            }

            .footer-bottom {
                flex-direction: column;
                gap: 20px;
                text-align: center;
                padding: 20px;
            }

            .footer-social {
                justify-content: center;
            }

            .footer-logo {
                justify-content: center;
                text-align: center;
            }

            .footer-logo-text {
                text-align: left;
            }

            .back-to-top {
                bottom: 20px;
                right: 20px;
                width: 45px;
                height: 45px;
                font-size: 1.1rem;
            }

            .social-link {
                width: 40px;
                height: 40px;
                font-size: 1.1rem;
            }
        }

        @media (max-width: 480px) {
            .footer-content {
                padding: 25px 15px;
            }

            .footer-section h4 {
                font-size: 1.1rem;
            }

            .footer-contact li,
            .footer-info li {
                font-size: 0.85rem;
            }

            .social-link {
                width: 38px;
                height: 38px;
                font-size: 1rem;
            }

            .footer-social {
                gap: 10px;
            }
        }

        /* Demo content for testing */
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }

        .demo-content {
            padding: 50px 20px;
            text-align: center;
            background: white;
            margin: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
    </style>

    <script>
        // Update current time
        function updateCurrentTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('th-TH', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            const dateString = now.toLocaleDateString('th-TH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });

            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = `${dateString} ${timeString}`;
            }
        }

        // Update online users (simulation)
        function updateOnlineUsers() {
            const onlineElement = document.getElementById('onlineUsers');
            if (onlineElement) {
                const randomUsers = Math.floor(Math.random() * 10) + 1;
                onlineElement.textContent = randomUsers;
            }
        }

        // Scroll to top function
        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // Show/hide back to top button
        function handleBackToTopButton() {
            const backToTopBtn = document.getElementById('backToTop');
            if (backToTopBtn) {
                if (window.pageYOffset > 300) {
                    backToTopBtn.classList.add('show');
                } else {
                    backToTopBtn.classList.remove('show');
                }
            }
        }

        // Initialize footer functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Update time immediately and then every second
            updateCurrentTime();
            setInterval(updateCurrentTime, 1000);

            // Update online users every 30 seconds
            updateOnlineUsers();
            setInterval(updateOnlineUsers, 30000);

            // Handle scroll events for back to top button
            window.addEventListener('scroll', handleBackToTopButton);

            // Initialize back to top button state
            handleBackToTopButton();
        });

        // Add smooth scrolling for all anchor links
        document.addEventListener('DOMContentLoaded', function() {
            const links = document.querySelectorAll('a[href^="#"]');
            links.forEach(link => {
                link.addEventListener('click', function(e) {
                    const href = this.getAttribute('href');
                    if (href !== '#') {
                        e.preventDefault();
                        const target = document.querySelector(href);
                        if (target) {
                            target.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                        }
                    }
                });
            });
        });
    </script>

</body>

</html>