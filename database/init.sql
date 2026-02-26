-- =====================================================================
-- BloodLink Dual Database Init (Fully Commented)
--   DB1: bloodlink        (clinical / hospital blood bank operations)
--   DB2: bloodlink_gov    (governance / customers / licensing / audit)
--
-- Notes on ISO 15189:
-- ISO 15189 is broad; the schema comments below express the *practical*
-- requirements it implies for a medical laboratory / transfusion service:
--   - traceability of specimens/components and records
--   - data integrity and controlled records
--   - auditability (who/when/what)
--   - retention-ready structure
--   - controlled access and accountability (paired with app controls)
-- =====================================================================

-- =========================
-- DB1: CLINICAL (bloodlink)
-- =========================
CREATE DATABASE IF NOT EXISTS bloodlink
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
USE bloodlink;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS haemovigilance_reports;
DROP TABLE IF EXISTS transfusions;
DROP TABLE IF EXISTS issues;
DROP TABLE IF EXISTS unit_events;
DROP TABLE IF EXISTS blood_units;
DROP TABLE IF EXISTS storage_locations;
DROP TABLE IF EXISTS patients;
DROP TABLE IF EXISTS suppliers;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Table: suppliers
-- ---------------------------------------------------------------------
CREATE TABLE suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: INT (PK). Description: Internal surrogate key for supplier record. ISO15189: Unique identification supports controlled records and traceability.',

  name VARCHAR(255) NOT NULL
    COMMENT 'Type: VARCHAR(255). Description: Operational/legal name of the supplier (e.g., IBTS). ISO15189: Clear identification of external providers supporting traceability and controlled records.',

  supplier_code VARCHAR(64) NULL
    COMMENT 'Type: VARCHAR(64). Description: External supplier identifier used on labels / documentation where applicable. ISO15189: Supports unambiguous traceability across external interfaces.',

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: TIMESTAMP. Description: Row creation timestamp (system metadata). ISO15189: Supports record integrity and audit-ready chronology (creation time).'
) ENGINE=InnoDB
COMMENT='Supplier master list for blood components received into hospital inventory. ISO15189: Supports control of externally provided products and traceability.'
;

-- Index comments are optional here; PK is self-explanatory in MySQL.

-- ---------------------------------------------------------------------
-- Table: patients
-- ---------------------------------------------------------------------
CREATE TABLE patients (
  id INT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: INT (PK). Description: Internal surrogate key for patient record. ISO15189: Unique identification supports controlled records.',

  mrn VARCHAR(64) NOT NULL
    COMMENT 'Type: VARCHAR(64) (UK). Description: Hospital Medical Record Number (unique within facility). ISO15189: Unambiguous patient identification is essential for traceability and error prevention.',

  first_name VARCHAR(100) NOT NULL
    COMMENT 'Type: VARCHAR(100). Description: Patient given name (minimum needed for labelling/workflow). ISO15189: Patient identification elements must be recorded accurately and legibly.',

  last_name VARCHAR(100) NOT NULL
    COMMENT 'Type: VARCHAR(100). Description: Patient family name. ISO15189: Patient identification and controlled records.',

  dob DATE NOT NULL
    COMMENT 'Type: DATE. Description: Date of birth for positive identification. ISO15189: Patient identification and risk control for misidentification.',

  sex ENUM('M','F','X','U') NOT NULL DEFAULT 'U'
    COMMENT 'Type: ENUM. Description: Administrative sex field for identification (U=Unknown). ISO15189: Supports correct identification and record completeness where required by local procedure.',

  abo_group ENUM('O','A','B','AB') NULL
    COMMENT 'Type: ENUM. Description: Patient ABO group (if known/verified). ISO15189: Result recording/traceability; must be governed by SOP for verification.',

  rhd ENUM('+','-') NULL
    COMMENT 'Type: ENUM. Description: Patient RhD status (if known/verified). ISO15189: Result recording/traceability; must be governed by SOP for verification.',

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: TIMESTAMP. Description: Row creation timestamp. ISO15189: Audit-ready chronology for controlled records.',

  UNIQUE KEY uniq_patients_mrn (mrn) COMMENT 'Key: UNIQUE(mrn). Description: Prevents duplicate MRNs. ISO15189: Data integrity for patient identification.'
) ENGINE=InnoDB
COMMENT='Patient master data (minimal) for issue/transfusion traceability. ISO15189: Supports unambiguous identification and controlled records.'
;

-- ---------------------------------------------------------------------
-- Table: storage_locations
-- ---------------------------------------------------------------------
CREATE TABLE storage_locations (
  id INT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: INT (PK). Description: Internal identifier for a storage location. ISO15189: Supports controlled storage records.',

  name VARCHAR(255) NOT NULL
    COMMENT 'Type: VARCHAR(255). Description: Human-readable storage location name (e.g., "Fridge 1"). ISO15189: Supports clear identification of storage equipment/locations in records.',

  location_type ENUM('RBC_FRIDGE','PLASMA_FREEZER','PLATELET_AGITATOR','OTHER') NOT NULL
    COMMENT 'Type: ENUM. Description: Storage class to support correct handling conditions. ISO15189: Supports controlled environmental/storage conditions and correct processing.',

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: TIMESTAMP. Description: Row creation timestamp. ISO15189: Audit-ready chronology.'
) ENGINE=InnoDB
COMMENT='Physical storage locations for components (fridges/freezers/agitators). ISO15189: Supports controlled storage and traceability of location.'
;

