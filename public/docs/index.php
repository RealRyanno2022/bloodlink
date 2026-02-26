<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

/* =========================
   Config
   ========================= */
$DBS = [
  'clinical' => [
    'label' => 'Clinical',
    'badge' => 'bloodlink',
    'dbname' => 'bloodlink',
    'host' => '127.0.0.1',
    'port' => 3306,
    'user' => 'root',
    'pass' => '',
  ],
  'gov' => [
    'label' => 'Governance',
    'badge' => 'bloodlink_gov',
    'dbname' => 'bloodlink_gov',
    'host' => '127.0.0.1',
    'port' => 3306,
    'user' => 'root',
    'pass' => '',
  ],
];

$PAGE_SIZE_DEFAULT = 25;
$PAGE_SIZE_MAX = 100;

/* "audit spine" tables: show but do not allow edit/delete */
$IMMUTABLE_TABLES = [
  'bloodlink' => ['unit_events'],
  'bloodlink_gov' => ['audit_logs'],
];

/* =========================
   Nurse/Exec semantics
   ========================= */
$FRIENDLY = [
  'bloodlink' => [
    'blood_units'            => ['Blood Inventory', 'Units currently held in storage/stock.'],
    'patients'               => ['Patient Registry', 'Patients linked to issues/transfusions.'],
    'issues'                 => ['Issued Units', 'Units issued to patients (traceability snapshot).'],
    'transfusions'           => ['Transfusion Outcomes', 'Final fate of issued units.'],
    'unit_events'            => ['Unit Audit Trail', 'Immutable timeline of unit lifecycle actions.'],
    'haemovigilance_reports' => ['Adverse Events', 'Haemovigilance / incident reporting.'],
    'storage_locations'      => ['Storage Locations', 'Fridges, freezers, platelet agitators.'],
    'suppliers'              => ['Suppliers', 'External providers / IBTS etc.'],
  ],
  'bloodlink_gov' => [
    'organisations'          => ['Organisations', 'Customers / hospital groups / authorities.'],
    'facilities'             => ['Facilities', 'Sites belonging to organisations.'],
    'users'                  => ['Users', 'Accounts scoped by organisation + role.'],
    'roles'                  => ['Roles', 'RBAC role definitions.'],
    'subscriptions'          => ['Subscriptions', 'Commercial / governance plans.'],
    'system_installations'   => ['Installations', 'Deployed instances by facility.'],
    'license_keys'           => ['Licence Keys', 'Authorisation tokens per installation.'],
    'audit_logs'             => ['Governance Audit', 'Immutable governance audit trail.'],
    'incident_reports'       => ['Support Tickets', 'Non-clinical incidents and support workflow.'],
  ],
];

$STATUS_CLASS = [
  'RECEIVED'    => 'st-blue',
  'IN_STORAGE'  => 'st-green',
  'ISSUED'      => 'st-amber',
  'TRANSFUSED'  => 'st-purple',
  'RETURNED'    => 'st-slate',
  'DISCARDED'   => 'st-red',
  'QUARANTINED' => 'st-red',
  'EXPIRED'     => 'st-red',
  'OPEN'        => 'st-amber',
  'UNDER_REVIEW'=> 'st-blue',
  'SUBMITTED'   => 'st-purple',
  'CLOSED'      => 'st-green',
  'RESOLVED'    => 'st-green',
  'ACTIVE'      => 'st-green',
  'PAST_DUE'    => 'st-amber',
  'CANCELLED'   => 'st-red',
  'ENDED'       => 'st-slate',
];













