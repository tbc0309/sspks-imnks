-- DSM 7 SQLite3 套件索引结构。
-- 应用首次启动时会自动执行等价结构；本文件仅用于检查或手工初始化。

PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS "wd_spk" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "displayname" TEXT NOT NULL DEFAULT '',
    "package" TEXT NOT NULL,
    "version" TEXT NOT NULL,
    "arch" TEXT NOT NULL DEFAULT '',
    "os_min_ver" TEXT NOT NULL,
    "beta" INTEGER NOT NULL DEFAULT 0,
    "spk" TEXT NOT NULL UNIQUE,
    "filesize" INTEGER NOT NULL DEFAULT 0,
    "md5" TEXT NOT NULL DEFAULT '',
    "filemtime" INTEGER NOT NULL DEFAULT 0,
    "params" TEXT NOT NULL,
    "create_time" INTEGER NOT NULL DEFAULT 0,
    "update_time" INTEGER DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS "idx_wd_spk_package" ON "wd_spk" ("package");
CREATE INDEX IF NOT EXISTS "idx_wd_spk_filter" ON "wd_spk" ("beta", "os_min_ver");