-- ---------------------------------------------------------------------
-- Table: blood_units
-- ---------------------------------------------------------------------
CREATE TABLE blood_units (
  id INT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: INT (PK). Description: Internal identifier for the blood component unit. ISO15189: Unambiguous unit identification is foundational for traceability.',

  supplier_id INT NOT NULL
    COMMENT 'Type: INT (FK->suppliers.id). Description: Supplier who provided the unit. ISO15189: Traceability across external provider interfaces.',

  donation_number VARCHAR(64) NOT NULL
    COMMENT 'Type: VARCHAR(64). Description: Donation/component number as printed on label (e.g., ISBT-128/IBTS identifier). ISO15189: Traceability of units is mandatory for controlled records.',

  component_type ENUM('RBC','PLASMA','PLATELETS','CRYO','WHOLE_BLOOD','OTHER') NOT NULL
    COMMENT 'Type: ENUM. Description: Component classification. ISO15189: Clear definition of examined/handled item for correct processing and traceability.',

  abo_group ENUM('O','A','B','AB') NOT NULL
    COMMENT 'Type: ENUM. Description: Unit ABO group as labelled. ISO15189: Recording of critical attributes for safe issue/transfusion and traceability.',

  rhd ENUM('+','-') NOT NULL
    COMMENT 'Type: ENUM. Description: Unit RhD status as labelled. ISO15189: Recording of critical attributes for compatibility and traceability.',

  volume_ml INT NOT NULL
    COMMENT 'Type: INT. Description: Declared volume in millilitres. ISO15189: Controlled record of component characteristics relevant to handling/issue.',

  collection_datetime DATETIME NULL
    COMMENT 'Type: DATETIME. Description: Collection time if available from supplier documentation. ISO15189: Supports traceability and chronological integrity where supplied.',

  expiry_datetime DATETIME NOT NULL
    COMMENT 'Type: DATETIME. Description: Label expiry date/time; unit must not be issued/transfused beyond this. ISO15189: Control of validity and safe use; record accuracy is critical.',

  status ENUM('RECEIVED','IN_STORAGE','ISSUED','TRANSFUSED','RETURNED','DISCARDED','QUARANTINED','EXPIRED')
    NOT NULL DEFAULT 'RECEIVED'
    COMMENT 'Type: ENUM. Description: Current lifecycle status of unit. ISO15189: Controlled workflow state supports traceability and auditability.',

  storage_location_id INT NULL
    COMMENT 'Type: INT (FK->storage_locations.id). Description: Current storage location. ISO15189: Supports controlled storage/location traceability.',

  cmv_negative BOOLEAN NOT NULL DEFAULT FALSE
    COMMENT 'Type: BOOLEAN. Description: Flag if unit labelled CMV-negative. ISO15189: Controlled recording of special attributes used in issue decisions.',

  irradiated BOOLEAN NOT NULL DEFAULT FALSE
    COMMENT 'Type: BOOLEAN. Description: Flag if unit irradiated. ISO15189: Controlled record of processing attributes affecting suitability.',

  washed BOOLEAN NOT NULL DEFAULT FALSE
    COMMENT 'Type: BOOLEAN. Description: Flag if unit washed. ISO15189: Controlled record of processing attributes affecting suitability.',

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: TIMESTAMP. Description: Unit record creation timestamp. ISO15189: Supports audit-ready chronology.',

  UNIQUE KEY uniq_unit (supplier_id, donation_number, component_type)
    COMMENT 'Key: UNIQUE(supplier_id, donation_number, component_type). Description: Prevents duplicate unit records for same labelled unit/component. ISO15189: Data integrity + traceability.',

  KEY idx_units_status (status)
    COMMENT 'Key: INDEX(status). Description: Operational querying by lifecycle status. ISO15189: Enables timely control/segregation (e.g., quarantined/expired).',

  KEY idx_units_expiry (expiry_datetime)
    COMMENT 'Key: INDEX(expiry_datetime). Description: Supports expiry management and recall checks. ISO15189: Supports timely control of validity and retention querying.',

  KEY idx_units_blood (abo_group, rhd)
    COMMENT 'Key: INDEX(abo_group, rhd). Description: Supports compatibility search and inventory summaries. ISO15189: Supports safe selection workflow.',

  KEY idx_units_storage (storage_location_id)
    COMMENT 'Key: INDEX(storage_location_id). Description: Fast lookup by physical location. ISO15189: Supports controlled storage tracking.',

  CONSTRAINT fk_units_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_units_storage  FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id)
) ENGINE=InnoDB
COMMENT='Blood component units held by the hospital blood bank. ISO15189: Traceability, controlled records, and auditable lifecycle state.'
;