/* =========================
   Helpers
   ========================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function pdo_for(array $cfg): PDO {
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['dbname']);
  return new PDO($dsn, $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
}

function clamp_int($v, int $min, int $max, int $default): int {
  if ($v === null || $v === '') return $default;
  if (!is_numeric($v)) return $default;
  $n = (int)$v;
  return max($min, min($max, $n));
}

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}

function csrf_check(): void {
  $tok = $_POST['csrf'] ?? '';
  if (!$tok || !hash_equals($_SESSION['csrf'] ?? '', $tok)) {
    http_response_code(403);
    echo "CSRF check failed.";
    exit;
  }
}

function schema_tables(PDO $pdo, string $dbName): array {
  $sql = "SELECT TABLE_NAME, TABLE_COMMENT
          FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = :db
          ORDER BY TABLE_NAME";
  $st = $pdo->prepare($sql);
  $st->execute(['db' => $dbName]);
  return $st->fetchAll();
}

function schema_columns(PDO $pdo, string $dbName, string $table): array {
  $sql = "SELECT
            COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE,
            COLUMN_DEFAULT, COLUMN_KEY, EXTRA, COLUMN_COMMENT, ORDINAL_POSITION
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t
          ORDER BY ORDINAL_POSITION";
  $st = $pdo->prepare($sql);
  $st->execute(['db' => $dbName, 't' => $table]);
  return $st->fetchAll();
}

function schema_primary_key(PDO $pdo, string $dbName, string $table): ?string {
  $sql = "SELECT COLUMN_NAME
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_KEY = 'PRI'
          ORDER BY ORDINAL_POSITION
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute(['db' => $dbName, 't' => $table]);
  $row = $st->fetch();
  return $row ? $row['COLUMN_NAME'] : null;
}

function parse_enum_values(string $columnType): ?array {
  if (!preg_match("/^enum\\((.*)\\)$/i", $columnType, $m)) return null;
  $inside = $m[1];
  $parts = preg_split("/,(?=(?:[^']*'[^']*')*[^']*$)/", $inside);
  $vals = [];
  foreach ($parts as $p) {
    $p = trim($p);
    $p = preg_replace("/^'(.*)'$/s", "$1", $p);
    $p = str_replace("\\'", "'", $p);
    $vals[] = $p;
  }
  return $vals;
}

function is_boolish(array $col): bool {
  $dt = strtolower($col['DATA_TYPE'] ?? '');
  if ($dt === 'boolean') return true;
  if ($dt === 'tinyint' && preg_match('/tinyint\\(1\\)/i', $col['COLUMN_TYPE'] ?? '')) return true;
  return false;
}

function is_auto_increment(array $col): bool {
  return stripos($col['EXTRA'] ?? '', 'auto_increment') !== false;
}

function col_required(array $col): bool {
  return ($col['IS_NULLABLE'] ?? '') === 'NO' && ($col['COLUMN_DEFAULT'] === null) && !is_auto_increment($col);
}

function type_badge(string $dataType): string {
  $t = strtolower($dataType);
  $cls = match ($t) {
    'int','bigint','smallint','mediumint','tinyint' => 'badge text-bg-primary',
    'varchar','text','longtext','mediumtext' => 'badge text-bg-secondary',
    'date','datetime','timestamp' => 'badge text-bg-info',
    'enum' => 'badge text-bg-warning',
    'json' => 'badge text-bg-dark',
    default => 'badge text-bg-light text-dark',
  };
  return '<span class="'.$cls.'">'.h($t).'</span>';
}

/* FK discovery: column -> (ref_table, ref_column) */
function schema_foreign_keys(PDO $pdo, string $dbName, string $table): array {
  $sql = "SELECT
            COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
          FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = :db
            AND TABLE_NAME = :t
            AND REFERENCED_TABLE_NAME IS NOT NULL";
  $st = $pdo->prepare($sql);
  $st->execute(['db' => $dbName, 't' => $table]);
  $out = [];
  foreach ($st->fetchAll() as $r) {
    $out[$r['COLUMN_NAME']] = [
      'ref_table' => $r['REFERENCED_TABLE_NAME'],
      'ref_col' => $r['REFERENCED_COLUMN_NAME'],
    ];
  }
  return $out;
}

