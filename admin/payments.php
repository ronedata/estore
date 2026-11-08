<?php
// admin/payments.php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__ . '/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

gate('payments');
$pdo = get_pdo();

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok' => false, 'message' => 'Invalid CSRF token. Please try again.'];
    header('Location: payments.php');
    exit;
  }

  $methods = $_POST['methods'] ?? [];
  $enabledCount = 0;
  foreach ($methods as $m) {
    if (!empty($m['is_enabled'])) {
      $enabledCount++;
    }
  }

  if ($enabledCount < 1) {
    $_SESSION['flash'] = ['ok' => false, 'message' => 'Please keep at least one payment method enabled.'];
    header('Location: payments.php');
    exit;
  }

  try {
    $pdo->beginTransaction();
    $stm = $pdo->prepare("UPDATE payment_methods SET label=?, description=?, is_enabled=?, sort_order=? WHERE id=?");
    foreach ($methods as $id => $m) {
      $id = (int)$id;
      $label = trim($m['label'] ?? '');
      $desc = trim($m['description'] ?? '');
      $enabled = !empty($m['is_enabled']) ? 1 : 0;
      $sort = (int)($m['sort_order'] ?? 0);
      if ($id > 0 && $label !== '') {
        $stm->execute([$label, $desc, $enabled, $sort, $id]);
      }
    }
    $pdo->commit();
    $_SESSION['flash'] = ['ok' => true, 'message' => 'Payment methods updated successfully.'];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['ok' => false, 'message' => 'Update failed: ' . $e->getMessage()];
  }

  header('Location: payments.php?saved=1');
  exit;
}

$all_methods = $pdo->query("SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC")->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0">Payment Methods</h5>
  <button class="btn btn-primary d-none d-lg-inline-flex align-items-center gap-2" form="paymentForm">
    <i class="fa-solid fa-floppy-disk"></i>
    <span>Save</span>
  </button>
</div>

<?php if (!empty($_SESSION['flash'])): $flash = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert alert-<?php echo $flash['ok'] ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
    <?php echo h($flash['message']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post" id="paymentForm">
      <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
      <p class="text-muted small mb-2">
        Enable or disable payment methods for checkout. Drag <i class="fa-solid fa-grip-vertical text-muted"></i> to reorder.
      </p>
      <div id="paymentList" class="vstack gap-3">
        <?php foreach ($all_methods as $m): ?>
          <?php $id = (int)$m['id']; ?>
          <div class="border rounded-3 p-3 d-flex gap-3" data-id="<?php echo $id; ?>">
            <div class="d-none d-md-flex align-items-center" style="cursor: grab;">
              <i class="fa-solid fa-grip-vertical text-muted"></i>
            </div>
            <div class="flex-grow-1">
              <div class="row g-2">
                <div class="col-md-4">
                  <label class="form-label small">Label</label>
                  <input class="form-control" name="methods[<?php echo $id; ?>][label]" value="<?php echo h($m['label']); ?>">
                </div>
                <div class="col-md-8">
                  <label class="form-label small">Description</label>
                  <input class="form-control" name="methods[<?php echo $id; ?>][description]" value="<?php echo h($m['description']); ?>">
                </div>
              </div>
            </div>
            <div class="d-flex flex-column align-items-center justify-content-center">
              <div class="form-check form-switch">
                <input type="hidden" name="methods[<?php echo $id; ?>][is_enabled]" value="0">
                <input class="form-check-input" type="checkbox" role="switch" name="methods[<?php echo $id; ?>][is_enabled]" value="1" <?php echo !empty($m['is_enabled']) ? 'checked' : ''; ?>>
                <label class="form-check-label small">Enable</label>
              </div>
            </div>
            <input type="hidden" class="sort-order-input" name="methods[<?php echo $id; ?>][sort_order]" value="<?php echo (int)$m['sort_order']; ?>">
          </div>
        <?php endforeach; ?>
      </div>
      <div class="text-end">
        <button class="btn btn-primary">
          <i class="fa-solid fa-floppy-disk me-1"></i>
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- SortableJS for drag-and-drop -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const el = document.getElementById('paymentList');
  if (el) {
    new Sortable(el, {
      animation: 150,
      handle: '.fa-grip-vertical',
      onUpdate: function () {
        const items = el.querySelectorAll('.border[data-id]');
        items.forEach((item, index) => {
          const input = item.querySelector('.sort-order-input');
          if (input) input.value = index;
        });
      }
    });
  }
});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
