# Sistem E-Voting Pemilihan Ketua OSIS Berbasis Jaringan Lokal (LAN)

Aplikasi web pemungutan suara elektronik (*E-Voting*) untuk Pemilihan Ketua & Wakil Ketua OSIS yang berjalan 100% secara lokal (Wi-Fi/LAN) tanpa memerlukan koneksi internet. Menggunakan mekanisme **One-Time Token** (kode sekali pakai) yang menjamin kerahasiaan suara (**LUBER JURDIL**).

---

## Fitur Utama

1. **Web Installer Mandiri (`install.php`):** Pengaturan database, pembuatan tabel otomatis, pengisian identitas sekolah, dan pembuatan akun Admin langsung dari browser tanpa perlu membuka phpMyAdmin. Otomatis mengunci instalasi dengan `installed.lock`.
2. **One-Time Token Generator & Cetak Siap Gunting:** Pembuatan hingga 1.000 token per eksekusi (6 karakter huruf kapital & angka tanpa karakter ambigu `O`/`0` dan `I`/`1`) serta halaman cetak khusus kertas A4.
3. **Pencegahan Double-Submit (Transaksi Atomik):** Validasi dan penghangusan token dilakukan menggunakan transaksi MySQL (`PDO::beginTransaction`) sehingga aman dari *race condition*.
4. **Kerahasiaan Suara (Voter Anonymity):** Tabel `tokens` tidak menyimpan relasi ke kandidat yang dipilih. Panitia dapat memverifikasi kapan token digunakan, namun tidak dapat melacak pilihan pemilih.
5. **CRUD Kandidat & Upload Foto Kustom:** Unggah foto kandidat langsung dari halaman Admin dengan pratinjau langsung (*live preview*), penamaan file kustom/otomatis (*slugify*), dan penghapusan otomatis file foto lama (`unlink`).
6. **Mode Kunci Hasil (*Lock Quick Count*) & Reset Simulasi:** Grafik perolehan suara dapat disembunyikan selama pemungutan suara berlangsung, serta tersedia fitur reset data simulasi yang dilindungi password Admin.
7. **Antarmuka Minimalis (White & Dark Theme):** Dibangun menggunakan *Modular Vanilla CSS* ringan (tanpa CDN/framework berat) dengan pilihan tema **White (Terang)** dan **Dark (Gelap)** yang dapat diubah langsung melalui menu **Pengaturan**.

---

## Spesifikasi Teknis

- **Backend:** PHP Native (Kompatibel PHP 7.4 hingga PHP 8.3+)
- **Database:** MySQL 5.7+ / MariaDB 10.4+ (PDO Prepared Statements)
- **Frontend:** HTML5, Modular Vanilla CSS3 (`assets/css/`), Vanilla JavaScript (`assets/js/`)
- **Server Lokal:** XAMPP, Laragon, WAMP, atau Apache/Nginx

---

## Struktur Direktori

```text
Website_pemilihan_ketuaOsis/
├── assets/
│   ├── css/
│   │   ├── base.css                # Variabel tema (White/Dark), reset, & komponen dasar
│   │   ├── voter-login.css         # Halaman input token pemilih & login admin
│   │   ├── bilik-suara.css         # Kartu kandidat, modal visi-misi, & konfirmasi
│   │   ├── admin-layout.css        # Navigasi sidebar, tabel, & form admin
│   │   ├── admin-dashboard.css     # Kartu statistik & grafik suara (CSS Bar Chart)
│   │   ├── print-token.css         # Cetak kertas token A4 (@media print)
│   │   └── installer.css           # Wizard instalasi mandiri (install.php)
│   ├── js/
│   │   ├── bilik-suara.js          # Interaktivitas modal visi-misi, konfirmasi, & timer
│   │   └── admin.js                # Live preview gambar upload, slug, & tema
│   └── uploads/
│       ├── .gitkeep
│       └── default-candidate.png   # Foto cadangan default kandidat
├── config/
│   ├── config.example.php          # Template konfigurasi database
│   └── database.php                # Koneksi PDO & helper keamanan
├── admin/
│   ├── index.php                   # Dashboard statistik real-time
│   ├── login.php                   # Login panitia
│   ├── kandidat.php                # CRUD kandidat & upload foto kustom
│   ├── token.php                   # Generator & manajemen token
│   ├── cetak_token.php             # Halaman cetak lembar token siap gunting
│   ├── hasil.php                   # Grafik perolehan suara & kunci hasil
│   ├── pengaturan.php              # Pengaturan sekolah, tema White/Dark, & reset simulasi
│   └── logout.php
├── database/
│   └── database.sql                # Dump SQL untuk import manual
├── index.php                       # Halaman depan bilik suara (input token)
├── bilik_suara.php                 # Halaman surat suara & daftar kandidat
├── proses_vote.php                 # Eksekusi transaksi suara & penghangusan token
├── install.php                     # Wizard instalasi otomatis
├── .gitignore
├── LICENSE
└── README.md
```

