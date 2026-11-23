-- 1.33.0: index for the rate limits and approval history (user, action, time).
ALTER TABLE `#__sdp_audit` ADD INDEX `idx_user_action_created` (`user_id`, `action`, `created`);
