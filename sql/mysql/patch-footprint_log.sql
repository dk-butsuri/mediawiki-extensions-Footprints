-- Adds footprint_log for an install that already has footprint.
-- Matches the footprint_log block in tables-generated.sql; keep them in step.
CREATE TABLE /*_*/footprint_log (
  fl_id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  fl_page INT UNSIGNED NOT NULL,
  fl_user INT UNSIGNED NOT NULL,
  fl_timestamp BINARY(14) NOT NULL,
  INDEX fl_timestamp (fl_timestamp),
  INDEX fl_page_timestamp (fl_page, fl_timestamp),
  PRIMARY KEY(fl_id)
) /*$wgDBTableOptions*/;