-- ---------------------------------------------------------------------
-- Table: unit_events (audit spine)
-- ---------------------------------------------------------------------
CREATE TABLE unit_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: BIGINT (PK). Description: Internal identifier for event record. ISO15189: Supports detailed audit trail records at scale.',

  unit_id INT NOT NULL
    COMMENT 'Type: INT (FK->blood_units.id). Description: Unit the event pertains to. ISO15189: Links audit trail to controlled item for traceability.',

  event_type ENUM('RECEIPT','MOVE','ISSUE','RETURN','DISCARD','TRANSFUSE','QUARANTINE','STATUS_CHANGE') NOT NULL
    COMMENT 'Type: ENUM. Description: Category of lifecycle event. ISO15189: Controlled recording of process steps and deviations.',

  event_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: DATETIME. Description: Timestamp the event occurred/was recorded. ISO15189: Chronological integrity and traceability.',

  performed_by VARCHAR(128) NULL
    COMMENT 'Type: VARCHAR(128). Description: Identifier of operator/system account performing action (v1 string; later FK to users). ISO15189: Accountability—who performed the step.',

  notes TEXT NULL
    COMMENT 'Type: TEXT. Description: Free text notes / reason / deviation details. ISO15189: Recording of nonconformities and contextual details for review.',

  from_storage_location_id INT NULL
    COMMENT 'Type: INT (FK->storage_locations.id). Description: Source storage location for MOVE events. ISO15189: Traceability of storage movement.',

  to_storage_location_id INT NULL
    COMMENT 'Type: INT (FK->storage_locations.id). Description: Destination storage location for MOVE events. ISO15189: Traceability of storage movement.',

  KEY idx_events_unit_time (unit_id, event_time)
    COMMENT 'Key: INDEX(unit_id, event_time). Description: Timeline reconstruction per unit. ISO15189: Traceability and audit review efficiency.',

  KEY idx_events_type_time (event_type, event_time)
    COMMENT 'Key: INDEX(event_type, event_time). Description: Operational reporting (e.g., all discards). ISO15189: Supports monitoring and quality review.',

  CONSTRAINT fk_events_unit FOREIGN KEY (unit_id) REFERENCES blood_units(id),
  CONSTRAINT fk_events_from FOREIGN KEY (from_storage_location_id) REFERENCES storage_locations(id),
  CONSTRAINT fk_events_to   FOREIGN KEY (to_storage_location_id) REFERENCES storage_locations(id)
) ENGINE=InnoDB
COMMENT='Immutable event log of unit lifecycle actions (receipt/move/issue/return/discard/transfuse/quarantine). ISO15189: Audit trail + traceability.'
;

-- ---------------------------------------------------------------------
-- Table: issues (issue/compatibility record snapshot)
-- ---------------------------------------------------------------------
CREATE TABLE issues (
  id BIGINT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: BIGINT (PK). Description: Issue transaction identifier. ISO15189: Controlled record of issuance activity.',

  unit_id INT NOT NULL
    COMMENT 'Type: INT (FK->blood_units.id). Description: Unit being issued. ISO15189: Traceability between issued product and patient.',

  patient_id INT NOT NULL
    COMMENT 'Type: INT (FK->patients.id). Description: Recipient patient. ISO15189: Traceability and correct identification in controlled records.',

  issued_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: DATETIME. Description: Time unit was issued from blood bank control. ISO15189: Chronology of critical steps.',

  required_time DATETIME NULL
    COMMENT 'Type: DATETIME. Description: Clinical required-by time (if recorded). ISO15189: Supports controlled workflow and prioritisation records.',

  patient_location VARCHAR(255) NULL
    COMMENT 'Type: VARCHAR(255). Description: Ward/bed/location at time of issue. ISO15189: Traceability for distribution and recall actions.',

  issued_by VARCHAR(128) NULL
    COMMENT 'Type: VARCHAR(128). Description: Operator/system identifier who issued. ISO15189: Accountability and audit trail.',

  patient_abo_group ENUM('O','A','B','AB') NULL
    COMMENT 'Type: ENUM. Description: Patient ABO as used/recorded at issue time (snapshot). ISO15189: Result/attribute snapshot integrity for historical record.',

  patient_rhd ENUM('+','-') NULL
    COMMENT 'Type: ENUM. Description: Patient RhD as used/recorded at issue time (snapshot). ISO15189: Snapshot supports review and traceability.',

  unit_abo_group ENUM('O','A','B','AB') NOT NULL
    COMMENT 'Type: ENUM. Description: Unit ABO at issue time (snapshot). ISO15189: Snapshot supports review if upstream label data changes in system.',

  unit_rhd ENUM('+','-') NOT NULL
    COMMENT 'Type: ENUM. Description: Unit RhD at issue time (snapshot). ISO15189: Snapshot supports review and audit.',

  donation_number VARCHAR(64) NOT NULL
    COMMENT 'Type: VARCHAR(64). Description: Unit donation/component number snapshot at issue time. ISO15189: Traceability identifier preserved for audits/recalls.',

  expiry_datetime DATETIME NOT NULL
    COMMENT 'Type: DATETIME. Description: Unit expiry snapshot at issue time. ISO15189: Controlled record of validity at issue.',

  KEY idx_issues_patient_time (patient_id, issued_time)
    COMMENT 'Key: INDEX(patient_id, issued_time). Description: Patient issue history. ISO15189: Supports traceability and audit queries.',

  KEY idx_issues_unit (unit_id)
    COMMENT 'Key: INDEX(unit_id). Description: Reverse lookup from unit to issue record. ISO15189: Supports traceability and recall workflows.',

  CONSTRAINT fk_issues_unit    FOREIGN KEY (unit_id) REFERENCES blood_units(id),
  CONSTRAINT fk_issues_patient FOREIGN KEY (patient_id) REFERENCES patients(id)
) ENGINE=InnoDB
COMMENT='Issue records (unit -> patient) with snapshot fields for historical integrity. ISO15189: Controlled record of distribution/issue and traceability.'
;

