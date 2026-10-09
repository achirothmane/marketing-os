CREATE TABLE mos_messenger_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  body LONGTEXT NOT NULL,
  headers LONGTEXT NOT NULL,
  queue_name VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL,
  available_at DATETIME NOT NULL,
  delivered_at DATETIME NULL,
  KEY idx_mos_messenger_queue (queue_name, available_at, delivered_at, id)
) ENGINE=InnoDB;

CREATE TABLE mos_identity_projection (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  person_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  imported_event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY(workspace_id,person_id),
  UNIQUE KEY uq_mos_projected_event (workspace_id,imported_event_id),
  CONSTRAINT fk_mos_projection_person FOREIGN KEY(workspace_id,person_id)
    REFERENCES mos_person(workspace_id,id),
  CONSTRAINT fk_mos_projection_event FOREIGN KEY(workspace_id,imported_event_id)
    REFERENCES mos_domain_event(workspace_id,event_id)
) ENGINE=InnoDB;

CREATE TABLE mos_messenger_failure (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  consumer_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  attempts INT UNSIGNED NOT NULL,
  last_error_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  quarantined_at DATETIME(6) NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY(workspace_id,event_id,consumer_key),
  CONSTRAINT fk_mos_failed_event FOREIGN KEY(workspace_id,event_id)
    REFERENCES mos_domain_event(workspace_id,event_id)
) ENGINE=InnoDB;
