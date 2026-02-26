<?php
declare(strict_types=1);

/*
  BloodLink Docs + CRUD (schema-driven)
  - Reads schema from information_schema for TWO DBs: bloodlink, bloodlink_gov
  - Generates:
      - table list
      - list view (paged)
      - create form
      - edit form
      - delete action
  - “Init is rules”: schema is the single source of truth.
*/

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();

/* =========================
   Config
   ========================= */
$DBS = [
  'clinical' => [
    'label' => 'Clinical (bloodlink)',
    'dbname' => 'bloodlink',
    'host' => '127.0.0.1',
    'port' => 3306,
    'user' => 'root',
    'pass' => '',
  ],
  'gov' => [
    'label' => 'Governance (bloodlink_gov)',
    'dbname' => 'bloodlink_gov',
    'host' => '127.0.0.1',
    'port' => 3306,
    'user' => 'root',
    'pass' => '',
  ],
];

$PAGE_SIZE_DEFAULT = 25;
$PAGE_SIZE_MAX = 100;

/* =========================
   Helpers
   ========================= */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function pdo_for(array $cfg): PDO {
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['dbname']);
  $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
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
            COLUMN_NAME,
            COLUMN_TYPE,
            DATA_TYPE,
            IS_NULLABLE,
            COLUMN_DEFAULT,
            COLUMN_KEY,
            EXTRA,
            COLUMN_COMMENT,
            ORDINAL_POSITION
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
  // columnType like: enum('A','B','C')
  if (!preg_match("/^enum\\((.*)\\)$/i", $columnType, $m)) return null;
  $inside = $m[1];

  // split on commas not inside quotes (simple approach; MySQL enums rarely include escaped commas)
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
  // MySQL BOOLEAN is TINYINT(1)
  if (strtolower($col['DATA_TYPE']) === 'tinyint' && preg_match('/tinyint\\(1\\)/i', $col['COLUMN_TYPE'])) return true;
  if (strtolower($col['DATA_TYPE']) === 'boolean') return true;
  return false;
}

function is_auto_increment(array $col): bool {
  return stripos($col['EXTRA'] ?? '', 'auto_increment') !== false;
}

function col_required(array $col): bool {
  return ($col['IS_NULLABLE'] ?? '') === 'NO' && ($col['COLUMN_DEFAULT'] === null) && !is_auto_increment($col);
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

function clamp_int($v, int $min, int $max, int $default): int {
  if ($v === null || $v === '') return $default;
  if (!is_numeric($v)) return $default;
  $n = (int)$v;
  return max($min, min($max, $n));
}

/* =========================
   Routing params
   ========================= */
$dbKey = $_GET['db'] ?? 'clinical';
if (!isset($DBS[$dbKey])) $dbKey = 'clinical';

$cfg = $DBS[$dbKey];
$pdo = pdo_for($cfg);
$dbName = $cfg['dbname'];

$tables = schema_tables($pdo, $dbName);
$table = $_GET['table'] ?? ($tables[0]['TABLE_NAME'] ?? '');
$tableNames = array_map(fn($t) => $t['TABLE_NAME'], $tables);
if ($table && !in_array($table, $tableNames, true)) $table = ($tables[0]['TABLE_NAME'] ?? '');

$action = $_GET['action'] ?? 'list';
$allowedActions = ['list','create','edit','delete'];
if (!in_array($action, $allowedActions, true)) $action = 'list';

$pk = $table ? schema_primary_key($pdo, $dbName, $table) : null;
$cols = $table ? schema_columns($pdo, $dbName, $table) : [];

$rowId = $_GET['id'] ?? null;
if ($rowId !== null && $rowId !== '' && !preg_match('/^[0-9]+$/', (string)$rowId)) $rowId = null;

$page = clamp_int($_GET['page'] ?? null, 1, 1_000_000, 1);
$pageSize = clamp_int($_GET['page_size'] ?? null, 1, $PAGE_SIZE_MAX, $PAGE_SIZE_DEFAULT);

/* =========================
   CRUD handlers
   ========================= */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $table) {
  csrf_check();

  $postAction = $_POST['do'] ?? '';
  if (!in_array($postAction, ['create','edit','delete'], true)) $postAction = '';

  // whitelist columns from schema
  $colMap = [];
  foreach ($cols as $c) $colMap[$c['COLUMN_NAME']] = $c;

  if ($postAction === 'create') {
    $fields = [];
    $params = [];

    foreach ($colMap as $name => $c) {
      if (is_auto_increment($c)) continue;
      if ($name === $pk) continue; // if PK auto or manually set, skip by default
      // allow explicitly set non-auto PK if you want: comment out above line.

      if (!array_key_exists($name, $_POST)) continue;
      $raw = $_POST[$name];

      if (is_boolish($c)) {
        $val = ($raw === '1' || $raw === 'on' || $raw === 'true') ? 1 : 0;
      } else {
        $val = ($raw === '') ? null : $raw;
      }

      // enforce NOT NULL columns (basic)
      if (($c['IS_NULLABLE'] ?? '') === 'NO' && $val === null && $c['COLUMN_DEFAULT'] === null) {
        $flash = "Missing required field: {$name}";
        break;
      }

      $fields[] = "`{$name}`";
      $params[":{$name}"] = $val;
    }

    if ($flash === null) {
      if (count($fields) === 0) {
        $flash = "No fields provided.";
      } else {
        $sql = "INSERT INTO `{$table}` (" . implode(',', $fields) . ")
                VALUES (" . implode(',', array_keys($params)) . ")";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $flash = "Created row (new id: " . (string)$pdo->lastInsertId() . ")";
        $action = 'list';
      }
    }
  }

  if ($postAction === 'edit') {
    if (!$pk) {
      $flash = "No primary key detected; edit disabled for this table.";
    } else {
      $id = $_POST['id'] ?? '';
      if (!preg_match('/^[0-9]+$/', (string)$id)) {
        $flash = "Invalid id.";
      } else {
        $sets = [];
        $params = [':id' => (int)$id];

        foreach ($colMap as $name => $c) {
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

          $sets[] = "`{$name}` = :{$name}";
          $params[":{$name}"] = $val;
        }

        if ($flash === null) {
          if (count($sets) === 0) {
            $flash = "No changes provided.";
          } else {
            $sql = "UPDATE `{$table}` SET " . implode(',', $sets) . " WHERE `{$pk}` = :id LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $flash = "Updated row id {$id}.";
            $action = 'list';
          }
        }
      }
    }
  }

  if ($postAction === 'delete') {
    if (!$pk) {
      $flash = "No primary key detected; delete disabled for this table.";
    } else {
      $id = $_POST['id'] ?? '';
      if (!preg_match('/^[0-9]+$/', (string)$id)) {
        $flash = "Invalid id.";
      } else {
        $sql = "DELETE FROM `{$table}` WHERE `{$pk}` = :id LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':id' => (int)$id]);
        $flash = "Deleted row id {$id}.";
        $action = 'list';
      }
    }
  }
}