-- ---------------------------------------------------------------------
-- Table: transfusions (final fate/outcome)
-- ---------------------------------------------------------------------
CREATE TABLE transfusions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: BIGINT (PK). Description: Transfusion/outcome record identifier. ISO15189: Controlled record of final disposition.',

  issue_id BIGINT NOT NULL
    COMMENT 'Type: BIGINT (FK->issues.id). Description: Issue transaction this outcome relates to. ISO15189: Traceability from issue to final fate.',

  transfusion_start DATETIME NULL
    COMMENT 'Type: DATETIME. Description: Start time of transfusion (if transfused). ISO15189: Chronology of critical clinical steps (where recorded by procedure).',

  transfusion_end DATETIME NULL
    COMMENT 'Type: DATETIME. Description: End time of transfusion (if transfused). ISO15189: Chronology/supports review where required.',

  fate ENUM('TRANSFUSED','RETURNED','DISCARDED','NOT_ADMINISTERED') NOT NULL
    COMMENT 'Type: ENUM. Description: Final fate of issued unit. ISO15189: Traceability to final disposition and quality monitoring.',

  bedside_check_by_1 VARCHAR(128) NULL
    COMMENT 'Type: VARCHAR(128). Description: Identifier for first bedside checker (v1 string). ISO15189: Accountability for critical verification steps.',

  bedside_check_by_2 VARCHAR(128) NULL
    COMMENT 'Type: VARCHAR(128). Description: Identifier for second bedside checker (v1 string). ISO15189: Accountability for critical verification steps.',

  notes TEXT NULL
    COMMENT 'Type: TEXT. Description: Outcome notes / reasons for discard/return etc. ISO15189: Recording deviations/nonconformities for quality review.',

  UNIQUE KEY uniq_transfusion_issue (issue_id)
    COMMENT 'Key: UNIQUE(issue_id). Description: Ensures one outcome per issue event. ISO15189: Data integrity for controlled records.',

  CONSTRAINT fk_transfusions_issue FOREIGN KEY (issue_id) REFERENCES issues(id)
) ENGINE=InnoDB
COMMENT='Outcome record for an issued unit (transfused/returned/discarded). ISO15189: Traceability to final fate and auditable lifecycle completion.'
;