/* FK options: (id -> label) */
function fk_options(PDO $pdo, string $refTable, string $refCol): array {
  // best-effort label column
  $cols = $pdo->query("SHOW COLUMNS FROM `{$refTable}`")->fetchAll();
  $names = array_map(fn($c) => $c['Field'], $cols);

  $labelCol = null;
  foreach (['name','title','facility_name','email','mrn','donation_number','installation_uuid','role_name'] as $cand) {
    if (in_array($cand, $names, true)) { $labelCol = $cand; break; }
  }
  if ($labelCol === null) $labelCol = $refCol;

  $sql = "SELECT `{$refCol}` AS id, `{$labelCol}` AS label
          FROM `{$refTable}`
          ORDER BY 2
          LIMIT 500";
  $st = $pdo->query($sql);
  $rows = $st->fetchAll();
  $out = [];
  foreach ($rows as $r) $out[(string)$r['id']] = (string)$r['label'];
  return $out;
}

function render_input(PDO $pdo, array $col, $value, string $name, array $fkMap): string {
  $dataType = strtolower($col['DATA_TYPE'] ?? '');
  $colType  = $col['COLUMN_TYPE'] ?? '';
  $nullable = (($col['IS_NULLABLE'] ?? '') === 'YES');
  $req = col_required($col);

  // FK dropdown
  if (isset($fkMap[$name])) {
    $refTable = $fkMap[$name]['ref_table'];
    $refCol = $fkMap[$name]['ref_col'];
    $opts = fk_options($pdo, $refTable, $refCol);

    $html = '<select class="form-select" name="'.h($name).'">';
    if ($nullable) $html .= '<option value="">(null)</option>';
    foreach ($opts as $id => $label) {
      $sel = ((string)$value === (string)$id) ? ' selected' : '';
      $html .= '<option value="'.h($id).'"'.$sel.'>'.h($label).' ('.h($id).')</option>';
    }
    $html .= '</select>';
    return $html;
  }

  // ENUM dropdown
  $enumVals = parse_enum_values($colType);
  if ($enumVals !== null) {
    $html = '<select class="form-select" name="'.h($name).'">';
    if ($nullable) $html .= '<option value="">(null)</option>';
    foreach ($enumVals as $v) {
      $sel = ((string)$value === (string)$v) ? ' selected' : '';
      $html .= '<option value="'.h($v).'"'.$sel.'>'.h($v).'</option>';
    }
    $html .= '</select>';
    return $html;
  }

  // BOOL checkbox
  if (is_boolish($col)) {
    $checked = ((string)$value === '1') ? ' checked' : '';
    return '<div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" value="1" name="'.h($name).'"'.$checked.'>
            </div>';
  }

  $type = 'text';
  if (in_array($dataType, ['int','bigint','smallint','mediumint','tinyint'], true)) $type = 'number';
  if ($dataType === 'date') $type = 'date';
  if (in_array($dataType, ['datetime','timestamp'], true)) $type = 'datetime-local';

  $val = ($value === null) ? '' : (string)$value;
  if ($type === 'datetime-local' && $val !== '') $val = str_replace(' ', 'T', substr($val, 0, 16));

  $reqAttr = $req ? ' required' : '';
  return '<input class="form-control" type="'.$type.'" name="'.h($name).'" value="'.h($val).'"'.$reqAttr.'>';
}

/* =========================
   Params
   ========================= */
$dbKey = $_GET['db'] ?? 'clinical';
if (!isset($DBS[$dbKey])) $dbKey = 'clinical';

$cfg = $DBS[$dbKey];
$pdo = pdo_for($cfg);
$dbName = $cfg['dbname'];

$tables = schema_tables($pdo, $dbName);
$tableNames = array_map(fn($t) => $t['TABLE_NAME'], $tables);

$table = $_GET['table'] ?? ($tables[0]['TABLE_NAME'] ?? '');
if ($table && !in_array($table, $tableNames, true)) $table = ($tables[0]['TABLE_NAME'] ?? '');

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list','create','edit'], true)) $action = 'list';

