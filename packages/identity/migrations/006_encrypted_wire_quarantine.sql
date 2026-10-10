CREATE TABLE mos_messenger_wire_quarantine (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_message_id BIGINT UNSIGNED NOT NULL,
  queue_name VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  key_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  sealed_nonce VARBINARY(24) NOT NULL,
  sealed_payload LONGBLOB NOT NULL,
  byte_count INT UNSIGNED NOT NULL,
  quarantined_at DATETIME(6) NOT NULL,
  PRIMARY KEY (workspace_id, source_message_id),
  KEY idx_mos_wire_quarantine_time (workspace_id, quarantined_at),
  CONSTRAINT fk_mos_wire_quarantine_workspace FOREIGN KEY (workspace_id)
    REFERENCES mos_workspace (id)
) ENGINE=InnoDB;