-- ---------------------------------------------------------------------
-- Table: haemovigilance_reports (adverse reactions/events)
-- ---------------------------------------------------------------------
CREATE TABLE haemovigilance_reports (
  id BIGINT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: BIGINT (PK). Description: Haemovigilance report identifier. ISO15189: Controlled records for incidents and corrective actions.',

  unit_id INT NULL
    COMMENT 'Type: INT (FK->blood_units.id). Description: Associated unit (if known). ISO15189: Traceability for investigations and recalls.',

  patient_id INT NULL
    COMMENT 'Type: INT (FK->patients.id). Description: Associated patient (if applicable). ISO15189: Traceability and controlled documentation.',

  report_type ENUM('SERIOUS_ADVERSE_REACTION','SERIOUS_ADVERSE_EVENT','NEAR_MISS','OTHER') NOT NULL
    COMMENT 'Type: ENUM. Description: Classification of report. ISO15189: Supports quality management and incident categorisation.',

  severity ENUM('LOW','MODERATE','SEVERE','FATAL','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN'
    COMMENT 'Type: ENUM. Description: Severity classification. ISO15189: Supports risk management, escalation, and review.',

  occurred_time DATETIME NULL
    COMMENT 'Type: DATETIME. Description: When the incident occurred (if known). ISO15189: Chronological integrity of incident records.',

  discovered_time DATETIME NULL
    COMMENT 'Type: DATETIME. Description: When the incident was discovered (if known). ISO15189: Supports timelines for investigation.',

  reported_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: DATETIME. Description: When report was entered in system. ISO15189: Audit trail and controlled record chronology.',

  reporter VARCHAR(128) NULL
    COMMENT 'Type: VARCHAR(128). Description: Who reported (v1 string). ISO15189: Accountability for incident reporting.',

  description TEXT NOT NULL
    COMMENT 'Type: TEXT. Description: Narrative description of event/reaction. ISO15189: Controlled documentation of nonconformity/incident.',

  status ENUM('OPEN','UNDER_REVIEW','SUBMITTED','CLOSED') NOT NULL DEFAULT 'OPEN'
    COMMENT 'Type: ENUM. Description: Workflow state of investigation/reporting. ISO15189: Quality system workflow and traceability of corrective actions.',

  KEY idx_hv_status_time (status, reported_time)
    COMMENT 'Key: INDEX(status, reported_time). Description: Workflow tracking/reporting. ISO15189: Supports monitoring and review.',

  CONSTRAINT fk_hv_unit    FOREIGN KEY (unit_id) REFERENCES blood_units(id),
  CONSTRAINT fk_hv_patient FOREIGN KEY (patient_id) REFERENCES patients(id)
) ENGINE=InnoDB
COMMENT='Haemovigilance / incident records. ISO15189: Nonconformity and quality incident documentation with traceability.'
;

-- ---------------------------------------------------------------------
-- Clinical Seed Data (minimal)
-- ---------------------------------------------------------------------
INSERT INTO suppliers (name, supplier_code) VALUES
('Irish Blood Transfusion Service', 'IBTS');

INSERT INTO storage_locations (name, location_type) VALUES
('Fridge 1', 'RBC_FRIDGE'),
('Freezer A', 'PLASMA_FREEZER'),
('Platelet Agitator 1', 'PLATELET_AGITATOR');

INSERT INTO patients (mrn, first_name, last_name, dob, sex, abo_group, rhd) VALUES
('MRN-10001', 'Aoife', 'Byrne', '1998-04-12', 'F', 'A', '+'),
('MRN-10002', 'Conor', 'Murphy', '1985-09-30', 'M', 'O', '-');

INSERT INTO blood_units
(supplier_id, donation_number, component_type, abo_group, rhd, volume_ml, collection_datetime, expiry_datetime, status, storage_location_id, cmv_negative, irradiated, washed)
VALUES
(1, 'DN-000001', 'RBC', 'O', '-', 300, NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 20 DAY, 'IN_STORAGE', 1, 0, 0, 0),
(1, 'DN-000002', 'PLASMA', 'A', '+', 250, NOW() - INTERVAL 2 DAY, NOW() + INTERVAL 300 DAY, 'IN_STORAGE', 2, 0, 0, 0),
(1, 'DN-000003', 'PLATELETS', 'A', '+', 300, NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 3 DAY, 'IN_STORAGE', 3, 0, 0, 0);

INSERT INTO unit_events (unit_id, event_type, performed_by, notes)
VALUES
(1, 'RECEIPT', 'seed', 'Initial seed receipt'),
(2, 'RECEIPT', 'seed', 'Initial seed receipt'),
(3, 'RECEIPT', 'seed', 'Initial seed receipt');



-- =============================
-- DB2: GOVERNANCE (bloodlink_gov)
-- =============================
-- BloodLink Governance DB: customer orgs/facilities, licensing, RBAC, audit logs. ISO15189 alignment: access control + accountability + audit logging (supports controlled records governance; clinical data stays separate).';
CREATE DATABASE IF NOT EXISTS bloodlink_gov
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
USE bloodlink_gov;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS license_keys;
DROP TABLE IF EXISTS system_installations;
DROP TABLE IF EXISTS subscriptions;
DROP TABLE IF EXISTS incident_reports;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS facilities;
DROP TABLE IF EXISTS organisations;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Table: organisations
-- ---------------------------------------------------------------------
CREATE TABLE organisations (
  id INT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: INT (PK). Description: Internal identifier for an organisation/customer. ISO15189: Supports controlled customer/account records and access scoping.',

  name VARCHAR(255) NOT NULL
    COMMENT 'Type: VARCHAR(255). Description: Organisation name (hospital group, blood bank, health authority). ISO15189: Supports clear identification of accountable entities.',

  organisation_type ENUM('HOSPITAL','BLOOD_BANK','HEALTH_AUTHORITY','PRIVATE_PROVIDER','OTHER') NOT NULL
    COMMENT 'Type: ENUM. Description: Classification for governance controls and reporting. ISO15189: Supports role-appropriate governance and accountability structures.',

  registration_number VARCHAR(64) NULL
    COMMENT 'Type: VARCHAR(64). Description: Optional business/registry identifier. ISO15189: Supports traceable organisational identity where required.',

  country VARCHAR(64) NOT NULL DEFAULT 'IE'
    COMMENT 'Type: VARCHAR(64). Description: Country code/name for organisation. ISO15189: Supports jurisdictional governance and record context.',

  contact_email VARCHAR(255) NULL
    COMMENT 'Type: VARCHAR(255). Description: Primary governance contact email. ISO15189: Supports controlled communications for quality/system governance.',

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: TIMESTAMP. Description: Row creation timestamp. ISO15189: Audit-ready chronology for governance records.'
) ENGINE=InnoDB
COMMENT='Customer organisations and authorities. ISO15189: Governance support (controlled records ownership/access boundaries).'
;

-- ---------------------------------------------------------------------
-- Table: facilities
-- ---------------------------------------------------------------------
CREATE TABLE facilities (
  id INT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: INT (PK). Description: Internal identifier for a facility/site. ISO15189: Supports site-level governance and scope definition.',

  organisation_id INT NOT NULL
    COMMENT 'Type: INT (FK->organisations.id). Description: Parent organisation. ISO15189: Controlled scoping of records and accountability.',

  facility_name VARCHAR(255) NOT NULL
    COMMENT 'Type: VARCHAR(255). Description: Facility/site name (e.g., transfusion lab). ISO15189: Supports controlled identification of service locations.',

  address VARCHAR(255) NULL
    COMMENT 'Type: VARCHAR(255). Description: Street address (optional). ISO15189: Context for governance and support operations.',

  city VARCHAR(128) NULL
    COMMENT 'Type: VARCHAR(128). Description: City (optional). ISO15189: Context for governance records.',

  postcode VARCHAR(32) NULL
    COMMENT 'Type: VARCHAR(32). Description: Postcode (optional). ISO15189: Context for governance records.',

  country VARCHAR(64) NOT NULL DEFAULT 'IE'
    COMMENT 'Type: VARCHAR(64). Description: Country (default IE). ISO15189: Jurisdictional context.',

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: TIMESTAMP. Description: Row creation timestamp. ISO15189: Audit chronology.',

  KEY idx_fac_org (organisation_id)
    COMMENT 'Key: INDEX(organisation_id). Description: Fast facility lookup per org. ISO15189: Supports governance reporting and accountability.',

  CONSTRAINT fk_fac_org FOREIGN KEY (organisation_id) REFERENCES organisations(id)
) ENGINE=InnoDB
COMMENT='Facilities/sites belonging to organisations (e.g., hospital lab site). ISO15189: Supports scope definition and governance.'
;

-- ---------------------------------------------------------------------
-- Table: roles (RBAC)
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: INT (PK). Description: Internal identifier for a role. ISO15189: Access control support.',

  role_name VARCHAR(64) NOT NULL
    COMMENT 'Type: VARCHAR(64) (UK). Description: Role code (e.g., ORG_ADMIN). ISO15189: Controlled access roles to protect record integrity.',

  description TEXT NULL
    COMMENT 'Type: TEXT. Description: Human-readable description of role. ISO15189: Documented responsibilities and access scope.',

  UNIQUE KEY uniq_roles_role_name (role_name)
    COMMENT 'Key: UNIQUE(role_name). Description: Prevents duplicate role definitions. ISO15189: Data integrity for access control.'
) ENGINE=InnoDB
COMMENT='Role definitions for RBAC. ISO15189: Controlled access + accountability support.'
;