$rowId = $_GET['id'] ?? null;
if ($rowId !== null && $rowId !== '' && !preg_match('/^[0-9]+$/', (string)$rowId)) $rowId = null;

$page = clamp_int($_GET['page'] ?? null, 1, 1_000_000, 1);
$pageSize = clamp_int($_GET['page_size'] ?? null, 1, $PAGE_SIZE_MAX, $PAGE_SIZE_DEFAULT);

$q = trim((string)($_GET['q'] ?? ''));

$pk = $table ? schema_primary_key($pdo, $dbName, $table) : null;
$cols = $table ? schema_columns($pdo, $dbName, $table) : [];
$fkMap = $table ? schema_foreign_keys($pdo, $dbName, $table) : [];

$isImmutable = in_array($table, $IMMUTABLE_TABLES[$dbName] ?? [], true);

/* =========================
   CRUD
   ========================= */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $table) {
  csrf_check();

  $do = $_POST['do'] ?? '';
  $colMap = [];
  foreach ($cols as $c) $colMap[$c['COLUMN_NAME']] = $c;

  if ($do === 'create') {
    if ($isImmutable) {
      $flash = "Create disabled for immutable table: {$table}";
    } else {
      $fields = [];
      $params = [];

      foreach ($colMap as $name => $c) {
        if (is_auto_increment($c)) continue;
        if ($name === $pk) continue;

        if (!array_key_exists($name, $_POST)) continue;
        $raw = $_POST[$name];

        if (is_boolish($c)) {
          $val = ($raw === '1' || $raw === 'on' || $raw === 'true') ? 1 : 0;
        } else {
          $val = ($raw === '') ? null : $raw;
        }

        if (($c['IS_NULLABLE'] ?? '') === 'NO' && $val === null && $c['COLUMN_DEFAULT'] === null) {
          $flash = "Missing required field: {$name}";
          break;
        }

        $fields[] = "`{$name}`";
        $params[":{$name}"] = $val;
      }

      if ($flash === null) {
        if (!$fields) $flash = "No fields provided.";
        else {
          $sql = "INSERT INTO `{$table}` (".implode(',', $fields).") VALUES (".implode(',', array_keys($params)).")";
          $st = $pdo->prepare($sql);
          $st->execute($params);
          $flash = "Created (id: ".$pdo->lastInsertId().")";
          $action = 'list';
        }
      }
    }
  }

  if ($do === 'edit') {
    if ($isImmutable) {
      $flash = "Edit disabled for immutable table: {$table}";
    } elseif (!$pk) {
      $flash = "No primary key detected; edit disabled.";
    } else {
      $id = $_POST['id'] ?? '';
      if (!preg_match('/^[0-9]+$/', (string)$id)) $flash = "Invalid id.";
      else {
        $sets = [];
        $params = [':id' => (int)$id];

        foreach ($colMap as $name => $c) {
          if ($name === $pk) continue;
          if (!array_key_exists($name, $_POST)) continue;

          $raw = $_POST[$name];
          if (is_boolish($c)) $val = ($raw === '1' || $raw === 'on' || $raw === 'true') ? 1 : 0;
          else $val = ($raw === '') ? null : $raw;

          if (($c['IS_NULLABLE'] ?? '') === 'NO' && $val === null && $c['COLUMN_DEFAULT'] === null) {
            $flash = "Missing required field: {$name}";
            break;
          }

          $sets[] = "`{$name}` = :{$name}";
          $params[":{$name}"] = $val;
        }

        if ($flash === null) {
          if (!$sets) $flash = "No changes provided.";
          else {
            $sql = "UPDATE `{$table}` SET ".implode(',', $sets)." WHERE `{$pk}` = :id LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $flash = "Updated row {$id}.";
            $action = 'list';
          }
        }
      }
    }
  }
}

/* =========================
   Data for views
   ========================= */
$rows = [];
$totalRows = null;
$editRow = null;

