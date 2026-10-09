CREATE TABLE mos_workspace (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE mos_person (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  version BIGINT UNSIGNED NOT NULL,
  merged_into_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (workspace_id, id),
  CONSTRAINT fk_mos_person_workspace FOREIGN KEY (workspace_id) REFERENCES mos_workspace (id),
  CONSTRAINT fk_mos_person_merged FOREIGN KEY (workspace_id, merged_into_id)
    REFERENCES mos_person (workspace_id, id),
  CONSTRAINT chk_mos_person_state CHECK (state IN ('ACTIVE','MERGED','ERASED')),
  CONSTRAINT chk_mos_person_version CHECK (version >= 1),
  CONSTRAINT chk_mos_person_merge_target CHECK (
    (state = 'MERGED' AND merged_into_id IS NOT NULL AND merged_into_id <> id)
    OR (state <> 'MERGED' AND merged_into_id IS NULL)
  )
) ENGINE=InnoDB;

CREATE TABLE mos_legacy_entity_map (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_system VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_entity_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_external_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  person_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  evidence_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  origin VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  recorded_at DATETIME(6) NOT NULL,
  PRIMARY KEY (workspace_id, source_system, source_entity_type, source_external_id),
  KEY idx_mos_map_person (workspace_id, person_id),
  CONSTRAINT fk_mos_map_workspace FOREIGN KEY (workspace_id)
    REFERENCES mos_workspace (id),
  CONSTRAINT fk_mos_map_person FOREIGN KEY (workspace_id, person_id)
    REFERENCES mos_person (workspace_id, id)
) ENGINE=InnoDB;