-- ---------------------------------------------------------------------
-- Table: users
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: INT (PK). Description: Internal identifier for a user account. ISO15189: Accountability for actions on controlled records.',

  organisation_id INT NOT NULL
    COMMENT 'Type: INT (FK->organisations.id). Description: Organisation the user belongs to. ISO15189: Access scoping by accountable entity.',

  role_id INT NOT NULL
    COMMENT 'Type: INT (FK->roles.id). Description: Role assigned to user. ISO15189: Role-based access control to protect record integrity.',

  email VARCHAR(255) NOT NULL
    COMMENT 'Type: VARCHAR(255) (UK). Description: Login identifier (email). ISO15189: Unique user identity for accountability.',

  password_hash VARCHAR(255) NOT NULL
    COMMENT 'Type: VARCHAR(255). Description: Password hash (never store plaintext). ISO15189: Security controls support record integrity and confidentiality.',

  is_active BOOLEAN NOT NULL DEFAULT TRUE
    COMMENT 'Type: BOOLEAN. Description: Account active flag. ISO15189: Controlled access to systems handling records.',

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: TIMESTAMP. Description: Account creation timestamp. ISO15189: Audit chronology for access records.',

  UNIQUE KEY uniq_users_email (email)
    COMMENT 'Key: UNIQUE(email). Description: Prevents duplicate user identities. ISO15189: Accountability and access integrity.',

  KEY idx_users_org (organisation_id)
    COMMENT 'Key: INDEX(organisation_id). Description: User lookup per organisation. ISO15189: Governance reporting and access administration.',

  KEY idx_users_role (role_id)
    COMMENT 'Key: INDEX(role_id). Description: User lookup per role. ISO15189: Access control oversight.',

  CONSTRAINT fk_users_org  FOREIGN KEY (organisation_id) REFERENCES organisations(id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB
COMMENT='User accounts for governance portal and administration. ISO15189: Accountability + controlled access support.'
;

-- ---------------------------------------------------------------------
-- Table: subscriptions
-- ---------------------------------------------------------------------
CREATE TABLE subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: INT (PK). Description: Subscription record identifier. ISO15189: Governance control over authorised system use.',

  organisation_id INT NOT NULL
    COMMENT 'Type: INT (FK->organisations.id). Description: Organisation receiving subscription/licence. ISO15189: Controlled scope of authorised access.',

  plan_type ENUM('TRIAL','STANDARD','ENTERPRISE','HSE_FRAMEWORK','CUSTOM') NOT NULL DEFAULT 'TRIAL'
    COMMENT 'Type: ENUM. Description: Commercial/governance plan class. ISO15189: Supports documented scope and service controls.',

  start_date DATE NOT NULL
    COMMENT 'Type: DATE. Description: Subscription start date. ISO15189: Governance/authorisation chronology.',

  end_date DATE NULL
    COMMENT 'Type: DATE. Description: Subscription end date (if applicable). ISO15189: Governance/authorisation controls.',

  status ENUM('ACTIVE','PAST_DUE','CANCELLED','ENDED') NOT NULL DEFAULT 'ACTIVE'
    COMMENT 'Type: ENUM. Description: Subscription status. ISO15189: Supports controlled authorisation of system access.',

  KEY idx_sub_org (organisation_id)
    COMMENT 'Key: INDEX(organisation_id). Description: Subscription lookup per org. ISO15189: Governance oversight.',

  CONSTRAINT fk_sub_org FOREIGN KEY (organisation_id) REFERENCES organisations(id)
) ENGINE=InnoDB
COMMENT='Commercial/governance subscriptions. ISO15189: Supports controlled authorisation (system governance).'
;

