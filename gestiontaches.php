<?php
declare(strict_types=1);

/* =========================
   CONFIG + FONCTIONS
========================= */
const DATA_FILE = __DIR__ . '/data.json';

function h(string $v): string {
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function read_tasks(): array {
  if (!file_exists(DATA_FILE)) {
    @mkdir(dirname(DATA_FILE), 0777, true);
    file_put_contents(DATA_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  }
  $raw = file_get_contents(DATA_FILE);
  $data = json_decode($raw ?: '[]', true);
  return is_array($data) ? $data : [];
}

function write_tasks(array $tasks): void {
  file_put_contents(DATA_FILE, json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function next_id(array $tasks): int {
  $max = 0;
  foreach ($tasks as $t) {
    $id = (int)($t['id'] ?? 0);
    if ($id > $max) $max = $id;
  }
  return $max + 1;
}

function normalize_priority(string $p): string {
  $p = strtolower(trim($p));
  return in_array($p, ['basse','moyenne','haute'], true) ? $p : 'moyenne';
}

function normalize_status(string $s): string {
  $s = strtolower(trim($s));
  $allowed = ['à faire','en cours','terminée'];
  return in_array($s, $allowed, true) ? $s : 'à faire';
}

function status_next(string $current): string {
  $current = normalize_status($current);
  if ($current === 'à faire') return 'en cours';
  if ($current === 'en cours') return 'terminée';
  return 'à faire';
}

function is_overdue(array $task): bool {
  $status = normalize_status((string)($task['statut'] ?? 'à faire'));
  $deadline = trim((string)($task['date_limite'] ?? ''));

  if ($status === 'terminée') return false;
  if ($deadline === '') return false;

  $dl = strtotime($deadline . ' 23:59:59');
  if ($dl === false) return false;

  return time() > $dl;
}

function matches_keyword(array $task, string $q): bool {
  $q = trim(mb_strtolower($q));
  if ($q === '') return true;

  $titre = mb_strtolower((string)($task['titre'] ?? ''));
  $desc  = mb_strtolower((string)($task['description'] ?? ''));

  return (str_contains($titre, $q) || str_contains($desc, $q));
}

function badge_priority(string $p): string {
  $p = normalize_priority($p);
  return match($p) {
    'haute' => 'bg-danger',
    'moyenne' => 'bg-warning text-dark',
    'basse' => 'bg-secondary',
    default => 'bg-secondary'
  };
}

function badge_status(string $s): string {
  $s = normalize_status($s);
  return match($s) {
    'à faire' => 'bg-secondary',
    'en cours' => 'bg-primary',
    'terminée' => 'bg-success',
    default => 'bg-secondary'
  };
}

/* =========================
   LOGIQUE (CRUD)
========================= */
$tasks = read_tasks();
$error = '';
$success = '';

// ✅ Nom du fichier courant (peu importe comment tu l'appelles)
$SELF = basename($_SERVER['PHP_SELF']);

// Actions GET : toggle / delete
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($action === 'toggle' && $id > 0) {
  foreach ($tasks as &$t) {
    if ((int)$t['id'] === $id) {
      $t['statut'] = status_next((string)$t['statut']);
      break;
    }
  }
  unset($t);
  write_tasks($tasks);
  header("Location: $SELF");
  exit;
}

if ($action === 'delete' && $id > 0) {
  $tasks = array_values(array_filter($tasks, fn($t) => (int)($t['id'] ?? 0) !== $id));
  write_tasks($tasks);
  header("Location: $SELF");
  exit;
}

// Ajout POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $titre = trim($_POST['titre'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $priorite = normalize_priority($_POST['priorite'] ?? 'moyenne');
  $date_limite = trim($_POST['date_limite'] ?? '');

  if ($titre === '') {
    $error = "Le titre est obligatoire.";
  } elseif ($date_limite !== '' && strtotime($date_limite) === false) {
    $error = "Date limite invalide.";
  } else {
    $tasks[] = [
      'id' => next_id($tasks),
      'titre' => $titre,
      'description' => $description,
      'priorité' => $priorite,
      'statut' => 'à faire',
      'date_creation' => date('Y-m-d'),
      'date_limite' => $date_limite
    ];
    write_tasks($tasks);
    $success = "Tâche ajoutée !";
    $tasks = read_tasks();
  }
}

// Filtres GET
$q = trim($_GET['q'] ?? '');
$f_statut = trim($_GET['statut'] ?? '');
$f_priorite = trim($_GET['priorite'] ?? '');

$filtered = array_values(array_filter($tasks, function($t) use ($q, $f_statut, $f_priorite) {
  if (!matches_keyword($t, $q)) return false;

  if ($f_statut !== '' && normalize_status((string)($t['statut'] ?? '')) !== normalize_status($f_statut)) {
    return false;
  }

  if ($f_priorite !== '' && normalize_priority((string)($t['priorité'] ?? '')) !== normalize_priority($f_priorite)) {
    return false;
  }
  return true;
}));

// Stats
$total = count($tasks);
$done = 0;
$overdue = 0;

foreach ($tasks as $t) {
  if (normalize_status((string)($t['statut'] ?? '')) === 'terminée') $done++;
  if (is_overdue($t)) $overdue++;
}
$percent = ($total > 0) ? round(($done / $total) * 100, 1) : 0.0;

?>
<!DOCTYPE html>

<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Gestion de tâches (JSON)</title>
  <link rel="stylesheet" href="css/bootstrap.css">
</head>

<body class="bg-light">

<div class="container py-4">

  <div class="mb-4">
    <h1 class="fw-bold mb-1">Gestion de tâches</h1>
    
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <!-- AJOUT -->
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-header bg-primary text-white fw-bold">
          Ajouter une tâche
        </div>
        <div class="card-body">
          <form method="post">
            <div class="mb-3">
              <label class="form-label fw-semibold">Titre *</label>
              <input type="text" name="titre" class="form-control" required value="<?= h($_POST['titre'] ?? '') ?>">
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" class="form-control" rows="3"><?= h($_POST['description'] ?? '') ?></textarea>
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Priorité</label>
                <?php $selP = $_POST['priorite'] ?? 'moyenne'; ?>
                <select name="priorite" class="form-select">
                  <option value="basse" <?= $selP==='basse'?'selected':'' ?>>Basse</option>
                  <option value="moyenne" <?= $selP==='moyenne'?'selected':'' ?>>Moyenne</option>
                  <option value="haute" <?= $selP==='haute'?'selected':'' ?>>Haute</option>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label fw-semibold">Date limite</label>
                <input type="date" name="date_limite" class="form-control" value="<?= h($_POST['date_limite'] ?? '') ?>">
              </div>
            </div>

            <button class="btn btn-success mt-3 fw-bold">Ajouter</button>
          </form>
        </div>
      </div>
    </div>

    <!-- STATS -->
    <div class="col-lg-5">
      <div class="card shadow-sm h-100">
        <div class="card-header bg-dark text-white fw-bold">
          Statistiques
        </div>
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge bg-secondary p-2">Total : <?= (int)$total ?></span>
            <span class="badge bg-success p-2">Terminées : <?= (int)$done ?></span>
            <span class="badge bg-primary p-2">% terminées : <?= h((string)$percent) ?>%</span>
            <span class="badge bg-danger p-2">En retard : <?= (int)$overdue ?></span>
          </div>
          <div class="text-muted small">
            Une tâche est “en retard” si elle n’est pas terminée et sa date limite est dépassée.
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- FILTRES -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-bold">
      Recherche & filtres
    </div>
    <div class="card-body">
      <form method="get" class="row g-2">
        <div class="col-lg-6">
          <input type="text" class="form-control" name="q" placeholder="Mot-clé (titre ou description)" value="<?= h($q) ?>">
        </div>

        <div class="col-lg-2">
          <select class="form-select" name="statut">
            <option value="">-- Statut --</option>
            <?php foreach (['à faire','en cours','terminée'] as $s): ?>
              <option value="<?= h($s) ?>" <?= ($f_statut === $s ? 'selected' : '') ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-lg-2">
          <select class="form-select" name="priorite">
            <option value="">-- Priorité --</option>
            <?php foreach (['basse','moyenne','haute'] as $p): ?>
              <option value="<?= h($p) ?>" <?= ($f_priorite === $p ? 'selected' : '') ?>><?= h($p) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-lg-2 d-grid d-lg-flex gap-2">
          <button class="btn btn-primary fw-bold" type="submit">Appliquer</button>
          <a class="btn btn-outline-secondary fw-bold" href="<?= h($SELF) ?>">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- TABLE -->
  <div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="fw-bold">Liste des tâches</div>
      <div class="text-muted small">Affichées : <?= count($filtered) ?></div>
    </div>

    <div class="card-body table-responsive">
      <?php if (count($filtered) === 0): ?>
        <div class="alert alert-info mb-0">Aucune tâche à afficher.</div>
      <?php else: ?>
        <table class="table table-bordered table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:70px;">ID</th>
              <th>Titre / Description</th>
              <th style="width:120px;">Priorité</th>
              <th style="width:120px;">Statut</th>
              <th style="width:140px;">Date limite</th>
              <th style="width:210px;">Actions</th>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($filtered as $t): ?>
              <?php
                $over = is_overdue($t);
                $prio = (string)($t['priorité'] ?? 'moyenne');
                $stat = (string)($t['statut'] ?? 'à faire');
                $dl   = trim((string)($t['date_limite'] ?? ''));
              ?>
              <tr class="<?= $over ? 'table-danger' : '' ?>">
                <td class="fw-bold"><?= (int)($t['id'] ?? 0) ?></td>

                <td>
                  <div class="fw-bold"><?= h((string)($t['titre'] ?? '')) ?></div>
                  <?php if (!empty($t['description'])): ?>
                    <div class="text-muted small"><?= h((string)$t['description']) ?></div>
                  <?php endif; ?>
                  <?php if ($over): ?>
                    <div class="small fw-bold mt-1">⚠ En retard</div>
                  <?php endif; ?>
                </td>

                <td>
                  <span class="badge <?= badge_priority($prio) ?>"><?= h($prio) ?></span>
                </td>

                <td>
                  <span class="badge <?= badge_status($stat) ?>"><?= h($stat) ?></span>
                </td>

                <td><?= $dl !== '' ? h($dl) : '<span class="text-muted">—</span>' ?></td>

                <td class="d-flex gap-2 flex-wrap">
                  <a class="btn btn-sm btn-primary fw-bold"
                     href="<?= h($SELF) ?>?action=toggle&id=<?= (int)$t['id'] ?>">
                    Changer statut
                  </a>

                  <a class="btn btn-sm btn-danger fw-bold"
                     href="<?= h($SELF) ?>?action=delete&id=<?= (int)$t['id'] ?>"
                     onclick="return confirm('Supprimer cette tâche ?');">
                    Supprimer
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  

</div>
</body>
</html>
