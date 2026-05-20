    <footer id="footer" class="footer">
        <div class="copyright">
            SPAMS &copy; <?php echo date('Y'); ?>. Supply and Property Asset Management System. &mdash; <span style="font-style:italic;opacity:0.7;">aDDicTus</span> &middot; <span style="opacity:0.6;"><?php echo APP_VERSION; ?></span>
        </div>
    </footer>
</main>

<?php require_once __DIR__ . '/chat_widget.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="<?php echo base_url('assets/js/app.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.bootstrap || !bootstrap.Toast) {
        return;
    }

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

    var container = document.getElementById('globalValidationToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'globalValidationToastContainer';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1080';
        document.body.appendChild(container);
    }

    var toastEl = document.createElement('div');
    toastEl.className = 'toast text-bg-danger border-0';
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');
    toastEl.innerHTML =
        '<div class="d-flex">' +
            '<div class="toast-body">' + summary + '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
        '</div>';

    container.appendChild(toastEl);
    var toast = new bootstrap.Toast(toastEl, { delay: 5000 });
    toast.show();
});
</script>
</body>
</html>