---

## Panduan Instalasi (XAMPP / Laragon)

### Cara 1: Instalasi Otomatis via Web Installer (Direkomendasikan)

1. Salin atau *clone* repositori ini ke dalam folder web server Anda:
   - **XAMPP:** `C:\xampp\htdocs\E-Voting-OSIS`
   - **Laragon:** `C:\laragon\www\E-Voting-OSIS`
2. Nyalakan modul **Apache** dan **MySQL** pada panel kontrol XAMPP atau Laragon.
3. Buka browser dan akses:
   ```text
   http://localhost/E-Voting-OSIS/install.php
   ```
4. Isi konfigurasi database (`localhost`, user `root`, password kosong), nama sekolah, tema tampilan awal (`White` atau `Dark`), serta username dan password Admin pertama.
5. Klik **Jalankan Instalasi Sekarang**. Sistem akan membuat database, tabel, file `config/config.php`, dan mengunci installer dengan file `installed.lock`.

### Cara 2: Instalasi Manual via phpMyAdmin

1. Buat database baru bernama `db_evoting_osis` di `http://localhost/phpmyadmin`.
2. Import file `database/database.sql`.
3. Salin file `config/config.example.php` menjadi `config/config.php` dan sesuaikan kredensial database jika diperlukan.
4. Buat file kosong bernama `installed.lock` di direktori utama proyek.
5. Login ke halaman Admin (`http://localhost/E-Voting-OSIS/admin/login.php`) menggunakan akun bawaan:
   - **Username:** `admin`
   - **Password:** `admin123`

---

## Panduan Konfigurasi Jaringan Lokal (LAN / Wi-Fi)

Agar perangkat di bilik suara (Smartphone, Tablet, atau Laptop klien) dapat mengakses server panitia tanpa internet:

1. Hubungkan **Laptop Server Panitia** dan seluruh **Perangkat Bilik Suara** ke jaringan Wi-Fi / Router LAN yang sama.
2. Pada Laptop Server (Windows), buka **Command Prompt (CMD)** atau **PowerShell**, lalu jalankan perintah:
   ```powershell
   ipconfig
   ```
3. Cari bagian **Wireless LAN adapter Wi-Fi** atau **Ethernet adapter**, lalu catat baris **IPv4 Address**, misalnya:
   ```text
   IPv4 Address. . . . . . . . . . . : 192.168.1.10
   ```
   *(Alamat IP lokal ini juga ditampilkan langsung di tabel bawah halaman **Dashboard Admin**).*
4. Pada browser perangkat di setiap bilik suara, buka alamat berikut:
   ```text
   http://192.168.1.10/E-Voting-OSIS/
   ```
5. Pastikan **Windows Firewall** pada Laptop Server mengizinkan koneksi masuk untuk port `80` (Apache HTTP Server).

---

## Mengubah Tema Tampilan (White / Dark)

1. Masuk ke panel Administrator (`/admin/login.php`).
2. Buka menu **Pengaturan** (`/admin/pengaturan.php`).
3. Pada kolom **Tema Tampilan Antarmuka (UI)**, pilih **White (Terang / Bersih)** atau **Dark (Gelap / Kontras Rendah Cahaya)**.
4. Klik **Simpan Pengaturan**. Seluruh halaman Admin maupun Bilik Suara akan menggunakan tema yang dipilih.

---

## Lisensi

Proyek ini dilisensikan di bawah [MIT License](LICENSE). Bebas digunakan dan dimodifikasi oleh kepanitiaan OSIS/MPK sekolah mana pun.
