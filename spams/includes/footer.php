<?php if (!empty($GLOBALS['spams_shell_open'])): ?>
    </div>
</main>
<footer id="footer" class="footer footer-transparent d-print-none">
    <div class="container-fluid">
        <div class="row text-center align-items-center flex-row-reverse">
            <div class="col-lg-auto ms-lg-auto">
                <span class="badge bg-primary-lt"><i class="bi bi-shield-check me-1"></i>SPAMS <?php echo APP_VERSION; ?></span>
            </div>
            <div class="col-12 col-lg-auto mt-3 mt-lg-0">
                SPAMS &copy; <?php echo date('Y'); ?> University of Antique. Supply and Property Asset Management System.
            </div>
        </div>
    </div>
</footer>
</div>
</div>
<?php else: ?>
    <div class="text-center text-secondary small py-3 d-print-none">
        SPAMS &copy; <?php echo date('Y'); ?> University of Antique
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/chat_widget.php'; ?>
<?php require_once __DIR__ . '/confirm_modal.php'; ?>

<div id="globalToastContainer" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1080;"></div>

<script src="<?php echo base_url('assets/vendor/tabler/js/tabler.min.js'); ?>"></script>
<script>window.bootstrap = window.bootstrap || (window.tabler && window.tabler.bootstrap) || window.tabler;</script>
<script src="<?php echo base_url('assets/vendor/tabler/jquery/jquery.min.js'); ?>"></script>
<script src="<?php echo base_url('assets/vendor/tabler/select2/select2.min.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/app.js?v=' . rawurlencode((string) filemtime(__DIR__ . '/../assets/js/app.js'))); ?>"></script>
<script src="<?php echo base_url('assets/js/confirm.js?v=' . rawurlencode((string) filemtime(__DIR__ . '/../assets/js/confirm.js'))); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.bootstrap || !bootstrap.Toast) {
        return;
    }

    <?php
    $flashToast = null;
    if (!empty($_SESSION['flash_message']) || !empty($_SESSION['flash_type'])) {
        $flashToast = [
            'message' => trim((string) ($_SESSION['flash_message'] ?? '')),
            'type' => trim((string) ($_SESSION['flash_type'] ?? '')),
        ];
        if ($flashToast['message'] === '') {
            $flashToast = null;
        } else {
            if ($flashToast['type'] === 'error') {
                $flashToast['type'] = 'danger';
            }
            if (!in_array($flashToast['type'], ['success', 'danger', 'warning', 'info'], true)) {
                $flashToast['type'] = 'info';
            }
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        }
    }
    ?>
    <?php if ($flashToast): ?>
    if (window.showToast) {
        window.showToast(<?php echo json_encode($flashToast['message'] ?? ''); ?>, <?php echo json_encode($flashToast['type'] ?? 'info'); ?>);
    }
    <?php endif; ?>

    var dangerAlert = Array.from(document.querySelectorAll('.alert.alert-danger')).find(function (el) {
        var isHidden = el.classList.contains('d-none') || el.hasAttribute('hidden') || el.getAttribute('aria-hidden') === 'true';
        if (isHidden) {
            return false;
        }

        if (el.offsetParent === null && el.getClientRects().length === 0) {
            return false;
        }

        var text = (el.textContent || '').trim();
        return text !== '';
    });

    if (!dangerAlert) {
        return;
    }

    var messageLines = Array.from(dangerAlert.querySelectorAll('div')).map(function (el) {
        return (el.textContent || '').trim();
    }).filter(function (line) {
        return line !== '';
    });

    var summary = messageLines[0] || (dangerAlert.textContent || '').trim();
    if (!summary) {
        return;
    }

    if (window.showToast) {
        window.showToast(summary, 'danger');
    }
});
</script>
</body>
</html>
