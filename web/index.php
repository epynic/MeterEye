<?php
require_once getenv('EB_CONFIG') ?: __DIR__ . '/../config.php';

$files = glob(IMAGES_DIR . '*.jpg') ?: [];
usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

$total    = count($files);
$perPage  = 48;
$pages    = max(1, (int) ceil($total / $perPage));
$page     = max(1, min($pages, (int) ($_GET['p'] ?? 1)));
$slice    = array_slice($files, ($page - 1) * $perPage, $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'EB Monitor') ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg: #0a0a0a;
      --surface: #111;
      --border: #1e1e1e;
      --text: #e8e8e8;
      --muted: #555;
      --accent: #c8ff40;
      --font: "Inter", ui-sans-serif, system-ui, -apple-system, sans-serif;
    }

    html { background: var(--bg); color: var(--text); font-family: var(--font); }
    body { padding: clamp(1.5rem, 5vw, 3rem); }

    header {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
      margin-bottom: 2.5rem;
      padding-bottom: 1.5rem;
      border-bottom: 1px solid var(--border);
    }

    header h1 { font-size: 1.5rem; font-weight: 700; letter-spacing: -0.02em; }
    header h1 span { color: var(--accent); }
    .meta { font-size: 0.8rem; color: var(--muted); }

    a.back { font-size: 0.8rem; color: var(--muted); text-decoration: none; }
    a.back:hover { color: var(--text); }

    .empty { color: var(--muted); font-size: 1rem; padding: 4rem 0; text-align: center; }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 1rem;
    }

    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
      transition: border-color 0.15s;
    }
    .card:hover { border-color: #333; }
    .card a { display: block; }
    .card img {
      width: 100%; aspect-ratio: 4/3; object-fit: cover;
      display: block; background: #000;
    }
    .card-meta {
      padding: 0.6rem 0.75rem;
      font-size: 0.72rem;
      color: var(--muted);
      font-variant-numeric: tabular-nums;
    }

    .pagination {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      margin-top: 2.5rem;
      flex-wrap: wrap;
    }

    .pagination a, .pagination span {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 2.2rem;
      height: 2.2rem;
      padding: 0 0.5rem;
      border-radius: 6px;
      font-size: 0.8rem;
      text-decoration: none;
      border: 1px solid var(--border);
      color: var(--muted);
      transition: border-color 0.15s, color 0.15s;
    }

    .pagination a:hover { border-color: #444; color: var(--text); }
    .pagination span.current { border-color: var(--accent); color: var(--accent); }
    .pagination span.gap { border-color: transparent; }
  </style>
</head>
<body>
  <header>
    <div>
      <h1>EB <span>Monitor</span></h1>
      <p class="meta"><?= $total ?> image<?= $total !== 1 ? 's' : '' ?>
        &mdash; page <?= $page ?> of <?= $pages ?></p>
    </div>
    <a class="back" href="/">&larr; home</a>
  </header>

  <?php if ($total === 0): ?>
    <p class="empty">No images yet — waiting for the first upload from the camera.</p>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($slice as $path):
        $name   = basename($path);
        $imgUrl = IMAGES_URL . rawurlencode($name);
        $parts  = explode('_', $name);
        $label  = ($parts[0] ?? '') . ' ' . str_replace('-', ':', ($parts[1] ?? ''));
      ?>
      <div class="card">
        <a href="<?= htmlspecialchars($imgUrl) ?>" target="_blank">
          <img src="<?= htmlspecialchars($imgUrl) ?>"
               alt="Meter reading <?= htmlspecialchars($label) ?>"
               loading="lazy" />
        </a>
        <div class="card-meta"><?= htmlspecialchars($label) ?></div>
      </div>
      <?php endforeach ?>
    </div>

    <?php if ($pages > 1):
      // Show at most 7 page links: first, last, current ±2, with gaps
      $show = array_unique(array_filter([
        1, 2,
        $page - 2, $page - 1, $page, $page + 1, $page + 2,
        $pages - 1, $pages,
      ], fn($p) => $p >= 1 && $p <= $pages));
      sort($show);
    ?>
    <nav class="pagination">
      <?php if ($page > 1): ?>
        <a href="?p=<?= $page - 1 ?>">&larr;</a>
      <?php endif ?>

      <?php $prev = null; foreach ($show as $p):
        if ($prev !== null && $p - $prev > 1): ?>
          <span class="gap">&hellip;</span>
        <?php endif ?>
        <?php if ($p === $page): ?>
          <span class="current"><?= $p ?></span>
        <?php else: ?>
          <a href="?p=<?= $p ?>"><?= $p ?></a>
        <?php endif ?>
      <?php $prev = $p; endforeach ?>

      <?php if ($page < $pages): ?>
        <a href="?p=<?= $page + 1 ?>">&rarr;</a>
      <?php endif ?>
    </nav>
    <?php endif ?>
  <?php endif ?>
</body>
</html>
