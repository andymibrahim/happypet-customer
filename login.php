<?php
require_once __DIR__ . '/config/database.php';

if (!empty($_SESSION['happypet_user_id'])) {
    header('Location: index.php');
    exit;
}

$db = HappyPetDB::getInstance();
$error = '';
$success = '';
$activeTab = 'login';

if (isset($_GET['logged_out'])) {
    $success = 'Anda telah berhasil keluar dari sesi HappyPet.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['auth_action'] ?? 'login';

    if ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $user = $db->findUserByEmail($email);

        if ($user && password_verify($password, $user['password'])) {
            if (($user['role'] ?? 'customer') === 'admin') {
                $error = 'Akun Administrator silakan masuk melalui website Panel Admin (happypet-admin).';
            } else {
                $_SESSION['happypet_user_id'] = $user['id'];
                $_SESSION['happypet_flash'] = 'Selamat datang kembali di HappyPet, ' . $user['name'] . '!';
                $db->logActivity(
                    (int)$user['id'],
                    'login',
                    'User berhasil masuk ke akun HappyPet.',
                    'auth',
                    (string)$user['id'],
                    ['email' => $user['email']],
                    $user
                );
                header('Location: index.php');
                exit;
            }
        } else {
            $db->logActivity(
                null,
                'login_failed',
                'Percobaan login gagal untuk email: ' . ($email ?: '(kosong)'),
                'auth',
                null,
                ['email' => $email]
            );
            $error = 'Email atau kata sandi tidak sesuai. Gunakan tombol "Isi Akun Demo" di bawah untuk mencoba langsung.';
        }
    } elseif ($action === 'register') {
        $activeTab = 'register';
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($name === '' || $email === '' || $password === '') {
            $error = 'Mohon lengkapi nama lengkap, email, dan kata sandi.';
        } elseif ($db->findUserByEmail($email)) {
            $error = 'Email tersebut sudah terdaftar. Silakan masuk menggunakan akun Anda.';
        } else {
            $newUser = $db->createUser([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'password' => $password,
                'avatar' => 'cat-sage'
            ]);
            $db->logActivity(
                (int)$newUser['id'],
                'register',
                'Pendaftaran akun baru berhasil atas nama ' . $newUser['name'] . '.',
                'auth',
                (string)$newUser['id'],
                ['email' => $newUser['email'], 'phone' => $phone],
                $newUser
            );
            $_SESSION['happypet_user_id'] = $newUser['id'];
            $_SESSION['happypet_flash'] = 'Akun berhasil dibuat! Selamat datang di HappyPet, ' . $newUser['name'] . '!';
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk & Daftar — HappyPet Luxury Pet Hotel & Care</title>
    <link rel="icon" type="image/png" href="assets/img/happypet-paw-badge-transparent.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <style>
        .auth-wrapper {
            min-height: 100vh;
            background-color: var(--bg-alabaster);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
            background-image:
                radial-gradient(at 10% 15%, rgba(132, 169, 140, 0.16) 0px, transparent 45%),
                radial-gradient(at 90% 85%, rgba(230, 204, 178, 0.35) 0px, transparent 50%);
        }
        .auth-container {
            width: 100%;
            max-width: 1040px;
            background: #FFFFFF;
            border-radius: 28px;
            overflow: hidden;
            display: grid;
            grid-template-columns: 1.08fr 1fr;
            box-shadow: 0 24px 60px rgba(53, 79, 82, 0.14);
            border: 1px solid var(--sand-secondary);
        }
        .auth-showcase {
            background: linear-gradient(150deg, var(--forest-cta) 0%, #3E5D60 55%, var(--sage-primary) 100%);
            color: #FFFFFF;
            padding: 46px 42px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        .auth-showcase::before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(230, 204, 178, 0.12);
            top: -80px;
            right: -80px;
        }
        .auth-form-side {
            padding: 44px 42px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #FFFFFF;
        }
        .auth-tabs {
            display: flex;
            background: var(--bg-alabaster);
            padding: 5px;
            border-radius: 13px;
            border: 1px solid var(--border-subtle);
            margin-bottom: 24px;
        }
        .auth-tab-btn {
            flex: 1;
            padding: 10px;
            border: none;
            background: transparent;
            border-radius: 10px;
            font-weight: 700;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .auth-tab-btn.active {
            background: var(--forest-cta);
            color: #FFFFFF;
            box-shadow: 0 4px 12px rgba(53, 79, 82, 0.2);
        }
        .demo-box {
            margin-top: 20px;
            background: var(--sand-light);
            border: 1.5px dashed var(--amber-cta);
            border-radius: 14px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .feature-bullet {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.92);
        }
        .feature-bullet i {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: rgba(230, 204, 178, 0.2);
            color: var(--sand-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        @media (max-width: 860px) {
            .auth-container {
                grid-template-columns: 1fr;
            }
            .auth-showcase {
                padding: 32px 28px;
            }
            .auth-form-side {
                padding: 32px 28px;
            }
        }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-container">
            <!-- Left Showcase Side -->
            <div class="auth-showcase">
                <div>
                    <div style="display:flex; align-items:center; gap:14px; margin-bottom:32px;">
                        <img src="assets/img/happypet-paw-badge-transparent.png" alt="HappyPet Paw Logo" style="width:48px; height:48px; object-fit:contain; filter:drop-shadow(0 4px 10px rgba(0,0,0,0.22)); flex-shrink:0;">
                        <div>
                            <div class="brand-title" style="font-size:26px;">HappyPet</div>
                            <div class="brand-subtitle">Luxury Pet Hotel, Spa & Care</div>
                        </div>
                    </div>

                    <span class="hero-pill">
                        <i class="fa-solid fa-shield-heart"></i> Standar Sanitasi Medis & Pengawasan 24/7
                    </span>

                    <h1 style="color:#FFFFFF; font-size:31px; line-height:1.25; margin-bottom:16px;">
                        Kenyamanan Setara Resort Bintang Lima untuk Anabul Tercinta.
                    </h1>
                    <p style="color:rgba(248,250,252,0.86); font-size:14.5px; margin-bottom:28px;">
                        Pesan kamar penitipan ber-AC HEPA filter, pantau lewat Live CCTV 24 jam, bayar instan dengan <strong>Midtrans Snap</strong>, dan cetak struk resmi kapan saja.
                    </p>

                    <div>
                        <div class="feature-bullet">
                            <i class="fa-solid fa-spa"></i>
                            <span>Kabin Steril bernuansa <strong>Sage Green</strong> yang menenangkan hewan</span>
                        </div>
                        <div class="feature-bullet">
                            <i class="fa-solid fa-credit-card"></i>
                            <span>Pembayaran otomatis terintegrasi <strong>Midtrans Payment Gateway</strong></span>
                        </div>
                        <div class="feature-bullet">
                            <i class="fa-solid fa-receipt"></i>
                            <span>Riwayat transaksi transparan & fitur <strong>Cetak Struk Resmi</strong></span>
                        </div>
                    </div>
                </div>

                <!-- Care Assurance Footer -->
                <div style="background:rgba(0,0,0,0.2); padding:14px 18px; border-radius:14px; display:flex; align-items:center; justify-content:space-between;">
                    <span style="font-size:12px; color:var(--sand-secondary); font-weight:600;">
                        <i class="fa-solid fa-user-doctor"></i> Tim Dokter Hewan &amp; Pet Sitter Siaga 24 Jam
                    </span>
                    <span style="font-size:11.5px; color:#FFFFFF; font-weight:700;">
                        HappyPet Care
                    </span>
                </div>
            </div>

            <!-- Right Form Side -->
            <div class="auth-form-side">
                <div style="margin-bottom:20px;">
                    <h2 style="font-size:24px; margin-bottom:4px;">Selamat Datang di HappyPet</h2>
                    <p style="font-size:13.5px; color:var(--text-muted);">Masuk ke dashboard pelanggan untuk memesan penitipan hewan.</p>
                </div>

                <?php if ($error): ?>
                    <div style="background:var(--danger-bg); color:var(--danger-text); padding:12px 16px; border-radius:12px; font-size:13px; font-weight:600; margin-bottom:18px; border:1px solid #F5C2C7;">
                        <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div style="background:var(--success-bg); color:var(--success-text); padding:12px 16px; border-radius:12px; font-size:13px; font-weight:600; margin-bottom:18px;">
                        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <div class="auth-tabs">
                    <button type="button" class="auth-tab-btn <?= $activeTab === 'login' ? 'active' : '' ?>" onclick="switchAuthTab('login')" id="tabBtnLogin">
                        <i class="fa-solid fa-right-to-bracket"></i> Masuk Akun
                    </button>
                    <button type="button" class="auth-tab-btn <?= $activeTab === 'register' ? 'active' : '' ?>" onclick="switchAuthTab('register')" id="tabBtnRegister">
                        <i class="fa-solid fa-user-plus"></i> Daftar Baru
                    </button>
                </div>

                <!-- LOGIN FORM -->
                <form method="POST" action="login.php" id="loginForm" style="display: <?= $activeTab === 'login' ? 'block' : 'none' ?>;">
                    <input type="hidden" name="auth_action" value="login">
                    <div class="form-group">
                        <label class="form-label">Alamat Email</label>
                        <input type="email" name="email" id="loginEmail" class="form-control" value="nadya@happypet.id" required placeholder="nama@email.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kata Sandi</label>
                        <input type="password" name="password" id="loginPassword" class="form-control" value="password123" required placeholder="••••••••">
                    </div>
                    <button type="submit" class="btn btn-forest" style="width:100%; padding:13px; font-size:15px; margin-top:6px;">
                        <i class="fa-solid fa-paw"></i> Masuk ke Dashboard HappyPet
                    </button>

                    <div class="demo-box">
                        <div>
                            <div style="font-size:12.5px; font-weight:800; color:var(--forest-cta);">
                                <i class="fa-solid fa-wand-magic-sparkles" style="color:var(--amber-cta);"></i> Akun Demo Siap Pakai
                            </div>
                            <div style="font-size:12px; color:var(--text-muted);">
                                Email: <code>nadya@happypet.id</code> &bull; Sandi: <code>password123</code>
                            </div>
                        </div>
                        <button type="button" class="btn btn-amber btn-sm" onclick="fillDemoAccount()">
                            Isi & Masuk
                        </button>
                    </div>
                </form>

                <!-- REGISTER FORM -->
                <form method="POST" action="login.php" id="registerForm" style="display: <?= $activeTab === 'register' ? 'block' : 'none' ?>;">
                    <input type="hidden" name="auth_action" value="register">
                    <div class="form-group">
                        <label class="form-label">Nama Lengkap Pemilik Hewan</label>
                        <input type="text" name="name" class="form-control" required placeholder="Contoh: Rina Wijaya">
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Email Aktif</label>
                            <input type="email" name="email" class="form-control" required placeholder="rina@email.com">
                        </div>
                        <div class="form-group">
                            <label class="form-label">No. WhatsApp</label>
                            <input type="text" name="phone" class="form-control" required placeholder="0812-xxxx-xxxx">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Alamat Penjemputan / Rumah</label>
                        <input type="text" name="address" class="form-control" placeholder="Jl. Melati Indah No. 10, Jakarta">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Buat Kata Sandi</label>
                        <input type="password" name="password" class="form-control" required placeholder="Minimal 6 karakter">
                    </div>
                    <button type="submit" class="btn btn-amber" style="width:100%; padding:13px; font-size:15px;">
                        <i class="fa-solid fa-user-check"></i> Buat Akun & Masuk
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function switchAuthTab(tab) {
            document.getElementById('loginForm').style.display = (tab === 'login') ? 'block' : 'none';
            document.getElementById('registerForm').style.display = (tab === 'register') ? 'block' : 'none';
            document.getElementById('tabBtnLogin').classList.toggle('active', tab === 'login');
            document.getElementById('tabBtnRegister').classList.toggle('active', tab === 'register');
        }
        function fillDemoAccount() {
            document.getElementById('loginEmail').value = 'nadya@happypet.id';
            document.getElementById('loginPassword').value = 'password123';
            document.getElementById('loginForm').submit();
        }
    </script>
</body>
</html>
