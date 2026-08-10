(function () {
    function confirmAction(options) {
        if (!window.bootstrap || !window.bootstrap.Modal) {
            return false;
        }

        var config = options || {};
        var modalEl = document.getElementById('sharedConfirmModal');
        if (!modalEl) {
            return false;
        }

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        var titleEl = modalEl.querySelector('[data-role="title"]');
        var messageEl = modalEl.querySelector('[data-role="message"]');
        var confirmBtn = modalEl.querySelector('[data-role="confirm"]');
        var cancelBtn = modalEl.querySelector('[data-role="cancel"]');

        if (titleEl) {
            titleEl.textContent = config.title || 'Confirm action';
        }
        if (messageEl) {
            messageEl.textContent = config.message || '';
        }
        if (confirmBtn) {
            confirmBtn.textContent = config.confirmText || 'Confirm';
        }

        var handled = false;
        var cleanup = function () {
            if (confirmBtn) {
                confirmBtn.removeEventListener('click', onConfirmClick);
            }
            if (cancelBtn) {
                cancelBtn.removeEventListener('click', onCancelClick);
            }
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
        };

        function onConfirmClick() {
            handled = true;
            cleanup();
            modal.hide();
            if (typeof config.onConfirm === 'function') {
                config.onConfirm();
            }
        }

        function onCancelClick() {
            handled = true;
            cleanup();
            modal.hide();
        }

        function onHidden() {
            cleanup();
            if (!handled) {
                if (typeof config.onCancel === 'function') {
                    config.onCancel();
                }
            }
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', onConfirmClick);
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', onCancelClick);
        }
        modalEl.addEventListener('hidden.bs.modal', onHidden);

        modal.show();
        return true;
    }

    function getConfirmMessageFromExpression(expression) {
        if (!expression) {
            return '';
        }

        var match = expression.match(/confirm\(\s*([\s\S]*?)\s*\)\s*;?$/);
        if (!match) {
            return '';
        }

        var text = (match[1] || '').trim();
        if ((text.charAt(0) === '\'' && text.charAt(text.length - 1) === '\'') || (text.charAt(0) === '"' && text.charAt(text.length - 1) === '"')) {
            return text.slice(1, -1);
        }

        return text;
    }

    function patchInlineConfirmHandlers(root) {
        if (!root) {
            return;
        }

        Array.prototype.slice.call(root.querySelectorAll('form')).forEach(function (form) {
            var attrValue = form.getAttribute('onsubmit');
            if (!attrValue || attrValue.indexOf('confirm(') === -1 || attrValue.indexOf('confirmAction') !== -1) {
                return;
            }

            var message = getConfirmMessageFromExpression(attrValue) || 'Confirm this action?';
            form.setAttribute('data-confirm-message', message);
            form.onsubmit = null;

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!window.confirmAction) {
                    return;
                }

                var target = event.currentTarget;
                window.confirmAction({
                    title: 'Confirm action',
                    message: target.getAttribute('data-confirm-message') || 'Confirm this action?',
                    confirmText: 'Confirm',
                    onConfirm: function () {
                        target.submit();
                    }
                });
            }, true);
        });

        Array.prototype.slice.call(root.querySelectorAll('button, a, input')).forEach(function (element) {
            var attrValue = element.getAttribute('onclick');
            if (!attrValue || attrValue.indexOf('confirm(') === -1 || attrValue.indexOf('confirmAction') !== -1) {
                return;
            }

            var message = getConfirmMessageFromExpression(attrValue) || 'Confirm this action?';
            element.setAttribute('data-confirm-message', message);
            element.onclick = null;

            element.addEventListener('click', function (event) {
                event.preventDefault();
                if (!window.confirmAction) {
                    return;
                }

                var target = event.currentTarget;
                window.confirmAction({
                    title: 'Confirm action',
                    message: target.getAttribute('data-confirm-message') || 'Confirm this action?',
                    confirmText: 'Confirm',
                    onConfirm: function () {
                        var form = target.closest('form');
                        if (form) {
                            form.submit();
                            return;
                        }

                        if (target.tagName === 'A' && target.getAttribute('href')) {
                            window.location.href = target.getAttribute('href');
                            return;
                        }

                        if (target.type === 'submit' && target.form) {
                            target.form.submit();
                        }
                    }
                });
            }, true);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            patchInlineConfirmHandlers(document);
        });
    } else {
        patchInlineConfirmHandlers(document);
    }

    window.confirmAction = confirmAction;
})();
