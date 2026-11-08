<?php
// admin/home_sections.php — হোমপেজ সেকশন: তালিকা, ক্রম (↑↓), চালু/বন্ধ, মুছুন
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/home_sections.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_home_sections');

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'সিকিউরিটি টোকেন মিলেনি'];
    header('Location: home_sections.php'); exit;
  }
  $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
  $id     = is_scalar($_POST['id'] ?? null) ? (int)$_POST['id'] : 0;

  $res = [false, 'অজানা অ্যাকশন।'];
  if ($action === 'delete')      $res = hs_delete($pdo, $id);
  elseif ($action === 'toggle')  $res = hs_toggle($pdo, $id);
  elseif ($action === 'up')      $res = hs_move($pdo, $id, 'up');
  elseif ($action === 'down')    $res = hs_move($pdo, $id, 'down');

  $_SESSION['flash'] = ['ok'=>(bool)$res[0], 'msg'=>$res[1]];
  header('Location: home_sections.php'); exit;
}

$rows   = hs_all($pdo);
$total  = count($rows);
$active = count(array_filter($rows, fn($r) => (int)$r['active'] === 1));
$types  = hs_types();
$catNames = [];
try { $catNames = $pdo->query("SELECT id, name FROM categories")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) {}

require_once __DIR__.'/header.php';
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h4 class="mb-1">হোম সেকশন</h4>
    <div class="text-muted small">
      মোট: <strong><?php echo $total; ?></strong>/<?php echo HS_MAX; ?> ·
      চালু: <strong><?php echo $active; ?></strong> ·
      হোমপেজে এই ক্রমেই (ওপর থেকে নিচে) দেখাবে
    </div>
  </div>
  <?php if ($total < HS_MAX): ?>
    <a class="btn btn-primary" href="home_section_form.php" id="btnNewSection">
      <i class="fa-solid fa-plus"></i> নতুন সেকশন
    </a>
  <?php else: ?>
    <button class="btn btn-secondary" disabled id="btnNewSection" title="সর্বোচ্চ <?php echo HS_MAX; ?>টি সেকশন">
      <i class="fa-solid fa-plus"></i> নতুন সেকশন (সীমা পূর্ণ)
    </button>
  <?php endif; ?>
</div>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($f['ok'])?'alert-success':'alert-danger'; ?> py-2" id="hsFlash">
    <?php echo h($f['msg'] ?? $f['message'] ?? ''); ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="hsTable">
        <thead>
          <tr>
            <th style="width:110px">ক্রম</th>
            <th>শিরোনাম</th>
            <th style="width:210px">ধরন</th>
            <th style="width:90px">পণ্য</th>
            <th style="width:90px">চালু</th>
            <th style="width:170px" class="text-end">অ্যাকশন</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <?php
            $rid  = (int)$r['id'];
            $type = (string)$r['type'];
            $canDelete = ($total > HS_MIN) && !((int)$r['active'] === 1 && $active <= HS_MIN);
            $canOff    = !((int)$r['active'] === 1 && $active <= HS_MIN);
          ?>
          <tr data-id="<?php echo $rid; ?>">
            <td>
              <div class="d-flex align-items-center gap-1">
                <span class="badge text-bg-light border"><?php echo $i + 1; ?></span>
                <form method="post" class="d-inline">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="up">
                  <input type="hidden" name="id" value="<?php echo $rid; ?>">
                  <button class="btn btn-sm btn-outline-secondary" title="ওপরে নিন" <?php echo $i === 0 ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-arrow-up"></i>
                  </button>
                </form>
                <form method="post" class="d-inline">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="down">
                  <input type="hidden" name="id" value="<?php echo $rid; ?>">
                  <button class="btn btn-sm btn-outline-secondary" title="নিচে নিন" <?php echo $i === $total - 1 ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-arrow-down"></i>
                  </button>
                </form>
              </div>
            </td>
            <td>
              <div class="fw-semibold"><?php echo h($r['title']); ?></div>
              <div class="small text-muted"><?php echo h((string)$r['subtitle']); ?></div>
            </td>
            <td>
              <span class="badge text-bg-primary"><?php echo h($types[$type][0] ?? $type); ?></span>
              <?php if ($type === 'category'): ?>
                <div class="small text-muted"><?php echo h($catNames[(int)$r['category_id']] ?? '(ক্যাটেগরি নেই)'); ?></div>
              <?php endif; ?>
            </td>
            <td>
              <strong><?php echo (int)$r['product_limit']; ?></strong>
              <?php if ($type === 'manual'): ?>
                <div class="small text-muted">বাছাই: <?php echo (int)$r['manual_count']; ?></div>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" class="d-inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?php echo $rid; ?>">
                <button class="btn btn-sm <?php echo $r['active']?'btn-success':'btn-outline-secondary'; ?>"
                        <?php echo $canOff ? '' : 'disabled title="অন্তত '.HS_MIN.'টি সেকশন চালু থাকতেই হবে"'; ?>>
                  <?php echo $r['active'] ? 'On' : 'Off'; ?>
                </button>
              </form>
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="home_section_form.php?id=<?php echo $rid; ?>">Edit</a>
              <form method="post" class="d-inline" onsubmit="return confirm('সেকশনটি মুছে ফেলবেন?')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo $rid; ?>">
                <button class="btn btn-sm btn-outline-danger"
                        <?php echo $canDelete ? '' : 'disabled title="অন্তত '.HS_MIN.'টি সেকশন রাখতেই হবে"'; ?>>Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="alert alert-light border mt-4 small">
  <strong>নিয়ম:</strong> সর্বোচ্চ <?php echo HS_MAX; ?>টি সেকশন, অন্তত <?php echo HS_MIN; ?>টি সবসময় চালু থাকবে।
  “হাতে বাছাই” সেকশনের পণ্য প্রোডাক্ট ফর্ম থেকে টিক দিয়ে ঠিক করা হয়।
  পণ্য না থাকলে সেকশন হোমপেজে নিজে থেকেই লুকিয়ে থাকে।
</div>

<?php require_once __DIR__.'/footer.php'; ?>
