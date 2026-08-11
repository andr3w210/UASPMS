    </div>
    <footer id="footer" class="footer">
        <div class="footer-shell">
            <span class="footer-badge"><i class="bi bi-shield-check"></i> SPAMS</span>
            <div class="copyright">
                SPAMS &copy; <?php echo date('Y'); ?>. Supply and Property Asset Management System. &mdash; <span style="font-style:italic;opacity:0.7;">aDDicTus</span> &middot; <span style="opacity:0.6;"><?php echo APP_VERSION; ?></span>
            </div>
        </div>
    </footer>
</main>

<?php require_once __DIR__ . '/chat_widget.php'; ?>
<?php require_once __DIR__ . '/confirm_modal.php'; ?>

<div id="globalToastContainer" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1080;"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="<?php echo base_url('assets/js/app.js?v=' . rawurlencode((string) filemtime(__DIR__ . '/../assets/js/app.js'))); ?>"></script>
<script src="<?php echo base_url('assets/js/confirm.js'); ?>"></script>
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