-- ---------------------------------------------------------------------
-- Table: system_installations
-- ---------------------------------------------------------------------
CREATE TABLE system_installations (
  id INT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: INT (PK). Description: Installation record identifier. ISO15189: Governance record of deployed system instances.',

  facility_id INT NOT NULL
    COMMENT 'Type: INT (FK->facilities.id). Description: Facility where system is installed. ISO15189: Scope and accountability by site.',

  installation_uuid CHAR(36) NOT NULL
    COMMENT 'Type: CHAR(36) (UK). Description: Stable installation identity (UUID). ISO15189: Supports unambiguous identification of system instance for audits.',

  environment ENUM('DEV','UAT','PROD') NOT NULL DEFAULT 'DEV'
    COMMENT 'Type: ENUM. Description: Deployment environment. ISO15189: Supports separation of test vs production controls.',

  version VARCHAR(64) NULL
    COMMENT 'Type: VARCHAR(64). Description: Installed application version. ISO15189: Supports change control, validation context, and audit readiness.',

  installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: DATETIME. Description: Installation timestamp. ISO15189: Change/audit chronology.',

  active BOOLEAN NOT NULL DEFAULT TRUE
    COMMENT 'Type: BOOLEAN. Description: Whether installation is active/authorised. ISO15189: Governance control over authorised use.',

  UNIQUE KEY uniq_install_uuid (installation_uuid)
    COMMENT 'Key: UNIQUE(installation_uuid). Description: Prevents duplicate installation identities. ISO15189: Data integrity for governance.',

  KEY idx_inst_fac (facility_id)
    COMMENT 'Key: INDEX(facility_id). Description: Installations per facility. ISO15189: Scope tracking and audits.',

  CONSTRAINT fk_inst_fac FOREIGN KEY (facility_id) REFERENCES facilities(id)
) ENGINE=InnoDB
COMMENT='Records of deployed BloodLink installations by facility. ISO15189: Supports change control and auditable deployment governance.'
;

-- ---------------------------------------------------------------------
-- Table: license_keys
-- ---------------------------------------------------------------------
CREATE TABLE license_keys (
  id INT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: INT (PK). Description: License key record identifier. ISO15189: Governance control of authorised system operation.',

  installation_id INT NOT NULL
    COMMENT 'Type: INT (FK->system_installations.id). Description: Installation the licence applies to. ISO15189: Scope-bound authorisation.',

  license_key VARCHAR(128) NOT NULL
    COMMENT 'Type: VARCHAR(128) (UK). Description: Licence token used to authorise an installation. ISO15189: Access control and authorisation support.',

  valid_until DATE NULL
    COMMENT 'Type: DATE. Description: Expiry of licence (if applicable). ISO15189: Controlled authorisation over time.',

  active BOOLEAN NOT NULL DEFAULT TRUE
    COMMENT 'Type: BOOLEAN. Description: Licence active flag. ISO15189: Controlled access/authorisation.',

  UNIQUE KEY uniq_license_key (license_key)
    COMMENT 'Key: UNIQUE(license_key). Description: Prevents duplicate licence tokens. ISO15189: Data integrity for authorisation controls.',

  KEY idx_lic_install (installation_id)
    COMMENT 'Key: INDEX(installation_id). Description: Licence lookup by installation. ISO15189: Governance oversight.',

  CONSTRAINT fk_lic_install FOREIGN KEY (installation_id) REFERENCES system_installations(id)
) ENGINE=InnoDB
COMMENT='Licence keys binding installations to authorisation state. ISO15189: Supports controlled authorisation and auditability.'
;

-- ---------------------------------------------------------------------
-- Table: audit_logs
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: BIGINT (PK). Description: Audit event identifier. ISO15189: Audit trail for governance actions.',

  user_id INT NOT NULL
    COMMENT 'Type: INT (FK->users.id). Description: User who performed the action. ISO15189: Accountability (who did what).',

  action VARCHAR(128) NOT NULL
    COMMENT 'Type: VARCHAR(128). Description: Action name (e.g., CREATE_ORG, ISSUE_LICENSE). ISO15189: Controlled record of system actions affecting governance.',

  entity_type VARCHAR(128) NOT NULL
    COMMENT 'Type: VARCHAR(128). Description: Entity category affected (e.g., ORGANISATION, INSTALLATION). ISO15189: Traceable context for audit records.',

  entity_id BIGINT NULL
    COMMENT 'Type: BIGINT. Description: Entity identifier affected (nullable where not applicable). ISO15189: Traceable linkage for audit review.',

  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: DATETIME. Description: Timestamp action occurred. ISO15189: Chronology of controlled system actions.',

  metadata_json JSON NULL
    COMMENT 'Type: JSON. Description: Structured metadata (e.g., before/after values, IP, request id). ISO15189: Supports evidence for investigations and audits.',

  KEY idx_audit_user_time (user_id, occurred_at)
    COMMENT 'Key: INDEX(user_id, occurred_at). Description: User activity timeline. ISO15189: Accountability and review.',

  KEY idx_audit_entity (entity_type, entity_id)
    COMMENT 'Key: INDEX(entity_type, entity_id). Description: Entity history timeline. ISO15189: Traceability of governance changes.',

  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB
