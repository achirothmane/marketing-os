CREATE TABLE mos_contact_scan_cursor (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  last_seen_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sweep_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (workspace_id),
  CONSTRAINT fk_mos_scan_workspace FOREIGN KEY (workspace_id) REFERENCES mos_workspace(id),
  CONSTRAINT chk_mos_scan_cursor CHECK (last_seen_id >= 0)
) ENGINE=InnoDB;
