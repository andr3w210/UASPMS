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

    function mutationMessage(form, submitter) {
        var actionInput = form.querySelector('input[name="action"], select[name="action"]');
        var action = actionInput ? String(actionInput.value || '').toLowerCase() : '';
        var buttonText = submitter ? String(submitter.textContent || '').replace(/\s+/g, ' ').trim() : '';
        var label = buttonText || action.replace(/[_-]+/g, ' ');

        if (action === 'create_session' || /preload assets|annual inventory/i.test(label)) {
            return 'Are you sure you want to create this annual inventory session and preload its assets?';
        }
        if (action === 'delete_session') {
            return 'Are you sure you want to delete this inventory count session and its checklist? This action cannot be undone.';
        }
        if (action.indexOf('hard_delete') !== -1 || /\b(delete|remove|discard)\b/i.test(label)) {
            return 'Are you sure you want to permanently delete this record? This action cannot be undone.';
        }
        if (action === 'delete' || /\bdeactivate\b/i.test(label)) {
            return 'Are you sure you want to deactivate this record?';
        }
        if (action.indexOf('cancel') !== -1 || /\bcancel\b/i.test(label)) {
            return 'Are you sure you want to cancel this transaction?';
        }
        if (action.indexOf('merge') !== -1 || /\bmerge\b/i.test(label)) {
            return 'Are you sure you want to merge these records? The duplicate record will be deactivated.';
        }
        if (action.indexOf('reactivate') !== -1 || /\breactivate\b/i.test(label)) {
            return 'Are you sure you want to reactivate this record?';
        }
        if (action.indexOf('close') !== -1 || /\bclose session\b/i.test(label)) {
            return 'Are you sure you want to close this session?';
        }
        if (action.indexOf('mark') !== -1 || /\bmark\b/i.test(label)) {
            return 'Are you sure you want to update the selected item status?';
        }
        if (action.indexOf('reset_password') !== -1 || /\breset password\b/i.test(label)) {
            return 'Are you sure you want to reset this user password?';
        }
        if (action.indexOf('create') !== -1 || action.indexOf('add') !== -1 || /\b(create|add)\b/i.test(label)) {
            return 'Are you sure you want to create this record?';
        }
        if (action.indexOf('save') !== -1 || action.indexOf('update') !== -1 || /\b(save|update|edit)\b/i.test(label)) {
            return 'Are you sure you want to save these changes?';
        }
        if (action.indexOf('post') !== -1 || action.indexOf('issue') !== -1 || action.indexOf('distribute') !== -1 || action.indexOf('transfer') !== -1 || action.indexOf('return') !== -1 || action.indexOf('dispose') !== -1 || /\b(post|issue|distribute|transfer|return|dispose)\b/i.test(label)) {
            return 'Are you sure you want to post this transaction?';
        }
        if (action.indexOf('approve') !== -1 || action.indexOf('resolve') !== -1 || /\b(approve|resolve)\b/i.test(label)) {
            return 'Are you sure you want to approve this change?';
        }
        if (action.indexOf('send') !== -1 || /\bsend\b/i.test(label)) {
            return 'Are you sure you want to send this message?';
        }
        if (action.indexOf('import') !== -1 || action.indexOf('upload') !== -1 || /\b(import|upload)\b/i.test(label)) {
            return 'Are you sure you want to import these records?';
        }
        return 'Are you sure you want to continue with this change?';
    }

    function requiresMutationConfirmation(form, submitter) {
        if (form.hasAttribute('data-confirm-required')) {
            return true;
        }

        var actionInput = form.querySelector('input[name="action"], select[name="action"]');
        var action = actionInput ? String(actionInput.value || '').toLowerCase() : '';
        var buttonText = submitter ? String(submitter.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase() : '';
        var mutationWords = /\b(save|update|create|add|delete|remove|deactivate|reactivate|merge|post|send|cancel|approve|resolve|issue|distribute|transfer|return|dispose|record|upload|import|reset password|close|mark|keep as primary|unit head)\b/;

        return mutationWords.test(action.replace(/[_-]+/g, ' ')) || mutationWords.test(buttonText);
    }

    function patchMutationForms(root) {
        if (!root) {
            return;
        }

        Array.prototype.slice.call(root.querySelectorAll('form[method="post"], form[method="POST"]')).forEach(function (form) {
            if (form.hasAttribute('data-no-confirm') || form.hasAttribute('data-confirm') || form.hasAttribute('data-confirm-message')) {
                return;
            }

            var submitButtons = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
            var defaultSubmitter = submitButtons.length === 1 ? submitButtons[0] : null;
            var hasMutationButton = submitButtons.some(function (button) {
                return requiresMutationConfirmation(form, button);
            });
            if (!requiresMutationConfirmation(form, defaultSubmitter) && !hasMutationButton) {
                return;
            }

            form.addEventListener('submit', function (event) {
                if (form.getAttribute('data-confirm-bypass') === '1') {
                    form.removeAttribute('data-confirm-bypass');
                    return;
                }

                if (event.defaultPrevented) {
                    return;
                }

                event.preventDefault();
                var submitter = event.submitter || document.activeElement;
                if (!requiresMutationConfirmation(form, submitter)) {
                    form.setAttribute('data-confirm-bypass', '1');
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                    } else {
                        form.submit();
                    }
                    return;
                }
                window.confirmAction({
                    title: 'Confirm change',
                    message: mutationMessage(form, submitter),
                    confirmText: 'Continue',
                    onConfirm: function () {
                        form.setAttribute('data-confirm-bypass', '1');
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                        } else {
                            form.submit();
                        }
                    }
                });
            });
        });
    }

    function patchAllConfirmations(root) {
        patchInlineConfirmHandlers(root);
        patchMutationForms(root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            patchAllConfirmations(document);
        });
    } else {
        patchAllConfirmations(document);
    }

    window.confirmAction = confirmAction;
})();
