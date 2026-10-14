-- Veedo.ro — trecere la tema luminoasă profesională (implicită).
-- Rulează-l doar dacă ai rulat deja migrarea din 2026-10-13 cu tema întunecată.
-- Poți reveni oricând la tema întunecată din Admin → Personalizare → Temă.
UPDATE `settings` SET `setting_value`='light'   WHERE `setting_key`='site_theme_default';
UPDATE `settings` SET `setting_value`='#2563EB' WHERE `setting_key`='site_color_primary';
UPDATE `settings` SET `setting_value`='#1E40AF' WHERE `setting_key`='site_color_secondary';