COMMENT='Governance audit trail. ISO15189: Supports accountability, integrity of records governance, and audit readiness.'
;

-- ---------------------------------------------------------------------
-- Table: incident_reports
-- ---------------------------------------------------------------------
CREATE TABLE incident_reports (
  id BIGINT AUTO_INCREMENT PRIMARY KEY
    COMMENT 'Type: BIGINT (PK). Description: Incident ticket identifier. ISO15189: Controlled record of system incidents and corrective actions.',

  organisation_id INT NOT NULL
    COMMENT 'Type: INT (FK->organisations.id). Description: Organisation reporting the incident. ISO15189: Accountability and scope context.',

  severity ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'LOW'
    COMMENT 'Type: ENUM. Description: Severity level for triage. ISO15189: Supports risk management and escalation processes.',

  category VARCHAR(128) NOT NULL
    COMMENT 'Type: VARCHAR(128). Description: Category (e.g., OUTAGE, SECURITY, DATA_ISSUE). ISO15189: Controlled classification supporting quality management.',

  description TEXT NOT NULL
    COMMENT 'Type: TEXT. Description: Incident description. ISO15189: Controlled documentation for investigation and corrective actions.',

  status ENUM('OPEN','UNDER_REVIEW','RESOLVED','CLOSED') NOT NULL DEFAULT 'OPEN'
    COMMENT 'Type: ENUM. Description: Workflow state. ISO15189: Supports tracking of nonconformities/corrective actions.',

  reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Type: DATETIME. Description: Time reported. ISO15189: Chronology and audit readiness.',

  KEY idx_inc_org_time (organisation_id, reported_at)
    COMMENT 'Key: INDEX(organisation_id, reported_at). Description: Incident timeline per org. ISO15189: Supports review and oversight.',

  CONSTRAINT fk_inc_org FOREIGN KEY (organisation_id) REFERENCES organisations(id)
) ENGINE=InnoDB
COMMENT='Governance support/incident records (non-clinical). ISO15189: Quality management support for system governance.'
;

-- ---------------------------------------------------------------------
-- Governance Seed Data (minimal)
-- ---------------------------------------------------------------------
INSERT INTO roles (role_name, description) VALUES
('GOV_ADMIN', 'BloodLink internal governance admin'),
('ORG_ADMIN', 'Customer organisation admin'),
('CLINICAL_STAFF', 'Hospital/blood bank operational user'),
('AUDITOR', 'Read-only audit access');

INSERT INTO organisations (name, organisation_type, registration_number, country, contact_email) VALUES
('St. Example Hospital', 'HOSPITAL', 'IE-EX-0001', 'IE', 'it@stexample.ie'),
('Example Blood Bank', 'BLOOD_BANK', 'IE-EX-0002', 'IE', 'ops@examplebloodbank.ie');

INSERT INTO facilities (organisation_id, facility_name, address, city, postcode, country) VALUES
(1, 'St. Example Hospital - Transfusion Lab', '1 Example Rd', 'Dublin', 'D02 XXXX', 'IE'),
(2, 'Example Blood Bank - Central Store', '2 Example Rd', 'Dublin', 'D01 YYYY', 'IE');

INSERT INTO subscriptions (organisation_id, plan_type, start_date, end_date, status) VALUES
(1, 'TRIAL', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'ACTIVE'),
(2, 'TRIAL', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'ACTIVE');

INSERT INTO system_installations (facility_id, installation_uuid, environment, version, active) VALUES
(1, '11111111-1111-1111-1111-111111111111', 'DEV', '0.1.0', TRUE),
(2, '22222222-2222-2222-2222-222222222222', 'DEV', '0.1.0', TRUE);

INSERT INTO license_keys (installation_id, license_key, valid_until, active) VALUES
(1, 'LIC-DEV-STEXAMPLE-0001', DATE_ADD(CURDATE(), INTERVAL 365 DAY), TRUE),
(2, 'LIC-DEV-EXBLOODBANK-0001', DATE_ADD(CURDATE(), INTERVAL 365 DAY), TRUE);

-- Placeholder password hash (replace via app using password_hash())
INSERT INTO users (organisation_id, role_id, email, password_hash, is_active) VALUES
(1, 2, 'admin@stexample.ie', '$2y$10$REPLACE_ME_WITH_PASSWORD_HASH', TRUE);

INSERT INTO audit_logs (user_id, action, entity_type, entity_id, metadata_json) VALUES
(1, 'SEED', 'DATABASE', NULL, JSON_OBJECT('note','Initial seed complete'));