CREATE TABLE mos_evidence (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_system VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_locator VARCHAR(320) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  content_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  observed_at DATETIME(6) NOT NULL,
  recorded_at DATETIME(6) NOT NULL,
  PRIMARY KEY (workspace_id,id),
  CONSTRAINT fk_mos_evidence_workspace FOREIGN KEY (workspace_id) REFERENCES mos_workspace (id)
) ENGINE=InnoDB;

CREATE TABLE mos_domain_event (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_type VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  schema_version SMALLINT UNSIGNED NOT NULL,
  aggregate_type VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  aggregate_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  aggregate_version BIGINT UNSIGNED NOT NULL,
  evidence_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  actor_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  correlation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  causation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  occurred_at DATETIME(6) NOT NULL,
  recorded_at DATETIME(6) NOT NULL,
  payload_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  metadata_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (workspace_id,event_id),
  KEY idx_mos_domain_event_aggregate (workspace_id,aggregate_type,aggregate_id),
  CONSTRAINT fk_mos_event_workspace FOREIGN KEY (workspace_id) REFERENCES mos_workspace (id),
  CONSTRAINT fk_mos_event_evidence FOREIGN KEY (workspace_id,evidence_id)
    REFERENCES mos_evidence (workspace_id,id),
  CONSTRAINT chk_mos_event_payload CHECK (JSON_VALID(payload_json)),
  CONSTRAINT chk_mos_event_metadata CHECK (JSON_VALID(metadata_json))
) ENGINE=InnoDB;

CREATE TABLE mos_outbox (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  lease_owner CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  lease_expires_at DATETIME(6) NULL,
  published_at DATETIME(6) NULL,
  PRIMARY KEY (workspace_id,event_id),
  KEY idx_mos_outbox_due (published_at,lease_expires_at,created_at),
  CONSTRAINT fk_mos_outbox_event FOREIGN KEY (workspace_id,event_id)
    REFERENCES mos_domain_event (workspace_id,event_id)
) ENGINE=InnoDB;

CREATE TABLE mos_inbox (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  consumer_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  processed_at DATETIME(6) NOT NULL,
  PRIMARY KEY (workspace_id,event_id,consumer_key),
  CONSTRAINT fk_mos_inbox_event FOREIGN KEY (workspace_id,event_id)
    REFERENCES mos_domain_event (workspace_id,event_id)
) ENGINE=InnoDB;
