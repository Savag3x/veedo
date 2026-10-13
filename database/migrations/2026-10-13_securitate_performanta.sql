-- =====================================================================
-- VEEDO.RO — Migrare: corecturi de schemă + indexuri de performanță
-- Rulează O SINGURĂ DATĂ (phpMyAdmin → SQL, sau: mysql veedo1_site < fisier.sql)
-- Fă backup înainte. Compatibil MySQL 8 / MariaDB 10.5+.
-- Dacă o instrucțiune dă "Duplicate key name" / "Can't DROP", poți ignora eroarea
-- (înseamnă că modificarea există deja) și rula restul.
-- =====================================================================

-- ---------- 1. Corecturi de schemă (bug-uri funcționale) ----------

-- Mesajele trimise din profilul unui utilizator nu au anunț asociat;
-- coloana era NOT NULL, așa că „Trimite mesaj" din profil dădea eroare.
ALTER TABLE `conversations` MODIFY `ad_id` int NULL;

-- api/chat.php salvează imagini în chat, dar coloana lipsea (eroare la trimiterea pozelor).
ALTER TABLE `messages` ADD COLUMN `image_path` varchar(255) NULL DEFAULT NULL AFTER `body`;

-- ---------- 2. Indexuri noi (interogările cele mai frecvente) ----------

-- Listări: status + categorie (homepage, /cauta, similare)
ALTER TABLE `ads` ADD INDEX `idx_status_cat_boost` (`status`, `category_id`, `is_boosted`);
-- „Anunțurile mele", profil public, limită anunțuri active
ALTER TABLE `ads` ADD INDEX `idx_user_status` (`user_id`, `status`);
-- Căutare după oraș
ALTER TABLE `ads` ADD INDEX `idx_status_city` (`status`, `city`);

-- Contorizare vizualizări unice (verificare la fiecare afișare a unui anunț)
ALTER TABLE `ad_views` ADD INDEX `idx_ad_ip_date` (`ad_id`, `ip_hash`, `viewed_at`);
-- Recomandări: istoricul unui utilizator
ALTER TABLE `ad_views` ADD INDEX `idx_user_date` (`user_id`, `viewed_at`);

-- Comentarii unui anunț (doar aprobate, de prim nivel / răspunsuri)
ALTER TABLE `ad_comments` ADD INDEX `idx_ad_parent_appr` (`ad_id`, `parent_id`, `is_approved`, `created_at`);

-- Lista de notificări a unui utilizator (sortată după dată)
ALTER TABLE `notifications` ADD INDEX `idx_user_created` (`user_id`, `created_at`);

-- Stories active per utilizator
ALTER TABLE `stories` ADD INDEX `idx_user_active_exp` (`user_id`, `is_active`, `expires_at`);

-- Recenzii afișate pe profil / anunț
ALTER TABLE `reviews` ADD INDEX `idx_reviewed_appr` (`reviewed_id`, `is_approved`, `created_at`);

-- Mesaje necitite dintr-o conversație
ALTER TABLE `messages` ADD INDEX `idx_conv_sender_read` (`conversation_id`, `sender_id`, `is_read`);

-- Conversații sortate după ultimul mesaj
ALTER TABLE `conversations` ADD INDEX `idx_buyer_last` (`buyer_id`, `last_message_at`);
ALTER TABLE `conversations` ADD INDEX `idx_seller_last` (`seller_id`, `last_message_at`);

-- ---------- 3. Indexuri duplicate (încetinesc scrierile, nu ajută la citire) ----------
-- Fiecare dublează un index UNIQUE existent pe aceeași coloană.
ALTER TABLE `ads`        DROP INDEX `idx_slug`;
ALTER TABLE `categories` DROP INDEX `idx_slug`;
ALTER TABLE `users`      DROP INDEX `idx_email`;
-- idx_status este acoperit de idx_status_boosted / idx_listing_main (același prefix)
ALTER TABLE `ads`        DROP INDEX `idx_status`;

-- ---------- 4. Setări noi ----------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `label`, `type`)
VALUES ('smtp_insecure', '0', 'email', 'SMTP: acceptă certificat self-signed (nerecomandat)', 'boolean');

-- ---------- 5. Design 2027 ----------
-- Tema implicită devine „dark", iar culorile din Admin → Aspect corespund noii palete
-- (principală = accent volt, secundară = violet). Le poți schimba oricând din Admin.
UPDATE `settings` SET `setting_value`='dark'    WHERE `setting_key`='site_theme_default';
UPDATE `settings` SET `setting_value`='#D4FF3F' WHERE `setting_key`='site_color_primary';
UPDATE `settings` SET `setting_value`='#9B86FF' WHERE `setting_key`='site_color_secondary';