if ($table && $action === 'list') {
  $offset = ($page - 1) * $pageSize;

  // search: naive OR across text-ish columns, plus id exact where possible
  $where = "";
  $bind = [];
  if ($q !== "") {
    $ors = [];
    foreach ($cols as $c) {
      $dt = strtolower($c['DATA_TYPE']);
      $cn = $c['COLUMN_NAME'];

      if (in_array($dt, ['varchar','text','mediumtext','longtext','char'], true)) {
        $ors[] = "`{$cn}` LIKE :q";
      }
      if ($cn === $pk && preg_match('/^[0-9]+$/', $q)) {
        $ors[] = "`{$cn}` = :qid";
        $bind[':qid'] = (int)$q;
      }
    }
    if ($ors) {
      $where = " WHERE (" . implode(" OR ", $ors) . ")";
      $bind[':q'] = "%{$q}%";
    }
  }

  $st = $pdo->prepare("SELECT COUNT(*) AS c FROM `{$table}`{$where}");
  $st->execute($bind);
  $totalRows = (int)($st->fetch()['c'] ?? 0);

  $sql = "SELECT * FROM `{$table}`{$where} ORDER BY 1 DESC LIMIT :lim OFFSET :off";
  $st = $pdo->prepare($sql);
  foreach ($bind as $k => $v) $st->bindValue($k, $v);
  $st->bindValue(':lim', $pageSize, PDO::PARAM_INT);
  $st->bindValue(':off', $offset, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
}

if ($table && $action === 'edit' && $pk && $rowId !== null) {
  $st = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$pk}` = :id LIMIT 1");
  $st->execute([':id' => (int)$rowId]);
  $editRow = $st->fetch() ?: null;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BloodLink Console</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --bg: #0b1220;
      --panel: #0f1a2e;
      --panel2: #0c1628;
      --stroke: rgba(255,255,255,.08);
      --text: rgba(255,255,255,.92);
      --muted: rgba(255,255,255,.62);
      --accent: #7dd3fc;
    }
    body { background: radial-gradient(1200px 600px at 20% -10%, rgba(125,211,252,.15), transparent 60%), var(--bg); color: var(--text); }
    .topbar { border-bottom: 1px solid var(--stroke); background: rgba(15,26,46,.6); backdrop-filter: blur(10px); }
    .brand { letter-spacing: .2px; }
    .pill { border: 1px solid var(--stroke); background: rgba(255,255,255,.04); }
    .sidebar { background: rgba(15,26,46,.55); border: 1px solid var(--stroke); border-radius: 16px; }
    .panel { background: rgba(15,26,46,.55); border: 1px solid var(--stroke); border-radius: 16px; }
    .muted { color: var(--muted); }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; }
    .list-group-item { background: transparent; border-color: var(--stroke); color: var(--text); }
    .list-group-item.active { background: rgba(125,211,252,.12); border-color: rgba(125,211,252,.25); }
    .list-group-item:hover { background: rgba(255,255,255,.04); }
    .table { --bs-table-bg: transparent; --bs-table-color: var(--text); --bs-table-border-color: var(--stroke); }
    .table thead th { position: sticky; top: 0; background: rgba(12,22,40,.95); backdrop-filter: blur(8px); }
    .btn-outline-light { border-color: var(--stroke); color: var(--text); }
    .btn-outline-light:hover { background: rgba(255,255,255,.06); }
    .form-control, .form-select { background: rgba(12,22,40,.75); border-color: var(--stroke); color: var(--text); }
    .form-control::placeholder { color: rgba(255,255,255,.35); }
    .form-control:focus, .form-select:focus { border-color: rgba(125,211,252,.45); box-shadow: 0 0 0 .2rem rgba(125,211,252,.12); }
    pre { background: rgba(12,22,40,.75); border: 1px solid var(--stroke); border-radius: 12px; padding: 12px; }
    .comment { font-size: 12px; color: rgba(255,255,255,.6); }
    .soft { background: rgba(255,255,255,.03); border: 1px solid var(--stroke); border-radius: 14px; }
  </style>
</head>
<body>

<nav class="topbar py-3">
  <div class="container-fluid" style="max-width: 1500px;">
    <div class="d-flex align-items-center justify-content-between gap-3">
      <div class="d-flex align-items-center gap-3">
        <div class="brand fw-semibold">BloodLink Console</div>
        <span class="badge pill text-light mono"><?= h($DBS[$dbKey]['badge']) ?></span>
        <?php if ($table): ?>
          <span class="badge pill text-light mono"><?= h($table) ?></span>
        <?php endif; ?>
      </div>

      <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm <?= $dbKey==='clinical' ? 'btn-light' : 'btn-outline-light' ?>"
           href="?db=clinical<?= $table ? '&table='.h($table) : '' ?>&action=list">Clinical</a>
        <a class="btn btn-sm <?= $dbKey==='gov' ? 'btn-light' : 'btn-outline-light' ?>"
           href="?db=gov<?= $table ? '&table='.h($table) : '' ?>&action=list">Governance</a>
      </div>
    </div>
  </div>
</nav>

<div class="container-fluid py-4" style="max-width: 1500px;">

  <?php if ($flash): ?>
    <div class="alert alert-info soft"><?= h($flash) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-3">
      <div class="sidebar p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold">Tables</div>
          <span class="muted small"><?= h(count($tables)) ?></span>
        </div>

        <input class="form-control mb-3" id="tableFilter" placeholder="Filter tables…">

        <div class="list-group" id="tableList" style="max-height: 72vh; overflow:auto;">
          <?php foreach ($tables as $t): ?>
            <?php $active = ($t['TABLE_NAME'] === $table); ?>
            <a class="list-group-item list-group-item-action <?= $active ? 'active' : '' ?>"
               href="?db=<?= h($dbKey) ?>&table=<?= h($t['TABLE_NAME']) ?>&action=list">
              <div class="d-flex justify-content-between align-items-center">
                <span class="mono"><?= h($t['TABLE_NAME']) ?></span>
              </div>
              <?php if (!empty($t['TABLE_COMMENT'])): ?>
                <div class="comment"><?= h($t['TABLE_COMMENT']) ?></div>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="mt-3 muted small">
          Schema-driven UI from <span class="mono">information_schema</span>.
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-9">
      <?php if (!$table): ?>
        <div class="panel p-4">No tables found.</div>
      <?php else: ?>
        <div class="panel p-4 mb-4">
          <div class="d-flex justify-content-between align-items-start gap-3">
            <div>
              <div class="mono fs-5"><?= h($dbName.'.'.$table) ?></div>
              <div class="muted">
                PK: <span class="mono"><?= h($pk ?? '(none)') ?></span>
                <?php if ($isImmutable): ?>
                  <span class="badge text-bg-warning ms-2">Immutable</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="d-flex gap-2">
              <a class="btn btn-sm <?= $action==='list' ? 'btn-light' : 'btn-outline-light' ?>"
                 href="?db=<?= h($dbKey) ?>&table=<?= h($table) ?>&action=list">List</a>

              <a class="btn btn-sm <?= $action==='create' ? 'btn-light' : 'btn-outline-light' ?> <?= $isImmutable ? 'disabled' : '' ?>"
                 href="?db=<?= h($dbKey) ?>&table=<?= h($table) ?>&action=create">Create</a>

              <a class="btn btn-sm <?= $action==='edit' ? 'btn-light' : 'btn-outline-light' ?> <?= (!$pk || $isImmutable) ? 'disabled' : '' ?>"
                 href="?db=<?= h($dbKey) ?>&table=<?= h($table) ?>&action=edit<?= $pk && $rowId ? '&id='.h((string)$rowId) : '' ?>">Edit</a>
            </div>
          </div>
        </div>

        <div class="panel p-4 mb-4">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold">Fields</div>
            <div class="muted small">Types / defaults / comments</div>
          </div>

          <div class="table-responsive" style="max-height: 320px; overflow:auto;">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Field</th>
                  <th>Type</th>
                  <th>Null</th>
                  <th>Default</th>
                  <th>Key</th>
                  <th>Extra</th>
                  <th>Comment</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cols as $c): ?>
                  <tr>
                    <td class="mono"><?= h($c['COLUMN_NAME']) ?></td>
                    <td class="mono"><?= h($c['COLUMN_TYPE']) ?></td>
                    <td class="muted"><?= h($c['IS_NULLABLE']) ?></td>
                    <td class="mono muted"><?= h($c['COLUMN_DEFAULT'] === null ? 'NULL' : (string)$c['COLUMN_DEFAULT']) ?></td>
                    <td class="mono"><?= h($c['COLUMN_KEY'] ?: '') ?></td>
                    <td class="mono muted"><?= h($c['EXTRA'] ?: '') ?></td>
                    <td class="comment"><?= h($c['COLUMN_COMMENT'] ?: '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php if ($action === 'list'): ?>
          <div class="panel p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
              <div class="fw-semibold">Rows</div>

              <form class="d-flex flex-wrap gap-2" method="get">
                <input type="hidden" name="db" value="<?= h($dbKey) ?>">
                <input type="hidden" name="table" value="<?= h($table) ?>">
                <input type="hidden" name="action" value="list">

                <input class="form-control" style="width: 240px" name="q" value="<?= h($q) ?>" placeholder="Search…">
                <input class="form-control" style="width: 110px" name="page" value="<?= h((string)$page) ?>" placeholder="page">
                <input class="form-control" style="width: 130px" name="page_size" value="<?= h((string)$pageSize) ?>" placeholder="page_size">

                <button class="btn btn-light">Go</button>
              </form>
            </div>

            <div class="muted small mb-2">
              Total: <strong><?= h((string)($totalRows ?? 0)) ?></strong>
              <?php if ($q !== ''): ?> • Filter: <span class="mono"><?= h($q) ?></span><?php endif; ?>
            </div>

            <div class="table-responsive" style="max-height: 62vh; overflow:auto;">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <?php foreach ($cols as $c): ?>
                      <th class="mono"><?= h($c['COLUMN_NAME']) ?></th>
                    <?php endforeach; ?>
                    <th style="width: 120px;">Open</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <?php foreach ($cols as $c): ?>
                        <?php
                          $cn = $c['COLUMN_NAME'];
                          $v = $r[$cn] ?? null;
                          $dt = strtolower($c['DATA_TYPE'] ?? '');
                        ?>
                        <td>
                          <?php if ($v === null): ?>
                            <span class="muted">NULL</span>
                          <?php else: ?>
                            <span class="mono"><?= h((string)$v) ?></span>
                            <span class="ms-2"><?= type_badge($dt === 'enum' ? 'enum' : $dt) ?></span>
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>

                      <td class="text-nowrap">
                        <?php if ($pk && isset($r[$pk]) && !$isImmutable): ?>
                          <a class="btn btn-sm btn-outline-light"
                             href="?db=<?= h($dbKey) ?>&table=<?= h($table) ?>&action=edit&id=<?= h((string)$r[$pk]) ?>">Edit</a>
                        <?php elseif ($pk && isset($r[$pk])): ?>
                          <a class="btn btn-sm btn-outline-light"
                             href="?db=<?= h($dbKey) ?>&table=<?= h($table) ?>&action=edit&id=<?= h((string)$r[$pk]) ?>">View</a>
                        <?php else: ?>
                          <span class="muted">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>

                  <?php if (!$rows): ?>
                    <tr><td colspan="<?= count($cols)+1 ?>" class="muted p-3">No rows.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($action === 'create'): ?>
          <div class="panel p-4">
            <div class="fw-semibold mb-3">Create row</div>

            <?php if ($isImmutable): ?>
              <div class="alert alert-warning soft">Create is disabled for immutable table: <?= h($table) ?></div>
            <?php else: ?>
              <form method="post" class="row g-3">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="do" value="create">

                <?php foreach ($cols as $c): ?>
                  <?php
                    $name = $c['COLUMN_NAME'];
                    if (is_auto_increment($c)) continue;
                    if ($name === $pk) continue;
                    $req = col_required($c);
                    $desc = $c['COLUMN_COMMENT'] ?? '';
                  ?>
                  <div class="col-12 col-md-6">
                    <div class="d-flex justify-content-between align-items-baseline">
                      <label class="form-label mb-1">
                        <span class="mono"><?= h($name) ?></span>
                        <?php if ($req): ?><span class="text-warning">*</span><?php endif; ?>
                      </label>
                      <span class="muted small mono"><?= h($c['COLUMN_TYPE']) ?></span>
                    </div>
                    <?= render_input($pdo, $c, $c['COLUMN_DEFAULT'], $name, $fkMap) ?>
                    <?php if ($desc): ?><div class="comment mt-1"><?= h($desc) ?></div><?php endif; ?>
                  </div>
                <?php endforeach; ?>

                <div class="col-12">
                  <button class="btn btn-light">Create</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($action === 'edit'): ?>
          <div class="panel p-4">
            <div class="fw-semibold mb-3"><?= $isImmutable ? 'View row' : 'Edit row' ?></div>

            <?php if (!$pk): ?>
              <div class="alert alert-warning soft">No primary key detected; edit disabled.</div>
            <?php elseif ($rowId === null): ?>
              <div class="alert alert-warning soft">Pick a row from List to open.</div>
            <?php elseif (!$editRow): ?>
              <div class="alert alert-warning soft">Row not found.</div>
            <?php else: ?>
              <form method="post" class="row g-3">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="do" value="edit">
                <input type="hidden" name="id" value="<?= h((string)$editRow[$pk]) ?>">

                <?php foreach ($cols as $c): ?>
                  <?php
                    $name = $c['COLUMN_NAME'];
                    $desc = $c['COLUMN_COMMENT'] ?? '';
                    $val = $editRow[$name] ?? null;
                    $readonly = ($name === $pk) || is_auto_increment($c) || $isImmutable;
                    $req = col_required($c);
                  ?>
                  <div class="col-12 col-md-6">
                    <div class="d-flex justify-content-between align-items-baseline">
                      <label class="form-label mb-1">
                        <span class="mono"><?= h($name) ?></span>
                        <?php if ($req): ?><span class="text-warning">*</span><?php endif; ?>
                      </label>
                      <span class="muted small mono"><?= h($c['COLUMN_TYPE']) ?></span>
                    </div>

                    <?php if ($readonly): ?>
                      <input class="form-control" value="<?= h($val === null ? 'NULL' : (string)$val) ?>" disabled>
                    <?php else: ?>
                      <?= render_input($pdo, $c, $val, $name, $fkMap) ?>
                    <?php endif; ?>

                    <?php if ($desc): ?><div class="comment mt-1"><?= h($desc) ?></div><?php endif; ?>
                  </div>
                <?php endforeach; ?>

                <div class="col-12">
                  <?php if (!$isImmutable): ?>
                    <button class="btn btn-light">Save</button>
                  <?php endif; ?>
                  <a class="btn btn-outline-light ms-2"
                     href="?db=<?= h($dbKey) ?>&table=<?= h($table) ?>&action=list">Back</a>
                </div>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>

</div>

<script>
  // Table filter (client-side)
  const input = document.getElementById('tableFilter');
  const list  = document.getElementById('tableList');
  if (input && list) {
    input.addEventListener('input', () => {
      const q = input.value.toLowerCase().trim();
      for (const a of list.querySelectorAll('a')) {
        const txt = a.innerText.toLowerCase();
        a.style.display = txt.includes(q) ? '' : 'none';
      }
    });
  }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>