-- 1.33.0: index for the rate limits and approval history (user, action, time).
CREATE INDEX IF NOT EXISTS "#__sdp_audit_idx_user_action_created" ON "#__sdp_audit" ("user_id", "action", "created");
