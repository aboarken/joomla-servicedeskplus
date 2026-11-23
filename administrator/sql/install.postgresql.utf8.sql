CREATE TABLE IF NOT EXISTS "#__sdp_settings" (
  "name" varchar(100) NOT NULL,
  "value" text NOT NULL,
  PRIMARY KEY ("name")
);

CREATE TABLE IF NOT EXISTS "#__sdp_user_map" (
  "joomla_user_id" bigint NOT NULL,
  "sdp_user_id" varchar(20) DEFAULT NULL,
  "email" varchar(320) DEFAULT '' NOT NULL,
  "matched_at" timestamp without time zone DEFAULT NULL,
  "checked_at" timestamp without time zone NOT NULL,
  "status" varchar(20) NOT NULL,
  "source" varchar(10) DEFAULT 'auto' NOT NULL,
  "modified_by" bigint DEFAULT 0 NOT NULL,
  PRIMARY KEY ("joomla_user_id"),
  CONSTRAINT "#__sdp_user_map_idx_sdp_user_id" UNIQUE ("sdp_user_id")
);
CREATE INDEX IF NOT EXISTS "#__sdp_user_map_idx_status" ON "#__sdp_user_map" ("status");

CREATE TABLE IF NOT EXISTS "#__sdp_audit" (
  "id" bigserial NOT NULL,
  "user_id" bigint DEFAULT 0 NOT NULL,
  "actor_id" bigint DEFAULT 0 NOT NULL,
  "action" varchar(50) NOT NULL,
  "sdp_request_id" varchar(20) DEFAULT NULL,
  "approval_id" varchar(20) DEFAULT NULL,
  "module" varchar(20) DEFAULT '' NOT NULL,
  "subject" varchar(255) DEFAULT '' NOT NULL,
  "result" varchar(20) NOT NULL,
  "detail" varchar(255) DEFAULT '' NOT NULL,
  "created" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__sdp_audit_idx_user_created" ON "#__sdp_audit" ("user_id", "created");
CREATE INDEX IF NOT EXISTS "#__sdp_audit_idx_action_created" ON "#__sdp_audit" ("action", "created");
CREATE INDEX IF NOT EXISTS "#__sdp_audit_idx_user_action_created" ON "#__sdp_audit" ("user_id", "action", "created");

CREATE TABLE IF NOT EXISTS "#__sdp_cache" (
  "user_id" bigint NOT NULL,
  "cache_key" varchar(191) NOT NULL,
  "value" text NOT NULL,
  "created" timestamp without time zone NOT NULL,
  PRIMARY KEY ("user_id", "cache_key")
);
CREATE INDEX IF NOT EXISTS "#__sdp_cache_idx_created" ON "#__sdp_cache" ("created");
