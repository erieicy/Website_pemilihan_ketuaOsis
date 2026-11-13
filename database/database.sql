-- ==========================================================
-- Skema Database: Sistem E-Voting Pemilihan Ketua OSIS (LAN)
-- Database Default: db_evoting_osis
-- ==========================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+07:00";

-- ----------------------------------------------------------
-- 1. Tabel `pengaturan`
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pengaturan` (
  `id` int(11) NOT NULL,
  `nama_sekolah` varchar(150) NOT NULL DEFAULT 'SMA Negeri 1',
  `periode` varchar(20) NOT NULL DEFAULT '2026/2027',
  `status_voting` enum('buka','tutup') NOT NULL DEFAULT 'buka',
  `tampilkan_hasil` tinyint(1) NOT NULL DEFAULT 0,
  `tema` enum('light','dark') NOT NULL DEFAULT 'light',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pengaturan` (`id`, `nama_sekolah`, `periode`, `status_voting`, `tampilkan_hasil`, `tema`)
VALUES (1, 'SMA Negeri 1', '2026/2027', 'buka', 0, 'light')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ----------------------------------------------------------
-- 2. Tabel `admin`
-- Akun Default: username = admin | password = admin123
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nama_lengkap` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admin` (`id`, `username`, `password`, `nama_lengkap`)
VALUES (1, 'admin', '$2y$10$./2KgU4vulAyPsJN4v21IOqvYEMC8LipkvV9oVX.YTMAeJV.XXacu', 'Panitia Pemilihan (MPK)')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ----------------------------------------------------------
-- 3. Tabel `kandidat`
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kandidat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `no_urut` int(3) NOT NULL,
  `nama_ketua` varchar(100) NOT NULL,
  `nama_wakil` varchar(100) DEFAULT '',
  `nama_file_foto` varchar(255) NOT NULL DEFAULT 'default-candidate.png',
  `visi` text NOT NULL,
  `misi` text NOT NULL,
  `jumlah_suara` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_no_urut` (`no_urut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. Tabel `tokens`
-- Tanpa Foreign Key ke `kandidat` demi menjamin kerahasiaan suara
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kode_token` varchar(10) NOT NULL,
  `sudah_dipakai` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_kode_token` (`kode_token`),
  KEY `idx_sudah_dipakai` (`sudah_dipakai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
