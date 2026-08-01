-- DSM 7 新站点初始数据库结构。
-- 仅用于空白数据库。

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `wd_spk` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `displayname` varchar(200) NOT NULL DEFAULT '',
    `package` varchar(200) NOT NULL,
    `version` varchar(255) NOT NULL,
    `arch` varchar(200) NOT NULL DEFAULT '',
    `os_min_ver` varchar(60) NOT NULL,
    `beta` tinyint(1) unsigned NOT NULL DEFAULT '0',
    `spk` varchar(300) NOT NULL,
    `filesize` bigint unsigned NOT NULL DEFAULT '0',
    `md5` char(32) NOT NULL DEFAULT '',
    `filemtime` bigint unsigned NOT NULL DEFAULT '0',
    `params` longtext NOT NULL,
    `create_time` bigint unsigned NOT NULL DEFAULT '0',
    `update_time` bigint unsigned DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_spk` (`spk`(191)),
    KEY `idx_package` (`package`(191)),
    KEY `idx_filter` (`beta`, `os_min_ver`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
