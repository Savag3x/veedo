
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE `admin_log` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int DEFAULT NULL,
  `details` text,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ads` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `category_id` int NOT NULL,
  `title` varchar(300) NOT NULL,
  `slug` varchar(350) NOT NULL,
  `description` text NOT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `price_negotiable` tinyint(1) DEFAULT '0',
  `currency` enum('RON','EUR','USD') DEFAULT 'RON',
  `city` varchar(100) DEFAULT NULL,
  `county` varchar(100) DEFAULT NULL,
  `address` varchar(300) DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `video_path` varchar(500) DEFAULT NULL,
  `video_thumb` varchar(500) DEFAULT NULL,
  `video_duration` int DEFAULT '0',
  `status` enum('pending','active','rejected','expired','sold','draft') DEFAULT 'pending',
  `is_boosted` tinyint(1) DEFAULT '0',
  `boosted_until` datetime DEFAULT NULL,
  `boost_type` enum('standard','premium') DEFAULT NULL,
  `views_count` int DEFAULT '0',
  `likes_count` int DEFAULT '0',
  `contact_count` int DEFAULT '0',
  `expires_at` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `reject_reason` text,
  `meta_title` varchar(300) DEFAULT NULL,
  `meta_description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `bumped_at` datetime DEFAULT NULL,
  `is_featured` tinyint(1) DEFAULT '0',
  `featured_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ad_comments` (
  `id` int NOT NULL,
  `ad_id` int NOT NULL,
  `user_id` int NOT NULL,
  `parent_id` int DEFAULT NULL COMMENT 'pentru răspunsuri la comentarii',
  `body` text NOT NULL,
  `is_approved` tinyint(1) DEFAULT '1',
  `likes_count` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ad_fields` (
  `id` int NOT NULL,
  `ad_id` int NOT NULL,
  `field_id` int NOT NULL,
  `field_value` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ad_photos` (
  `id` int NOT NULL,
  `ad_id` int NOT NULL,
  `path` varchar(500) NOT NULL,
  `sort_order` int DEFAULT '0',
  `is_primary` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ad_views` (
  `id` int NOT NULL,
  `ad_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `ip_hash` varchar(64) DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `affiliates` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `affiliate_code` varchar(20) NOT NULL,
  `commission_rate` decimal(5,2) DEFAULT '10.00',
  `status` enum('pending','active','suspended') DEFAULT 'pending',
  `total_earned` decimal(10,2) DEFAULT '0.00',
  `total_paid` decimal(10,2) DEFAULT '0.00',
  `clicks_count` int DEFAULT '0',
  `conversions_count` int DEFAULT '0',
  `paypal_email` varchar(255) DEFAULT NULL,
  `bank_iban` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `affiliate_clicks` (
  `id` int NOT NULL,
  `affiliate_id` int NOT NULL,
  `ad_id` int DEFAULT NULL,
  `ip_hash` varchar(64) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `converted` tinyint(1) DEFAULT '0',
  `order_id` int DEFAULT NULL,
  `commission_amount` decimal(10,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `affiliate_payouts` (
  `id` int NOT NULL,
  `affiliate_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `method` enum('paypal','bank') DEFAULT 'paypal',
  `status` enum('pending','paid','failed') DEFAULT 'pending',
  `reference` varchar(200) DEFAULT NULL,
  `note` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `affiliate_withdrawals` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `iban` varchar(34) NOT NULL,
  `status` enum('pending','paid','rejected') DEFAULT 'pending',
  `note` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `categories` (
  `id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `icon` varchar(50) DEFAULT 0xF09F93A6,
  `color` varchar(7) DEFAULT '#888888',
  `description` text,
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `meta_title` varchar(200) DEFAULT NULL,
  `meta_description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `category_fields` (
  `id` int NOT NULL,
  `category_id` int NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `field_label` varchar(200) NOT NULL,
  `field_type` enum('text','number','select','radio','checkbox') DEFAULT 'text',
  `field_options` text COMMENT 'JSON array opțiuni',
  `field_placeholder` varchar(200) DEFAULT NULL,
  `field_unit` varchar(50) DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT '0',
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `comment_likes` (
  `id` int NOT NULL,
  `comment_id` int NOT NULL,
  `user_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `conversations` (
  `id` int NOT NULL,
  `ad_id` int NOT NULL,
  `buyer_id` int NOT NULL,
  `seller_id` int NOT NULL,
  `last_message` text,
  `last_message_at` datetime DEFAULT NULL,
  `buyer_unread` int DEFAULT '0',
  `seller_unread` int DEFAULT '0',
  `is_archived_buyer` tinyint(1) DEFAULT '0',
  `is_archived_seller` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `favorites` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `ad_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `follows` (
  `id` int NOT NULL,
  `follower_id` int NOT NULL COMMENT 'cine urmărește',
  `following_id` int NOT NULL COMMENT 'pe cine urmărește',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `likes` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `ad_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `live_chat` (
  `id` int NOT NULL,
  `stream_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `user_name` varchar(100) NOT NULL DEFAULT 'Anonim',
  `user_avatar` varchar(500) DEFAULT NULL,
  `message` varchar(500) NOT NULL,
  `is_pinned` tinyint(1) DEFAULT '0',
  `is_deleted` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `live_products` (
  `id` int NOT NULL,
  `session_id` int NOT NULL,
  `ad_id` int NOT NULL,
  `is_featured` tinyint(1) DEFAULT '0',
  `sort_order` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `live_sessions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text,
  `stream_key` varchar(100) DEFAULT NULL,
  `playback_url` varchar(500) DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `status` enum('scheduled','live','ended') DEFAULT 'scheduled',
  `viewers_peak` int DEFAULT '0',
  `viewers_current` int DEFAULT '0',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `live_streams` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text,
  `stream_key` varchar(100) DEFAULT NULL,
  `stream_url` varchar(500) DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `status` enum('scheduled','live','ended') DEFAULT 'scheduled',
  `viewers_count` int DEFAULT '0',
  `scheduled_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `live_stream_products` (
  `id` int NOT NULL,
  `stream_id` int NOT NULL,
  `ad_id` int NOT NULL,
  `featured_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `messages` (
  `id` int NOT NULL,
  `conversation_id` int NOT NULL,
  `sender_id` int NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text,
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `orders` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `ad_id` int DEFAULT NULL,
  `type` enum('boost_7','boost_30','business_verify','ad_post','premium_category','affiliate_payout') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'RON',
  `payment_method` enum('stripe','netopia','free') DEFAULT 'stripe',
  `payment_id` varchar(200) DEFAULT NULL,
  `transaction_id` varchar(200) DEFAULT NULL,
  `status` enum('pending','completed','failed','refunded') DEFAULT 'pending',
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pages` (
  `id` int NOT NULL,
  `title` varchar(200) NOT NULL,
  `slug` varchar(200) NOT NULL,
  `content` longtext,
  `meta_title` varchar(200) DEFAULT NULL,
  `meta_description` text,
  `is_active` tinyint(1) DEFAULT '1',
  `in_footer` tinyint(1) DEFAULT '1',
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `push_subscriptions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `endpoint` varchar(1000) NOT NULL,
  `p256dh` varchar(500) NOT NULL,
  `auth` varchar(200) NOT NULL,
  `browser` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `rate_limits` (
  `id` int UNSIGNED NOT NULL,
  `rate_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `hits` int UNSIGNED NOT NULL DEFAULT '1',
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `referrals` (
  `id` int NOT NULL,
  `referrer_id` int NOT NULL,
  `referred_id` int NOT NULL,
  `status` enum('pending','converted','paid') DEFAULT 'pending',
  `commission_amount` decimal(10,2) DEFAULT '0.00',
  `converted_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `reports` (
  `id` int NOT NULL,
  `reporter_id` int NOT NULL,
  `ad_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `reason` enum('spam','fake','inappropriate','scam','other') NOT NULL,
  `details` text,
  `status` enum('pending','reviewed','resolved') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `reviews` (
  `id` int NOT NULL,
  `reviewer_id` int NOT NULL,
  `reviewed_id` int NOT NULL,
  `ad_id` int DEFAULT NULL,
  `rating` tinyint NOT NULL,
  `comment` text,
  `is_approved` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `search_alerts` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `keyword` varchar(200) DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `min_price` decimal(10,2) DEFAULT NULL,
  `max_price` decimal(10,2) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `has_video` tinyint(1) DEFAULT '0',
  `frequency` enum('instant','daily','weekly') DEFAULT 'daily',
  `is_active` tinyint(1) DEFAULT '1',
  `last_sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `settings` (
  `id` int NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `setting_group` varchar(50) DEFAULT 'general',
  `label` varchar(200) DEFAULT NULL,
  `type` enum('text','textarea','number','boolean','color','select','image') DEFAULT 'text',
  `options` text,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stories` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `media_path` varchar(255) NOT NULL,
  `media_type` enum('photo','video') DEFAULT 'photo',
  `caption` varchar(500) DEFAULT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `link_text` varchar(100) DEFAULT NULL,
  `views_count` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT ((now() + interval 24 hour))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `story_views` (
  `id` int NOT NULL,
  `story_id` int NOT NULL,
  `viewer_id` int DEFAULT NULL,
  `ip_hash` varchar(64) DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `users` (
  `id` int NOT NULL,
  `name` varchar(200) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `avatar` varchar(500) DEFAULT NULL,
  `bio` text,
  `business_name` varchar(200) DEFAULT NULL,
  `business_desc` text,
  `business_banner` varchar(500) DEFAULT NULL,
  `business_website` varchar(300) DEFAULT NULL,
  `business_phone` varchar(50) DEFAULT NULL,
  `business_category` varchar(100) DEFAULT NULL,
  `business_schedule` varchar(500) DEFAULT NULL,
  `business_address` varchar(300) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `county` varchar(100) DEFAULT NULL,
  `role` enum('user','business','admin','moderator') DEFAULT 'user',
  `is_verified_business` tinyint(1) DEFAULT '0',
  `business_verified_until` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `is_email_verified` tinyint(1) DEFAULT '0',
  `email_verify_token` varchar(100) DEFAULT NULL,
  `reset_token` varchar(100) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `ads_count` int DEFAULT '0',
  `rating_avg` decimal(3,2) DEFAULT '0.00',
  `rating_count` int DEFAULT '0',
  `theme_preference` enum('light','dark','auto') DEFAULT 'auto',
  `notification_email` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  `followers_count` int DEFAULT '0',
  `following_count` int DEFAULT '0',
  `referral_code` varchar(20) DEFAULT NULL,
  `referred_by` int DEFAULT NULL,
  `affiliate_balance` decimal(10,2) DEFAULT '0.00',
  `affiliate_total_earned` decimal(10,2) DEFAULT '0.00',
  `notif_email_messages` tinyint(1) NOT NULL DEFAULT '1',
  `notif_email_ad_status` tinyint(1) NOT NULL DEFAULT '1',
  `notif_email_reviews` tinyint(1) NOT NULL DEFAULT '1',
  `notif_email_follows` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `admin_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_action` (`action`);

ALTER TABLE `ads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_category` (`category_id`),
  ADD KEY `idx_boosted` (`is_boosted`,`boosted_until`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_slug` (`slug`),
  ADD KEY `idx_status_boosted` (`status`,`is_boosted`),
  ADD KEY `idx_listing_main` (`status`,`is_boosted`,`created_at`),
  ADD KEY `idx_status_expires` (`status`,`expires_at`),
  ADD KEY `idx_bumped` (`bumped_at`);
ALTER TABLE `ads` ADD FULLTEXT KEY `ft_search` (`title`,`description`);

ALTER TABLE `ad_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_id` (`parent_id`),
  ADD KEY `idx_ad` (`ad_id`),
  ADD KEY `idx_user` (`user_id`);

ALTER TABLE `ad_fields`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_ad_field` (`ad_id`,`field_id`),
  ADD KEY `field_id` (`field_id`),
  ADD KEY `idx_ad` (`ad_id`);

ALTER TABLE `ad_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ad` (`ad_id`),
  ADD KEY `idx_ad_primary` (`ad_id`,`is_primary`);

ALTER TABLE `ad_views`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ad_date` (`ad_id`,`viewed_at`),
  ADD KEY `idx_cleanup` (`viewed_at`);

ALTER TABLE `affiliates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `affiliate_code` (`affiliate_code`);

ALTER TABLE `affiliate_clicks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_affiliate` (`affiliate_id`),
  ADD KEY `idx_created` (`created_at`);

ALTER TABLE `affiliate_payouts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `affiliate_id` (`affiliate_id`);

ALTER TABLE `affiliate_withdrawals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_parent` (`parent_id`),
  ADD KEY `idx_slug` (`slug`);

ALTER TABLE `category_fields`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cat` (`category_id`);

ALTER TABLE `comment_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_comment_like` (`comment_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `conversations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_conv` (`ad_id`,`buyer_id`),
  ADD KEY `idx_buyer` (`buyer_id`),
  ADD KEY `idx_seller` (`seller_id`);

ALTER TABLE `favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_fav` (`user_id`,`ad_id`),
  ADD KEY `ad_id` (`ad_id`);

ALTER TABLE `follows`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_follow` (`follower_id`,`following_id`),
  ADD KEY `idx_follower` (`follower_id`),
  ADD KEY `idx_following` (`following_id`);

ALTER TABLE `likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_like` (`user_id`,`ad_id`),
  ADD KEY `ad_id` (`ad_id`);

ALTER TABLE `live_chat`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stream` (`stream_id`,`created_at`);

ALTER TABLE `live_products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `session_id` (`session_id`),
  ADD KEY `ad_id` (`ad_id`);

ALTER TABLE `live_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stream_key` (`stream_key`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_status` (`status`);

ALTER TABLE `live_streams`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stream_key` (`stream_key`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_user` (`user_id`);

ALTER TABLE `live_stream_products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `stream_id` (`stream_id`),
  ADD KEY `ad_id` (`ad_id`);

ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `idx_conv` (`conversation_id`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_created` (`created_at`);

ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ad_id` (`ad_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_txn_id` (`transaction_id`);

ALTER TABLE `pages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

ALTER TABLE `push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_endpoint` (`user_id`,`endpoint`(255)),
  ADD KEY `idx_user` (`user_id`);

ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rate_key` (`rate_key`),
  ADD KEY `idx_expires` (`expires_at`);

ALTER TABLE `referrals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_referred` (`referred_id`),
  ADD KEY `referrer_id` (`referrer_id`);

ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reporter_id` (`reporter_id`),
  ADD KEY `ad_id` (`ad_id`);

ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_review` (`reviewer_id`,`reviewed_id`,`ad_id`),
  ADD KEY `idx_reviewed` (`reviewed_id`);

ALTER TABLE `search_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_active` (`is_active`,`frequency`);

ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

ALTER TABLE `stories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_expires` (`expires_at`),
  ADD KEY `idx_active` (`is_active`);

ALTER TABLE `story_views`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_view` (`story_id`,`viewer_id`),
  ADD KEY `idx_story` (`story_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `referral_code` (`referral_code`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`);

ALTER TABLE `admin_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `ads`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `ad_comments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `ad_fields`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `ad_photos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `ad_views`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `affiliates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `affiliate_clicks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `affiliate_payouts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `affiliate_withdrawals`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `category_fields`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `comment_likes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `conversations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `favorites`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `follows`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `likes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `live_chat`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `live_products`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `live_sessions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `live_streams`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `live_stream_products`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `pages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `push_subscriptions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `rate_limits`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `referrals`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `reports`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `reviews`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `search_alerts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `stories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `story_views`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `live_chat`
  ADD CONSTRAINT `live_chat_ibfk_1` FOREIGN KEY (`stream_id`) REFERENCES `live_streams` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