/* =========================
   Data fetch for views
   ========================= */
$rows = [];
$totalRows = null;

if ($table && $action === 'list') {
  $offset = ($page - 1) * $pageSize;

  // total count
  $st = $pdo->prepare("SELECT COUNT(*) AS c FROM `{$table}`");
  $st->execute();
  $totalRows = (int)($st->fetch()['c'] ?? 0);

  // data
  $st = $pdo->prepare("SELECT * FROM `{$table}` ORDER BY 1 DESC LIMIT :lim OFFSET :off");
  $st->bindValue(':lim', $pageSize, PDO::PARAM_INT);
  $st->bindValue(':off', $offset, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
}

$editRow = null;
if ($table && $action === 'edit' && $pk && $rowId !== null) {
  $st = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$pk}` = :id LIMIT 1");
  $st->execute([':id' => (int)$rowId]);
  $editRow = $st->fetch() ?: null;
}

/* =========================
   Render helpers (inputs)
   ========================= */
function render_input(array $col, $value, string $name): string {
  $dataType = strtolower($col['DATA_TYPE'] ?? '');
  $colType  = $col['COLUMN_TYPE'] ?? '';
  $nullable = (($col['IS_NULLABLE'] ?? '') === 'YES');
  $req = col_required($col);

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

  if (is_boolish($col)) {
    $checked = ((string)$value === '1') ? ' checked' : '';
    return '<div class="form-check">
              <input class="form-check-input" type="checkbox" value="1" name="'.h($name).'"'.$checked.'>
            </div>';
  }

  $type = 'text';
  if ($dataType === 'int' || $dataType === 'bigint' || $dataType === 'smallint' || $dataType === 'mediumint' || $dataType === 'tinyint') $type = 'number';
  if ($dataType === 'date') $type = 'date';
  if ($dataType === 'datetime' || $dataType === 'timestamp') $type = 'datetime-local';
  if ($dataType === 'json') $type = 'text';

  $val = ($value === null) ? '' : (string)$value;

  // datetime-local expects "YYYY-MM-DDTHH:MM"
  if (($type === 'datetime-local') && $val !== '') {
    // Basic conversion from "YYYY-MM-DD HH:MM:SS" to "YYYY-MM-DDTHH:MM"
    $val = str_replace(' ', 'T', substr($val, 0, 16));
  }

  $reqAttr = $req ? ' required' : '';
  return '<input class="form-control" type="'.$type.'" name="'.h($name).'" value="'.h($val).'"'.$reqAttr.'>';
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BloodLink Schema-Driven Docs</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { padding: 24px; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .muted { color: #6c757d; }
    .table-list { max-height: 72vh; overflow: auto; }
    .comment { font-size: 12px; color: #6c757d; }
  </style>
</head>
<body>
<div class="container-fluid" style="max-width: 1400px;">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h3 mb-1">BloodLink Docs + CRUD</h1>
      <div class="muted">Scribed from init via <span class="mono">information_schema</span>. No duplicated field mapping.</div>
    </div>
    <div class="text-end muted">
      DB: <strong><?= h($DBS[$dbKey]['label']) ?></strong>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-info"><?= h($flash) ?></div>
  <?php endif; ?>

  <!-- DB Tabs -->
  <ul class="nav nav-tabs mb-3">
    <?php foreach ($DBS as $k => $v): ?>
      <li class="nav-item">
        <a class="nav-link <?= $k === $dbKey ? 'active' : '' ?>"
           href="?db=<?= h($k) ?>">
          <?= h($v['label']) ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="row g-3">
    <!-- Table list -->
    <div class="col-12 col-lg-3">
      <div class="card">
        <div class="card-header">Tables</div>
        <div class="list-group list-group-flush table-list">
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
      </div>
    </div>

    <!-- Main pane -->
    <div class="col-12 col-lg-9">
      <?php if (!$table): ?>
        <div class="alert alert-warning">No tables found in this database.</div>
      <?php else: ?>
        <div class="card mb-3">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="mono fs-5"><?= h($dbName . '.' . $table) ?></div>
                <div class="muted">
                  PK: <span class="mono"><?= h($pk ?? '(none detected)') ?></span>
                </div>
              </div>

              <!-- Action tabs -->
              <ul class="nav nav-pills">
                <li class="nav-item">
                  <a class="nav-link <?= $action === 'list' ? 'active' : '' ?>"
                     href="?db=<?= h($dbKey) ?>&table=<?= h($table) ?>&action=list">List</a>
                </li>
                <li class="nav-item">
                  <a class="nav-link <?= $action === 'create' ? 'active' : '' ?>"
                     href="?db=<?= h($dbKey) ?>&table=<?= h($table) ?>&action=create">Create</a>
                </li>
                <li class="nav-item">
                  <a class="nav-link <?= $action === 'edit' ? 'active' : '' ?> <?= !$pk ? 'disabled' : '' ?>"
                     href="?db=<?= h($dbKey) ?>&table=<?= h($table) ?>&action=edit<?= $pk && $rowId ? '&id='.h((string)$rowId) : '' ?>">Edit</a>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Columns quick reference -->
        <div class="card mb-3">
          <div class="card-header">Fields (from init/schema)</div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Field</th><th>Type</th><th>Null</th><th>Default</th><th>Key</th><th>Extra</th><th>Comment</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($cols as $c): ?>
                    <tr>
                      <td class="mono"><?= h($c['COLUMN_NAME']) ?></td>
                      <td class="mono"><?= h($c['COLUMN_TYPE']) ?></td>
                      <td><?= h($c['IS_NULLABLE']) ?></td>
                      <td class="mono"><?= h($c['COLUMN_DEFAULT'] === null ? 'NULL' : (string)$c['COLUMN_DEFAULT']) ?></td>
                      <td class="mono"><?= h($c['COLUMN_KEY'] ?: '') ?></td>
                      <td class="mono"><?= h($c['EXTRA'] ?: '') ?></td>
                      <td class="comment"><?= h($c['COLUMN_COMMENT'] ?: '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <?php if ($action === 'list'): ?>
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span>Rows</span>
              <form class="d-flex gap-2" method="get">
                <input type="hidden" name="db" value="<?= h($dbKey) ?>">
                <input type="hidden" name="table" value="<?= h($table) ?>">
                <input type="hidden" name="action" value="list">
                <input class="form-control form-control-sm" style="width:120px" name="page" value="<?= h((string)$page) ?>" placeholder="page">
                <input class="form-control form-control-sm" style="width:140px" name="page_size" value="<?= h((string)$pageSize) ?>" placeholder="page_size">
                <button class="btn btn-sm btn-outline-primary">Go</button>
              </form>
            </div>

            <div class="card-body p-0">
              <div class="p-3 muted">
                Total: <strong><?= h((string)($totalRows ?? 0)) ?></strong>
              </div>

              <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                  <thead>
                    <tr>
                      <?php foreach ($cols as $c): ?>
                        <th class="mono"><?= h($c['COLUMN_NAME']) ?></th>
                      <?php endforeach; ?>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r): ?>
                      <tr>
                        <?php foreach ($cols as $c): ?>
                          <?php $cn = $c['COLUMN_NAME']; ?>
                          <td class="mono"><?= h($r[$cn] === null ? 'NULL' : (string)$r[$cn]) ?></td>
                        <?php endforeach; ?>
                        <td class="text-nowrap">
                          <?php if ($pk && isset($r[$pk])): ?>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="?db=<?= h($dbKey) ?>&table=<?= h($table) ?>&action=edit&id=<?= h((string)$r[$pk]) ?>">Edit</a>

                            <form method="post" class="d-inline">
                              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                              <input type="hidden" name="do" value="delete">
                              <input type="hidden" name="id" value="<?= h((string)$r[$pk]) ?>">
                              <button class="btn btn-sm btn-outline-danger"
                                      onclick="return confirm('Delete row <?= h((string)$r[$pk]) ?>?')">Delete</button>
                            </form>
                          <?php else: ?>
                            <span class="muted">No PK</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                    <?php if (count($rows) === 0): ?>
                      <tr><td colspan="<?= count($cols)+1 ?>" class="p-3 muted">No rows.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

            </div>
          </div>
        <?php endif; ?>

        <?php if ($action === 'create'): ?>
          <div class="card">
            <div class="card-header">Create row</div>
            <div class="card-body">
              <form method="post" class="row g-3">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="do" value="create">

                <?php foreach ($cols as $c): ?>
                  <?php
                    $name = $c['COLUMN_NAME'];
                    if (is_auto_increment($c)) continue;
                    if ($name === $pk) continue; // skip PK by default
                    $req = col_required($c);
                    $desc = $c['COLUMN_COMMENT'] ?? '';
                  ?>
                  <div class="col-12 col-md-6">
                    <label class="form-label">
                      <span class="mono"><?= h($name) ?></span>
                      <?php if ($req): ?><span class="text-danger">*</span><?php endif; ?>
                      <span class="muted"> (<?= h($c['COLUMN_TYPE']) ?>)</span>
                    </label>
                    <?= render_input($c, $c['COLUMN_DEFAULT'], $name) ?>
                    <?php if ($desc): ?><div class="comment mt-1"><?= h($desc) ?></div><?php endif; ?>
                  </div>
                <?php endforeach; ?>

                <div class="col-12">
                  <button class="btn btn-primary">Create</button>
                </div>
              </form>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($action === 'edit'): ?>
          <div class="card">
            <div class="card-header">Edit row</div>
            <div class="card-body">
              <?php if (!$pk): ?>
                <div class="alert alert-warning">No primary key detected; edit is disabled.</div>
              <?php elseif ($rowId === null): ?>
                <div class="alert alert-warning">Pick a row from the list to edit.</div>
              <?php elseif (!$editRow): ?>
                <div class="alert alert-warning">Row not found.</div>
              <?php else: ?>
                <form method="post" class="row g-3">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="do" value="edit">
                  <input type="hidden" name="id" value="<?= h((string)$editRow[$pk]) ?>">

                  <?php foreach ($cols as $c): ?>
                    <?php
                      $name = $c['COLUMN_NAME'];
                      $req = col_required($c);
                      $desc = $c['COLUMN_COMMENT'] ?? '';
                      $val = $editRow[$name] ?? null;
                      $readonly = ($name === $pk) || is_auto_increment($c);
                    ?>
                    <div class="col-12 col-md-6">
                      <label class="form-label">
                        <span class="mono"><?= h($name) ?></span>
                        <?php if ($req): ?><span class="text-danger">*</span><?php endif; ?>
                        <span class="muted"> (<?= h($c['COLUMN_TYPE']) ?>)</span>
                      </label>

                      <?php if ($readonly): ?>
                        <input class="form-control" value="<?= h($val === null ? 'NULL' : (string)$val) ?>" disabled>
                      <?php else: ?>
                        <?= render_input($c, $val, $name) ?>
                      <?php endif; ?>

                      <?php if ($desc): ?><div class="comment mt-1"><?= h($desc) ?></div><?php endif; ?>
                    </div>
                  <?php endforeach; ?>

                  <div class="col-12">
                    <button class="btn btn-primary">Save changes</button>
                    <a class="btn btn-outline-secondary"
                       href="?db=<?= h($dbKey) ?>&table=<?= h($table) ?>&action=list">Back to list</a>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>