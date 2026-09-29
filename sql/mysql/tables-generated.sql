-- This file matches the output of maintenance/generateSchemaSql.php.
-- Source: sql/tables.json
-- Written by hand because the container image ships without seld/jsonlint,
-- which generateSchemaSql.php requires. Keep it in step with tables.json.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/footprint (
  fp_page INT UNSIGNED NOT NULL,
  fp_user INT UNSIGNED NOT NULL,
  fp_views INT UNSIGNED DEFAULT 1 NOT NULL,
  fp_first BINARY(14) NOT NULL,
  fp_last BINARY(14) NOT NULL,
  INDEX fp_page_last (fp_page, fp_last),
  INDEX fp_user_last (fp_user, fp_last),
  PRIMARY KEY(fp_page, fp_user)
) /*$wgDBTableOptions*/;

CREATE TABLE /*_*/footprint_log (
  fl_id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  fl_page INT UNSIGNED NOT NULL,
  fl_user INT UNSIGNED NOT NULL,
  fl_timestamp BINARY(14) NOT NULL,
  INDEX fl_timestamp (fl_timestamp),
  INDEX fl_page_timestamp (fl_page, fl_timestamp),
  PRIMARY KEY(fl_id)
) /*$wgDBTableOptions*/;
