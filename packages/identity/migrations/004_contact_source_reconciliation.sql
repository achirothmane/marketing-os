CREATE TABLE mos_source_reconciliation_case (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_system VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_entity_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_external_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  observed_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  missing_observations INT UNSIGNED NOT NULL DEFAULT 0,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  first_observed_at DATETIME(6) NOT NULL,
  last_observed_at DATETIME(6) NOT NULL,
  PRIMARY KEY (workspace_id,source_system,source_entity_type,source_external_id),
  KEY idx_mos_source_case_status (workspace_id,status),
  CONSTRAINT fk_mos_source_case_mapping
    FOREIGN KEY (workspace_id,source_system,source_entity_type,source_external_id)
    REFERENCES mos_legacy_entity_map (workspace_id,source_system,source_entity_type,source_external_id),
  CONSTRAINT chk_mos_source_status CHECK (status IN
    ('MATCH','SOURCE_CHANGED','SOURCE_MISSING_ONCE','SOURCE_MISSING_REPEATED','UNVERIFIED_LEGACY')),
  CONSTRAINT chk_mos_source_revision CHECK (revision>=1)
) ENGINE=InnoDB;

CREATE TABLE mos_source_reconciliation_observation (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_system VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_entity_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_external_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  observed_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  observed_at DATETIME(6) NOT NULL,
  revision BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mos_reconciliation_revision
    (workspace_id,source_system,source_entity_type,source_external_id,revision),
  CONSTRAINT fk_mos_source_observation_case
    FOREIGN KEY (workspace_id,source_system,source_entity_type,source_external_id)
    REFERENCES mos_source_reconciliation_case (workspace_id,source_system,source_entity_type,source_external_id),
  CONSTRAINT chk_mos_observation_status CHECK (status IN
    ('MATCH','SOURCE_CHANGED','SOURCE_MISSING_ONCE','SOURCE_MISSING_REPEATED','UNVERIFIED_LEGACY'))
) ENGINE=InnoDB;
