document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-product-photo-dropzone]').forEach((zone) => {
        const input = zone.querySelector('[data-product-photo-input]');
        const preview = zone.querySelector('[data-product-photo-preview]');
        const status = zone.querySelector('[data-product-photo-status]');
        const parent = zone.parentElement;
        const error = parent.querySelector('[data-product-photo-error]');
        const remove = parent.querySelector('[data-product-photo-remove]');
        const existingPhoto = zone.dataset.existingPhoto;
        let previewUrl;
        let dragDepth = 0;

        const clearPhoto = () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = undefined;
            preview.onerror = null;
            preview.hidden = !existingPhoto;
            if (existingPhoto) preview.src = existingPhoto;
            else preview.removeAttribute('src');
            input.value = '';
            input.setCustomValidity('');
            input.setAttribute('aria-invalid', 'false');
            error.hidden = true;
            error.textContent = '';
            status.textContent = existingPhoto ? 'Current product photo' : '';
            remove.hidden = true;
        };
        const rejectPhoto = (message) => {
            clearPhoto();
            input.setCustomValidity(message);
            input.setAttribute('aria-invalid', 'true');
            error.textContent = message;
            error.hidden = false;
            remove.textContent = 'Clear selection';
            remove.hidden = false;
        };
        const selectPhoto = (files) => {
            if (!files.length) { clearPhoto(); return; }
            if (files.length !== 1) { rejectPhoto('Choose one product photo at a time.'); return; }
            const file = files[0];
            const allowedType = ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)
                || (!file.type && /\.(jpe?g|png|webp)$/i.test(file.name));
            if (!allowedType) { rejectPhoto('Choose a JPG, PNG, or WebP image.'); return; }
            if (file.size > 4 * 1024 * 1024) { rejectPhoto('Choose an image no larger than 4 MB.'); return; }

            clearPhoto();
            // Dropped files must be attached to the real field for the normal form upload.
            const transfer = new DataTransfer();
            transfer.items.add(file);
            input.files = transfer.files;
            previewUrl = URL.createObjectURL(file);
            const currentUrl = previewUrl;
            preview.onerror = () => {
                if (previewUrl === currentUrl) rejectPhoto('This image could not be opened. Choose another photo.');
            };
            preview.src = currentUrl;
            preview.hidden = false;
            status.textContent = file.name;
            remove.textContent = existingPhoto ? 'Keep current photo' : 'Remove photo';
            remove.hidden = false;
            const serverError = parent.querySelector('#image-error');
            if (serverError) serverError.hidden = true;
        };
        input.addEventListener('change', () => selectPhoto([...input.files]));
        remove.addEventListener('click', () => { clearPhoto(); input.focus(); });
        input.form.addEventListener('reset', clearPhoto);
        const draggingFiles = (event) => [...(event.dataTransfer?.types || [])].includes('Files');
        zone.addEventListener('dragenter', (event) => {
            if (!draggingFiles(event)) return;
            event.preventDefault();
            dragDepth++;
            zone.classList.add('is-dragging');
        });
        zone.addEventListener('dragover', (event) => {
            if (!draggingFiles(event)) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        });
        zone.addEventListener('dragleave', (event) => {
            event.preventDefault();
            dragDepth = Math.max(0, dragDepth - 1);
            if (!dragDepth) zone.classList.remove('is-dragging');
        });
        zone.addEventListener('drop', (event) => {
            if (!draggingFiles(event)) return;
            event.preventDefault();
            event.stopPropagation();
            dragDepth = 0;
            zone.classList.remove('is-dragging');
            selectPhoto([...event.dataTransfer.files]);
        });
    });

    document.querySelectorAll('[data-basic-image-dropzone]').forEach((zone) => {
        const input = zone.querySelector('input[type="file"]');
        const status = document.getElementById(`${input.id}-upload-status`);
        const maxBytes = Number(zone.dataset.maxBytes);
        input.addEventListener('change', () => {
            const file = input.files?.[0];
            let message = '';
            if (input.files.length > 1) message = 'Choose one image at a time.';
            else if (file && !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) message = 'Choose a JPG, PNG, or WebP image.';
            else if (file && file.size > maxBytes) message = `Choose an image no larger than ${maxBytes / 1048576} MB.`;
            input.setCustomValidity(message);
            input.setAttribute('aria-invalid', String(Boolean(message)));
            status.textContent = message || file?.name || '';
        });
        ['dragenter', 'dragover'].forEach((name) => zone.addEventListener(name, (event) => {
            if (input.matches(':disabled') || ![...(event.dataTransfer?.types || [])].includes('Files')) return;
            event.preventDefault();
            zone.classList.add('is-dragging');
        }));
        zone.addEventListener('dragleave', () => zone.classList.remove('is-dragging'));
        zone.addEventListener('drop', (event) => {
            if (input.matches(':disabled')) return;
            event.preventDefault();
            zone.classList.remove('is-dragging');
            if (!event.dataTransfer?.files.length) return;
            const transfer = new DataTransfer();
            for (const file of event.dataTransfer.files) transfer.items.add(file);
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    // Keep the native field for form submission and a no-JavaScript fallback.
    const scrollableFields = document.querySelectorAll('#catalog-filter-form select[name="category"], #catalog-filter-form select[name="wood_type"], #catalog-filter-form select[name="varnish_finish"]');
    for (const categoryField of scrollableFields) {
        const pickerId = categoryField.id;
        const wrapper = document.createElement('div');
        wrapper.className = 'category-picker';
        categoryField.before(wrapper);
        wrapper.append(categoryField);
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'form-select category-picker-trigger';
        trigger.id = `${pickerId}-picker-trigger`;
        trigger.setAttribute('role', 'combobox');
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-controls', `${pickerId}-picker-options`);
        const label = document.querySelector(`label[for="${pickerId}"]`);
        if (label) {
            label.id = `${pickerId}-picker-label`;
            label.htmlFor = trigger.id;
            trigger.setAttribute('aria-labelledby', label.id);
        }
        const list = document.createElement('div');
        list.id = `${pickerId}-picker-options`;
        list.className = 'category-picker-options';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', label?.textContent || pickerId);
        list.hidden = true;
        // Keep focus on the combobox until the option's click selects its value.
        list.addEventListener('mousedown', (event) => event.preventDefault());
        const options = [...categoryField.options].map((option, index) => {
            const item = document.createElement('div');
            item.id = `${pickerId}-picker-option-${index}`;
            item.className = 'category-picker-option';
            item.setAttribute('role', 'option');
            item.textContent = option.text;
            item.addEventListener('click', () => choose(index));
            list.append(item);
            return item;
        });
        let active = categoryField.selectedIndex;
        let typed = '';
        let typedAt = 0;
        const highlight = (index) => {
            active = Math.max(0, Math.min(options.length - 1, index));
            options.forEach((item, i) => item.classList.toggle('is-active', i === active));
            trigger.setAttribute('aria-activedescendant', options[active].id);
            if (!list.hidden) options[active].scrollIntoView({ block: 'nearest' });
        };
        const close = () => {
            list.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            trigger.removeAttribute('aria-activedescendant');
        };
        const sync = () => {
            trigger.textContent = categoryField.selectedOptions[0]?.text || 'All';
            options.forEach((item, index) => item.setAttribute('aria-selected', String(index === categoryField.selectedIndex)));
        };
        const open = () => {
            list.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            highlight(categoryField.selectedIndex);
        };
        const choose = (index) => {
            categoryField.selectedIndex = index;
            categoryField.dispatchEvent(new Event('change', { bubbles: true }));
            close();
            trigger.focus({ preventScroll: true });
        };
        trigger.addEventListener('click', () => list.hidden ? open() : close());
        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'Tab') { close(); return; }
            if (event.key === 'Escape') { event.preventDefault(); close(); return; }
            if (['Enter', ' '].includes(event.key)) {
                event.preventDefault();
                list.hidden ? open() : choose(active);
                return;
            }
            if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                event.preventDefault();
                if (list.hidden) open();
                highlight(event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1
                    : (active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
                return;
            }
            if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                typed = Date.now() - typedAt > 800 ? event.key : typed + event.key;
                typedAt = Date.now();
                if (list.hidden) open();
                const index = options.findIndex((item) => item.textContent.toLowerCase().startsWith(typed.toLowerCase()));
                if (index >= 0) highlight(index);
            }
        });
        document.addEventListener('click', (event) => { if (!wrapper.contains(event.target)) close(); });
        wrapper.addEventListener('focusout', (event) => { if (!wrapper.contains(event.relatedTarget)) close(); });
        categoryField.addEventListener('change', sync);
        categoryField.form?.addEventListener('reset', () => setTimeout(() => { sync(); close(); }, 0));
        wrapper.append(trigger, list);
        categoryField.hidden = true;
        sync();
    }
    const reservationPage = document.querySelector('[data-admin-reservations-page]');
    if (reservationPage) {
        const feedback = document.querySelector('[data-admin-pagination-error]');
        let pending;
        let requestNumber = 0;
        const loadReservations = async (url, pushHistory = true) => {
            const number = ++requestNumber;
            pending?.abort();
            const controller = new AbortController();
            pending = controller;
            const timeout = setTimeout(() => controller.abort(), 15000);
            reservationPage.setAttribute('aria-busy', 'true');
            feedback.hidden = true;
            try {
                const response = await fetch(url, {
                    signal: controller.signal,
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok || response.redirected) throw new Error('Unable to load reservations. Please try again or sign in again.');
                const html = await response.text();
                const updated = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-admin-reservations-page]');
                if (!updated) throw new Error('Unable to load reservations. Please try again.');
                if (number !== requestNumber) return;
                reservationPage.innerHTML = updated.innerHTML;
                if (pushHistory) history.pushState(null, '', url);
                reservationPage.focus({ preventScroll: true });
            } catch (error) {
                if (number !== requestNumber) return;
                feedback.textContent = error.name === 'AbortError'
                    ? 'Loading timed out. Please try the page number again.'
                    : error.message;
                feedback.hidden = false;
            } finally {
                clearTimeout(timeout);
                if (number === requestNumber) reservationPage.removeAttribute('aria-busy');
            }
        };
        reservationPage.addEventListener('click', (event) => {
            const link = event.target.closest('.pagination a, nav a.page-link');
            if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const url = new URL(link.href, location.href);
            if (url.origin !== location.origin || url.pathname !== location.pathname) return;
            event.preventDefault();
            loadReservations(url.href);
        });
        window.addEventListener('popstate', () => loadReservations(location.href, false));
    }
    const authSuccess = document.querySelector('[data-auth-success-dialog]');
    if (authSuccess) {
        authSuccess.addEventListener('close', () => document.querySelector('#email')?.focus());
        authSuccess.showModal();
    }
    const setupReservationAction = (dialogId, formSelector) => {
        const dialog = document.getElementById(dialogId);
        if (!dialog) return;
        const reason = dialog.querySelector('[data-cancellation-reason]');
        const item = dialog.querySelector('[data-reservation-action-item]');
        const submitting = new WeakSet();
        let activeForm = null;
        let approvedForm = null;
        let opener = null;
        document.querySelectorAll(formSelector).forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (approvedForm === form) {
                    approvedForm = null;
                    submitting.add(form);
                    form.setAttribute('aria-busy', 'true');
                    form.querySelector('button[type="submit"]').disabled = true;
                    return;
                }
                event.preventDefault();
                if (activeForm || submitting.has(form)) return;
                activeForm = form;
                opener = event.submitter || form.querySelector('button[type="submit"]');
                item.textContent = `${form.dataset.reservationName} · ${form.dataset.reservationReference}`;
                if (reason) reason.value = form.elements.cancellation_reason.value;
                dialog.returnValue = 'no';
                dialog.showModal();
            });
        });
        dialog.querySelector('[data-reservation-action-no]').addEventListener('click', () => dialog.close('no'));
        dialog.addEventListener('close', () => {
            const form = activeForm;
            activeForm = null;
            if (form && dialog.returnValue === 'yes') {
                if (reason) form.elements.cancellation_reason.value = reason.value;
                approvedForm = form;
                form.requestSubmit();
            } else {
                opener?.focus();
            }
        });
    };
    setupReservationAction('cancel-reservation-dialog', '[data-cancel-reservation]');
    setupReservationAction('delete-reservation-dialog', '[data-delete-reservation]');
    document.addEventListener('submit', (event) => {
        const message = event.submitter?.dataset.confirm || event.target.dataset.confirm;
        if (message && !window.confirm(message)) event.preventDefault();
    });
    const showImageFallback = (photo) => {
        const fallback = photo.dataset.imageFallback;
        if (!fallback) return;
        delete photo.dataset.imageFallback;
        photo.src = fallback;
    };
    document.addEventListener('error', (event) => {
        if (event.target instanceof HTMLImageElement) showImageFallback(event.target);
    }, true);
    document.querySelectorAll('img[data-image-fallback]').forEach((photo) => {
        if (photo.complete && !photo.naturalWidth) showImageFallback(photo);
    });
    // Presentation only: keep an unavailable photo inside its labelled frame.
    document.querySelectorAll('.planning-room-thumbnail').forEach((photo) => {
        const syncPhotoFrame = () => photo.parentElement.classList.toggle('is-unavailable', !photo.naturalWidth);
        photo.addEventListener('load', syncPhotoFrame);
        photo.addEventListener('error', syncPhotoFrame);
        if (photo.complete) syncPhotoFrame();
    });
    const welcomeToast = document.querySelector('[data-welcome-toast]');
    document.querySelectorAll('[data-confirm-item-ready]').forEach((form) => {
        const button = form.querySelector('button');
        let saving = false;
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (saving) return;
            saving = true;
            button.disabled = true;
            const label = button.textContent;
            button.textContent = 'Confirming…';
            form.setAttribute('aria-busy', 'true');
            let feedback = form.querySelector('[data-readiness-error]');
            if (feedback) feedback.remove();
            try {
                const response = await fetch(form.action, {
                    method: 'POST', body: new FormData(form), signal: AbortSignal.timeout(15000),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || response.redirected || !data.item_ready_confirmed_at) {
                    throw new Error([401, 419].includes(response.status) || response.redirected
                        ? 'Your session expired. Please sign in again.'
                        : Object.values(data.errors || {}).flat().join(' ') || data.message || 'Unable to confirm readiness. Please check the reservation status.');
                }
                const badge = document.createElement('span');
                badge.className = 'badge text-bg-success';
                badge.setAttribute('role', 'status');
                badge.textContent = data.label || 'Shop marked ready';
                form.replaceWith(badge);
            } catch (error) {
                feedback = document.createElement('p');
                feedback.dataset.readinessError = '';
                feedback.className = 'small text-danger mt-1 mb-0';
                feedback.setAttribute('role', 'alert');
                feedback.textContent = error.name === 'TimeoutError'
                    ? 'Confirmation timed out. Check the reservation status before trying again.' : error.message;
                form.append(feedback);
                button.disabled = false;
                button.textContent = label;
            } finally {
                saving = false;
                form.removeAttribute('aria-busy');
            }
        });
    });
    if (welcomeToast) {
        let dismissTimer;
        const pause = () => clearTimeout(dismissTimer);
        const resume = () => {
            pause();
            if (!welcomeToast.matches(':hover, :focus-within')) {
                dismissTimer = setTimeout(() => welcomeToast.remove(), 5000);
            }
        };
        welcomeToast.querySelector('[data-dismiss-welcome]').addEventListener('click', () => { pause(); welcomeToast.remove(); });
        welcomeToast.addEventListener('mouseenter', pause);
        welcomeToast.addEventListener('mouseleave', resume);
        welcomeToast.addEventListener('focusin', pause);
        welcomeToast.addEventListener('focusout', () => setTimeout(resume, 0));
        resume();
    }
    if (document.body.classList.contains('home-page')) {
        const navigation = document.querySelector('.fc-navbar');
        if (navigation) {
            const syncHomeHeight = () => document.body.style.setProperty('--home-nav-height', `${navigation.getBoundingClientRect().height}px`);
            new ResizeObserver(syncHomeHeight).observe(navigation);
            syncHomeHeight();
        }
    }
    const themeToggle = document.querySelector('[data-theme-toggle]');
    if (themeToggle) {
        const syncThemeToggle = () => {
            const darkMode = document.documentElement.dataset.theme === 'dark';
            themeToggle.setAttribute('aria-pressed', String(darkMode));
            themeToggle.textContent = darkMode ? 'Light mode' : 'Dark mode';
        };
        syncThemeToggle();
        const restoreTheme = () => {
            try { document.documentElement.dataset.theme = localStorage.getItem('furnicraft-theme') === 'dark' ? 'dark' : 'light'; } catch (_) {}
            syncThemeToggle();
        };
        window.addEventListener('pageshow', restoreTheme);
        window.addEventListener('storage', (event) => {
            if (event.key === 'furnicraft-theme' || event.key === null) restoreTheme();
        });
        themeToggle.addEventListener('click', () => {
            const darkMode = document.documentElement.dataset.theme !== 'dark';
            document.documentElement.dataset.theme = darkMode ? 'dark' : 'light';
            try { localStorage.setItem('furnicraft-theme', darkMode ? 'dark' : 'light'); } catch (_) {}
            syncThemeToggle();
        });
    }

    const reportForm = document.querySelector('[data-report-filters]');
    if (reportForm) {
        const input = reportForm.querySelector('[data-report-search]');
        const results = document.querySelector('[data-report-results]');
        const error = reportForm.querySelector('[data-report-search-error]');
        let timer;
        let pending;
        let searchVersion = 0;
        const clear = reportForm.querySelector('[data-search-clear]');
        const syncClear = () => { if (clear) clear.hidden = input.value.length === 0; };
        syncClear();
        clear?.addEventListener('click', () => {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        });
        input.addEventListener('input', () => {
            syncClear();
            const version = ++searchVersion;
            clearTimeout(timer);
            pending?.abort();
            timer = setTimeout(async () => {
                const controller = new AbortController();
                pending = controller;
                const url = new URL(reportForm.action);
                url.search = new URLSearchParams(new FormData(reportForm)).toString();
                results.setAttribute('aria-busy', 'true');
                error.hidden = true;
                try {
                    const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'text/html' } });
                    if (!response.ok || response.redirected) throw new Error('Search unavailable');
                    const document = new DOMParser().parseFromString(await response.text(), 'text/html');
                    const updated = document.querySelector('[data-report-results]');
                    if (!updated) throw new Error('Search unavailable');
                    if (controller.signal.aborted || version !== searchVersion) return;
                    results.innerHTML = updated.innerHTML;
                    history.replaceState(null, '', url);
                } catch (failure) {
                    if (failure.name !== 'AbortError' && version === searchVersion) {
                        error.textContent = 'Search is temporarily unavailable. Submit the search form to try again.';
                        error.hidden = false;
                    }
                } finally {
                    if (pending === controller) results.removeAttribute('aria-busy');
                }
            }, 280);
        });
    }
    document.querySelectorAll('[data-favorite-form]').forEach((form) => {
        const button = form.querySelector('button[type="submit"]');
        const message = document.querySelector('[data-favorite-message]');
        let saving = false;
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (saving) return;
            saving = true;
            button.disabled = true;
            const previousText = button.textContent;
            button.textContent = 'Saving…';
            if (message) message.hidden = true;
            try {
                const response = await fetch(form.action, {
                    method: 'POST', body: new FormData(form),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || typeof data.saved !== 'boolean') {
                    throw new Error([401, 419].includes(response.status) || response.redirected
                        ? 'Your session expired. Please sign in again.'
                        : 'Unable to update favorites. Please try again.');
                }
                button.textContent = data.saved ? 'Remove from favorites' : 'Save to favorites';
                button.classList.toggle('btn-dark', data.saved);
                button.classList.toggle('btn-outline-dark', !data.saved);
                button.setAttribute('aria-pressed', String(data.saved));
                if (message) {
                    message.className = 'small text-success';
                    message.textContent = data.saved ? 'Saved to your favorites.' : 'Removed from your favorites.';
                    message.hidden = false;
                }
            } catch (error) {
                button.textContent = previousText;
                if (message) {
                    message.className = 'small text-danger';
                    message.textContent = error.message || 'Connection failed. Please try again.';
                    message.hidden = false;
                }
            } finally {
                saving = false;
                button.disabled = false;
            }
        });
    });
    document.querySelectorAll('[data-profile-dialog-open]').forEach((button) => {
        const dialog = document.getElementById(button.dataset.profileDialogOpen);
        if (!dialog) return;
        button.addEventListener('click', () => dialog.showModal());
        dialog.querySelectorAll('[data-profile-dialog-close]').forEach((closeButton) => {
            closeButton.addEventListener('click', () => dialog.close());
        });
        dialog.addEventListener('close', () => button.focus());
        if (dialog.hasAttribute('data-open-on-load')) dialog.showModal();
    });
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        if (!input) return;
        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(visible));
            button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            button.title = visible ? 'Hide password' : 'Show password';
            button.querySelector('[data-password-slash]').style.display = visible ? '' : 'none';
        });
    });
    const reservationDialog = document.querySelector('#reservation-dialog');
    if (reservationDialog) {
        const opener = document.querySelector('[data-reservation-open]');
        const form = reservationDialog.querySelector('[data-reservation-form]');
        const message = reservationDialog.querySelector('[data-reservation-message]');
        const done = reservationDialog.querySelector('[data-reservation-done]');
        done?.querySelector('button').addEventListener('click', () => reservationDialog.close());
        let sending = false;
        let submitted = false;
        opener.addEventListener('click', () => reservationDialog.showModal());
        reservationDialog.querySelector('[data-reservation-close]').addEventListener('click', () => reservationDialog.close());
        reservationDialog.addEventListener('close', () => opener.focus());
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (sending || submitted) return;
            sending = true;
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = 'Sending request…';
            message.hidden = true;
            try {
                const response = await fetch(form.action, {
                    method: 'POST', body: new FormData(form),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const errors = Object.values(data.errors || {}).flat();
                    throw new Error(errors.join(' ') || (response.status === 419 || response.status === 401
                        ? 'Your session expired. Sign in again before submitting.'
                        : data.message || 'Unable to submit. Please try again.'));
                }
                submitted = true;
                if (done) done.hidden = false;
                form.hidden = true;
                form.classList.remove('vstack');
                opener.textContent = 'View request confirmation';
                message.className = 'alert alert-success';
                message.textContent = data.message;
            } catch (error) {
                message.className = 'alert alert-danger';
                message.textContent = error.message || 'Connection failed. Please try again.';
            } finally {
                sending = false;
                button.disabled = submitted;
                button.textContent = 'Send reservation request';
                message.hidden = false;
                message.focus();
            }
        });
    }
    const roomPhotoInput = document.querySelector('[data-room-photo-input]');
    if (roomPhotoInput) {
        const dropzone = document.querySelector('[data-room-dropzone]');
        const fileName = document.querySelector('[data-room-file-name]');
        const inputPreview = document.querySelector('[data-room-input-preview]');
        const inputPlaceholder = document.querySelector('[data-room-input-placeholder]');
        let inputPreviewUrl;
        const clearInputPreview = () => {
            if (inputPreviewUrl) URL.revokeObjectURL(inputPreviewUrl);
            inputPreviewUrl = null;
            if (inputPreview) { inputPreview.hidden = true; inputPreview.removeAttribute('src'); }
            if (inputPlaceholder) inputPlaceholder.hidden = false;
        };
        inputPreview?.addEventListener('error', clearInputPreview);
        window.addEventListener('pagehide', event => { if (!event.persisted) clearInputPreview(); });
        roomPhotoInput.addEventListener('change', () => {
            const file = roomPhotoInput.files?.[0];
            if (fileName) fileName.textContent = file ? file.name : '';
            clearInputPreview();
            if (inputPreview && file && ['image/jpeg', 'image/png', 'image/webp'].includes(file.type) && file.size <= 8 * 1024 * 1024) {
                inputPreviewUrl = URL.createObjectURL(file);
                inputPreview.src = inputPreviewUrl;
                inputPreview.hidden = false;
                if (inputPlaceholder) inputPlaceholder.hidden = true;
            }
        });
        if (dropzone) {
            ['dragenter', 'dragover'].forEach(eventName => dropzone.addEventListener(eventName, event => {
                event.preventDefault();
                if (!roomPhotoInput.matches(':disabled')) dropzone.classList.add('is-dragging');
            }));
            dropzone.addEventListener('dragleave', event => {
                if (!dropzone.contains(event.relatedTarget)) dropzone.classList.remove('is-dragging');
            });
            dropzone.addEventListener('drop', event => {
                event.preventDefault();
                dropzone.classList.remove('is-dragging');
                if (roomPhotoInput.matches(':disabled')) return;
                const file = event.dataTransfer?.files?.[0];
                if (!file) return;
                const transfer = new DataTransfer();
                transfer.items.add(file);
                roomPhotoInput.files = transfer.files;
                roomPhotoInput.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }
        const uploadForm = roomPhotoInput.closest('form');
        const qualityMessage = uploadForm?.querySelector('[data-room-photo-quality]');
        const uploadButton = uploadForm?.querySelector('button[type="submit"]');
        let photoAccepted = false;
        let qualityRejected = false;
        const showRetake = (message) => {
            if (!qualityMessage) return;
            qualityMessage.replaceChildren(document.createTextNode(message + ' '));
            const retry = document.createElement('button');
            retry.type = 'button'; retry.className = 'btn btn-sm btn-outline-secondary ms-2'; retry.textContent = 'Retake';
            retry.addEventListener('click', () => { roomPhotoInput.value = ''; clearInputPreview(); if (fileName) fileName.textContent = ''; photoAccepted = false; qualityRejected = false; qualityMessage.textContent = ''; roomPhotoInput.click(); });
            qualityMessage.append(retry);
            qualityRejected = true;
        };
        roomPhotoInput.addEventListener('change', async () => {
            photoAccepted = false; qualityRejected = false;
            const file = roomPhotoInput.files?.[0];
            if (!file) return;
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { showRetake('Choose a JPG, PNG, or WebP image.'); return; }
            if (file.size > 8 * 1024 * 1024) { showRetake('This photo is over 8 MB. Choose a smaller image.'); return; }
            try {
                const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
                if (Math.max(bitmap.width, bitmap.height) < 600) { bitmap.close(); showRetake('This photo is too small. Choose one at least 600 px on its longest side.'); return; }
                const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(bitmap.width * scale));
                canvas.height = Math.max(1, Math.round(bitmap.height * scale));
                const context = canvas.getContext('2d', { willReadFrequently: true });
                context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                bitmap.close();
                const sample = document.createElement('canvas');
                sample.width = 160; sample.height = Math.max(1, Math.round(160 * canvas.height / canvas.width));
                const sampleContext = sample.getContext('2d', { willReadFrequently: true });
                sampleContext.drawImage(canvas, 0, 0, sample.width, sample.height);
                const pixels = sampleContext.getImageData(0, 0, sample.width, sample.height).data;
                const gray = new Float32Array(sample.width * sample.height);
                let luminance = 0;
                for (let i = 0, p = 0; i < pixels.length; i += 4, p += 1) {
                    gray[p] = .2126 * pixels[i] + .7152 * pixels[i + 1] + .0722 * pixels[i + 2];
                    luminance += gray[p];
                }
                luminance /= gray.length;
                let sum = 0, sumSquares = 0, count = 0;
                for (let y = 1; y < sample.height - 1; y += 1) for (let x = 1; x < sample.width - 1; x += 1) {
                    const i = y * sample.width + x;
                    const laplacian = gray[i - 1] + gray[i + 1] + gray[i - sample.width] + gray[i + sample.width] - 4 * gray[i];
                    sum += laplacian; sumSquares += laplacian * laplacian; count += 1;
                }
                const variance = count ? sumSquares / count - (sum / count) ** 2 : 0;
                if (luminance < 42) { showRetake('This photo is too dark to analyze. Retake it in brighter light.'); return; }
                if (luminance > 242) { showRetake('This photo is too bright to analyze. Retake it away from direct glare.'); return; }
                if (variance < 24) { showRetake('This photo looks blurry. Hold the camera steady and retake it.'); return; }
                const processed = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', .88));
                if (!processed) { showRetake('This photo could not be prepared. Please retake it.'); return; }
                const transfer = new DataTransfer();
                transfer.items.add(new File([processed], 'room-photo.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
                roomPhotoInput.files = transfer.files;
                photoAccepted = true;
                if (qualityMessage) qualityMessage.textContent = 'Photo checked and prepared for automatic room fitting.';
            } catch (error) {
                showRetake('This photo could not be checked. Please choose another photo.');
            }
        });
        const consent = uploadForm?.querySelector('[name="gemini_consent"]');
        const choice = document.createElement('dialog');
        choice.className = 'reservation-dialog';
        choice.setAttribute('aria-labelledby', 'upload-ai-choice-title');
        const providerName = uploadForm?.querySelector('[name="ai_consent_provider"]')?.value === 'groq' ? 'Groq' : 'Google Gemini';
        choice.innerHTML = '<h2 class="h4" id="upload-ai-choice-title">Enable AI analysis?</h2><p>Enable AI to send this room photo to ' + providerName + ' for room analysis and placement checking. Continue without AI to use local guidance without sending the photo to an AI provider.</p><div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-primary" data-enable-ai>Enable AI</button><button type="button" class="btn btn-outline-dark" data-without-ai>Continue without AI</button><button type="button" class="btn btn-link" data-cancel-upload>Cancel</button></div>';
        document.body.append(choice);
        let continueLocally = false;
        choice.querySelector('[data-enable-ai]').addEventListener('click', () => {
            if (consent) consent.checked = true;
            choice.close();
            uploadForm.requestSubmit();
        });
        choice.querySelector('[data-without-ai]').addEventListener('click', () => {
            if (consent) consent.checked = false;
            continueLocally = true;
            choice.close();
            uploadForm.requestSubmit();
            continueLocally = false;
        });
        choice.querySelector('[data-cancel-upload]').addEventListener('click', () => choice.close());
        choice.addEventListener('close', () => uploadButton?.focus());
        let uploadInProgress = false;
        uploadForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (uploadInProgress) return;
            if (!photoAccepted || qualityRejected) { event.preventDefault(); if (!roomPhotoInput.files?.length) roomPhotoInput.click(); return; }
            if (consent && !consent.checked && !continueLocally) {
                event.preventDefault();
                if (!choice.open) choice.showModal();
                return;
            }
            if (uploadButton) { uploadButton.disabled = true; uploadButton.textContent = consent?.checked ? 'Uploading · Analyzing room…' : 'Uploading · Preparing local preview…'; }
            uploadInProgress = true;
            try {
                const response = await fetch(uploadForm.action, {
                    method: 'POST', body: new FormData(uploadForm),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || 'Could not upload this photo. Please try again.');
                document.dispatchEvent(new CustomEvent('room-photo-uploaded', { detail: payload }));
                photoAccepted = false;
                if (qualityMessage) qualityMessage.textContent = '';
            } catch (error) {
                if (qualityMessage) qualityMessage.textContent = error.message || 'Could not upload this photo. Please try again.';
            } finally {
                uploadInProgress = false;
                if (uploadButton) { uploadButton.disabled = false; uploadButton.textContent = 'Upload room photo'; }
            }
        });
    }
    const preferencesForm = document.querySelector('[data-preferences-form]');

    if (preferencesForm) {
        const submitButton = preferencesForm.querySelector('[data-preferences-submit]');
        const status = preferencesForm.querySelector('[data-preferences-status]');
        const defaultButtonText = submitButton?.textContent ?? 'Update preferences';
        const snapshot = () => JSON.stringify(
            [...new FormData(preferencesForm).entries()]
                .filter(([name]) => name !== '_token')
                .map(([name, value]) => [name, String(value)])
        );
        let savedSnapshot = snapshot();
        let savedInPlace = false;

        const syncSubmitState = (force = false) => {
            if (!submitButton || (!force && submitButton.textContent === 'Saving…')) return;
            const changed = snapshot() !== savedSnapshot;
            submitButton.textContent = savedInPlace && !changed ? 'Updated successfully' : (savedInPlace ? 'Update preferences' : defaultButtonText);
            submitButton.disabled = savedInPlace && !changed;
        };

        const clearErrors = () => {
            preferencesForm.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
            preferencesForm.querySelectorAll('[data-async-error]').forEach((message) => message.remove());
        };

        const showStatus = (message, successful) => {
            if (!status) return;
            status.hidden = false;
            status.textContent = message;
            status.classList.toggle('alert-success', successful);
            status.classList.toggle('alert-danger', !successful);
        };

        preferencesForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            clearErrors();
            if (status) status.hidden = true;
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Saving…';
            }

            try {
                const response = await fetch(preferencesForm.action, {
                    method: preferencesForm.method,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(preferencesForm),
                });
                const payload = await response.json();

                if (!response.ok) {
                    const errors = payload.errors ?? {};
                    let firstInvalid = null;
                    Object.entries(errors).forEach(([name, messages]) => {
                        const field = preferencesForm.elements.namedItem(name);
                        if (!(field instanceof HTMLElement)) return;
                        field.classList.add('is-invalid');
                        const feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback d-block';
                        feedback.dataset.asyncError = '';
                        feedback.textContent = Array.isArray(messages) ? messages[0] : messages;
                        field.insertAdjacentElement('afterend', feedback);
                        firstInvalid ??= field;
                    });
                    showStatus(payload.message ?? 'Please check the highlighted preferences.', false);
                    firstInvalid?.focus();
                    return;
                }

                const furnitureType = payload.preference?.furniture_type ?? preferencesForm.elements.namedItem('furniture_type')?.value ?? 'furniture';
                if (status) status.hidden = true;

                const savedState = document.querySelector('[data-preference-save-state]');
                if (savedState) savedState.hidden = false;
                document.querySelector('[data-step-one-warning]')?.setAttribute('hidden', '');
                const roomFieldset = document.querySelector('[data-room-upload-fieldset]');
                if (roomFieldset) roomFieldset.disabled = false;
                document.querySelectorAll('[data-saved-furniture-type]').forEach((element) => element.textContent = furnitureType);
                document.querySelectorAll('[data-saved-furniture-type-lower]').forEach((element) => element.textContent = furnitureType.toLowerCase());
                const savedBudget = document.querySelector('[data-saved-budget]');
                if (savedBudget && payload.preference) {
                    const money = (value) => Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    savedBudget.textContent = `PHP ${money(payload.preference.budget_min)} – ${money(payload.preference.budget_max)}`;
                }

                const stepOne = document.querySelector('[data-workflow-step="1"]');
                const stepTwo = document.querySelector('[data-workflow-step="2"]');
                stepOne?.classList.remove('is-current');
                stepOne?.classList.add('is-complete');
                stepOne?.removeAttribute('aria-current');
                stepTwo?.classList.add('is-current');
                stepTwo?.setAttribute('aria-current', 'step');

                const roomPicker = document.querySelector('#room_image_id');
                const generateButton = document.querySelector('[data-generate-recommendations]');
                if (generateButton) generateButton.disabled = false;
                const skipRoom = document.querySelector('[data-skip-room]');
                if (skipRoom) skipRoom.disabled = false;
                savedSnapshot = snapshot();
                savedInPlace = true;
            } catch (error) {
                showStatus('Preferences could not be saved right now. Please try again.', false);
            } finally {
                if (submitButton) {
                    if (!savedInPlace) {
                        submitButton.disabled = false;
                        if (submitButton.textContent === 'Saving…') submitButton.textContent = defaultButtonText;
                    } else {
                        syncSubmitState(true);
                    }
                }
            }
        });

        preferencesForm.addEventListener('input', syncSubmitState);
        preferencesForm.addEventListener('change', syncSubmitState);
    }

    const catalogHeading = document.querySelector('.catalog-page-heading');
    if (catalogHeading) {
        const navigation = document.querySelector('.fc-navbar');
        const catalog = catalogHeading.closest('.catalog-page');
        const sidebar = catalog.querySelector('.catalog-filters');
        let layoutFrame;
        const syncCatalogOffsets = () => {
            const navigationHeight = navigation?.getBoundingClientRect().height || 0;
            const headingHeight = catalogHeading.getBoundingClientRect().height;
            const offset = Math.ceil(navigationHeight + headingHeight + 8);
            catalog.style.setProperty('--catalog-nav-height', `${navigationHeight}px`);
            catalog.style.setProperty('--catalog-content-offset', `${offset}px`);
            document.documentElement.style.setProperty('--catalog-content-offset', `${offset}px`);
            // Keep every filter reachable when a short viewport cannot fit the whole sidebar.
            catalog.classList.toggle('catalog-filters-flow', Boolean(sidebar && sidebar.getBoundingClientRect().height > window.innerHeight - offset - 8));
        };
        const scheduleCatalogOffsets = () => {
            cancelAnimationFrame(layoutFrame);
            layoutFrame = requestAnimationFrame(syncCatalogOffsets);
        };
        const observer = new ResizeObserver(scheduleCatalogOffsets);
        observer.observe(catalogHeading);
        if (navigation) observer.observe(navigation);
        if (sidebar) observer.observe(sidebar);
        window.addEventListener('resize', scheduleCatalogOffsets);
        syncCatalogOffsets();
    }

    const catalogFilters = document.querySelector('[data-catalog-filter-disclosure]');
    if (catalogFilters) {
        const mobileFilters = window.matchMedia('(max-width: 991.98px)');
        const syncFilterDisclosure = () => {
            catalogFilters.open = !mobileFilters.matches || window.location.search.length > 0;
        };
        syncFilterDisclosure();
        mobileFilters.addEventListener('change', syncFilterDisclosure);
    }

    const input = document.querySelector('[data-live-search]');
    if (input) {
        const box = document.querySelector('[data-search-results]');
        const results = document.querySelector('[data-catalog-results]');
        const form = input.form;
        const clearSearch = document.querySelector('[data-search-clear]');
        const searchStatus = document.querySelector('[data-search-status]');
        const applyButton = form.querySelector('[data-filter-apply]');
        applyButton?.classList.add('d-none');
        let timer, searchController;
        let requestNumber = 0;
        let resettingFilters = false;
        const clearFilters = () => {
            resettingFilters = true;
            for (const name of ['category', 'style', 'wood_type', 'varnish_finish', 'min_price', 'max_price']) {
                const field = form.elements.namedItem(name);
                if (field) {
                    field.value = '';
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            resettingFilters = false;
        };
        const cancelSearch = () => {
            clearTimeout(timer);
            searchController?.abort();
            return ++requestNumber;
        };
        const updateCatalog = (delay = 0) => {
            const currentRequest = cancelSearch();
            const query = input.value.trim();
            const url = new URL(form.action);
            // Keep all selected filters, but always restart at the first page.
            for (const [name, value] of new FormData(form)) {
                if (name !== 'page' && String(value).trim()) url.searchParams.set(name, String(value).trim());
            }
            if (clearSearch) clearSearch.hidden = input.value.length === 0;
            box.hidden = true;
            box.innerHTML = '';
            results.removeAttribute('aria-busy');
            if (searchStatus) searchStatus.textContent = 'Updating catalog results…';
            timer = setTimeout(async () => {
                const controller = new AbortController();
                searchController = controller;
                results.setAttribute('aria-busy', 'true');
                try {
                    // One request uses the full catalog query, total, and pagination.
                    const response = await fetch(url, { headers: { Accept: 'text/html' }, signal: controller.signal });
                    if (!response.ok || response.redirected) throw new Error('Search unavailable');
                    const updatedPage = new DOMParser().parseFromString(await response.text(), 'text/html');
                    const updatedResults = updatedPage.querySelector('[data-catalog-results]');
                    if (!updatedResults) throw new Error('Search unavailable');
                    if (currentRequest !== requestNumber || controller.signal.aborted) return;
                    results.innerHTML = updatedResults.innerHTML;
                    applyButton?.classList.add('d-none');
                    history.replaceState(null, '', url);
                    const count = results.querySelector('[data-product-count]')?.textContent?.trim() ?? '';
                    if (searchStatus) searchStatus.textContent = query ? `${count} matching "${query}".` : `${count} in the catalog.`;
                } catch (error) {
                    if (error.name === 'AbortError' || currentRequest !== requestNumber) return;
                    const message = 'Catalog results could not be updated. Results are unchanged. Select Apply or press Enter to try again.';
                    applyButton?.classList.remove('d-none');
                    box.innerHTML = `<div class="p-3 text-muted" role="status">${message}</div>`;
                    box.hidden = false;
                    if (searchStatus) searchStatus.textContent = message;
                } finally {
                    if (currentRequest === requestNumber) results.removeAttribute('aria-busy');
                }
            }, delay);
        };
        input.addEventListener('input', () => {
            clearFilters();
            updateCatalog(280);
        });
        form.addEventListener('change', (event) => {
            if (!resettingFilters && event.target.matches('select, input[type="number"]')) updateCatalog();
        });
        if (clearSearch) {
            clearSearch.hidden = input.value.length === 0;
            clearSearch.addEventListener('click', () => {
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
            });
        }
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') box.hidden = true;
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            updateCatalog();
        });
        document.addEventListener('click', (event) => {
            if (!input.closest('.search-wrap').contains(event.target)) box.hidden = true;
        });
    }

    const roomOptions = [...document.querySelectorAll('[data-room-option]')];
    const deleteDialog = document.createElement('dialog');
    deleteDialog.className = 'reservation-dialog';
    deleteDialog.setAttribute('aria-labelledby', 'delete-room-title');
    deleteDialog.setAttribute('aria-describedby', 'delete-room-description');
    deleteDialog.innerHTML = '<h2 class="h4" id="delete-room-title">Delete this room photo?</h2><p id="delete-room-description">This will delete the room photo and its generated previews. This cannot be undone.</p><div class="d-flex flex-wrap justify-content-end gap-2"><button type="button" class="btn btn-outline-dark" data-cancel-delete autofocus>Cancel</button><button type="button" class="btn btn-danger" data-confirm-delete>Yes, delete photo</button></div>';
    document.body.append(deleteDialog);
    let resolveDelete = null;
    deleteDialog.querySelector('[data-cancel-delete]').addEventListener('click', () => deleteDialog.close('cancel'));
    deleteDialog.querySelector('[data-confirm-delete]').addEventListener('click', () => deleteDialog.close('delete'));
    deleteDialog.addEventListener('close', () => {
        resolveDelete?.(deleteDialog.returnValue === 'delete');
        resolveDelete = null;
    });
    const confirmRoomDeletion = () => new Promise((resolve) => {
        resolveDelete = resolve;
        deleteDialog.returnValue = 'cancel';
        deleteDialog.showModal();
    });
    const bindRoomDeletion = (form) => {
        const status = document.createElement('p');
        status.className = 'small mt-2 mb-0';
        status.setAttribute('role', 'status');
        form.append(status);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            if (button.disabled || deleteDialog.open) return;
            if (!await confirmRoomDeletion()) { button.focus(); return; }
            button.disabled = true;
            button.textContent = 'Deleting…';
            status.textContent = '';
            try {
                const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(data.message || 'Could not delete this photo. Please try again.');
                const card = form.closest('[data-room-option]');
                const container = card.parentElement;
                const wasSelected = roomPicker?.value === card.dataset.roomId;
                roomOptions.splice(roomOptions.indexOf(card), 1);
                card.remove();
                if (wasSelected) {
                    roomPicker.value = '';
                    if (!roomPhotoInput?.files?.length) roomPhotoInput?.dispatchEvent(new Event('change', { bubbles: true }));
                    const hero = document.querySelector('[data-planner-hero]');
                    if (hero) {
                        hero.src = hero.dataset.examplePhoto;
                        hero.alt = 'Example room for inspiration';
                        const caption = document.querySelector('[data-planner-photo-caption]');
                        if (caption) caption.textContent = 'Example room · Your photo will appear here';
                    }
                    if (generateRecommendations) generateRecommendations.disabled = false;
                    if (roomStepState) roomStepState.hidden = true;
                    if (selectedRoomLabel) selectedRoomLabel.textContent = 'No room selected. Recommendations will use your saved preferences.';
                }
                if (roomSelectionStatus) roomSelectionStatus.textContent = data.message;
                if (!roomOptions.length) {
                    const empty = document.createElement('div');
                    empty.className = 'empty-state';
                    empty.textContent = 'No room photos yet. Upload a room photo to generate recommendations.';
                    container.append(empty);
                }
                (roomOptions[0] || document.querySelector('[data-room-photo-input]'))?.focus();
            } catch (error) {
                status.textContent = error.message;
                status.classList.add('text-danger');
                button.disabled = false;
                button.textContent = 'Delete photo';
            }
        });
    };
    document.querySelectorAll('[data-delete-room]').forEach(bindRoomDeletion);
    const roomPicker = document.querySelector('#room_image_id');
    const roomSelectionStatus = document.querySelector('[data-room-selection-status]');
    const roomStepState = document.querySelector('[data-room-step-state]');
    const selectedRoomLabel = document.querySelector('[data-selected-room-label]');
    const generateRecommendations = document.querySelector('[data-generate-recommendations]');
    const roomLibrary = document.querySelector('[data-room-library]');
    const confirmRoom = document.querySelector('[data-confirm-room-library]');
    let pendingRoomId = '';
    const paintRoomChoice = (id) => {
        roomOptions.forEach(option => {
            const selected = option.dataset.roomId === id;
            option.classList.toggle('is-selected', selected);
            option.setAttribute('aria-pressed', String(selected));
            const badge = option.querySelector('[data-room-selected-badge]');
            if (badge) badge.hidden = !selected;
        });
        if (confirmRoom) confirmRoom.disabled = !roomOptions.some(option => option.dataset.roomId === id);
    };
    document.querySelector('[data-open-room-library]')?.addEventListener('click', () => {
        pendingRoomId = roomPicker?.value || '';
        paintRoomChoice(pendingRoomId);
        if (roomSelectionStatus) roomSelectionStatus.textContent = 'Choose a room photo, then press OK to use it.';
        roomLibrary.showModal();
    });
    document.querySelector('[data-cancel-room-library]')?.addEventListener('click', () => roomLibrary.close());
    roomLibrary?.addEventListener('close', () => paintRoomChoice(roomPicker?.value || ''));
    document.querySelector('[data-skip-room]')?.addEventListener('click', () => {
        roomPicker.value = '';
        document.querySelector('#recommendation-form').requestSubmit();
    });

    if (roomPicker) {
        const selectRoom = (selectedOption, announce = true) => {
            roomOptions.forEach((option) => {
                const selected = option === selectedOption;
                option.classList.toggle('is-selected', selected);
                option.setAttribute('aria-pressed', String(selected));
                const badge = option.querySelector('[data-room-selected-badge]');
                if (badge) badge.hidden = !selected;
            });

            roomPicker.value = selectedOption.dataset.roomId;
            const preview = document.querySelector('[data-room-input-preview]');
            if (preview && selectedOption.dataset.photoUrl) {
                if (roomPhotoInput?.files?.length) {
                    roomPhotoInput.value = '';
                    roomPhotoInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
                preview.src = selectedOption.dataset.photoUrl;
                preview.hidden = false;
                const placeholder = document.querySelector('[data-room-input-placeholder]');
                if (placeholder) placeholder.hidden = true;
            }
            const hero = document.querySelector('[data-planner-hero]');
            if (hero && selectedOption.dataset.photoUrl) {
                hero.src = selectedOption.dataset.photoUrl;
                hero.alt = 'Selected room photo';
                const caption = document.querySelector('[data-planner-photo-caption]');
                if (caption) caption.textContent = 'Selected room photo';
            }
            const roomReady = selectedOption.dataset.roomReady === 'true';
            if (roomStepState) roomStepState.hidden = false;
            if (generateRecommendations) generateRecommendations.disabled = !roomReady;
            if (selectedRoomLabel) {
                selectedRoomLabel.textContent = roomReady
                    ? 'Saved room photo selected. Only products matching your saved furniture type and preferences will be ranked.'
                    : 'The saved room photo is still being analyzed. Recommendations will unlock automatically when it is ready.';
            }
            if (announce && roomSelectionStatus) {
                roomSelectionStatus.textContent = 'Saved room photo selected. You can generate recommendations below without reloading the page.';
            }
        };

        const chooseRoom = option => {
            if (roomLibrary?.open) {
                pendingRoomId = option.dataset.roomId;
                paintRoomChoice(pendingRoomId);
            } else selectRoom(option);
        };
        confirmRoom?.addEventListener('click', () => {
            const option = roomOptions.find(item => item.dataset.roomId === pendingRoomId);
            if (!option) { paintRoomChoice(''); return; }
            selectRoom(option);
            roomLibrary.close();
        });

        const bindRoomChoice = (option) => {
            option.addEventListener('click', (event) => {
                if (event.target.closest('a, button, form')) return;
                chooseRoom(option);
            });
            option.addEventListener('keydown', (event) => {
                if (event.target.closest('a, button, form')) return;
                if (!['Enter', ' '].includes(event.key)) return;
                event.preventDefault();
                chooseRoom(option);
            });
        };
        roomOptions.forEach(bindRoomChoice);

        document.addEventListener('room-photo-uploaded', event => {
            const template = document.createElement('template');
            template.innerHTML = event.detail.html;
            const option = template.content.querySelector('[data-room-option]');
            const list = document.querySelector('.planner-room-list');
            if (!option || !list) return;
            list.querySelector('.empty-state')?.remove();
            list.prepend(option);
            roomOptions.unshift(option);
            bindRoomChoice(option);
            option.querySelectorAll('[data-delete-room]').forEach(bindRoomDeletion);
            selectRoom(option, false);
            watchRoomAnalysis([option]);
        });

        roomPicker.addEventListener('change', () => {
            const option = roomOptions.find((item) => item.dataset.roomId === roomPicker.value);
            if (option) selectRoom(option, false);
        });

        const initialRoom = roomOptions.find((option) => option.dataset.roomId === roomPicker.value);
        if (initialRoom) selectRoom(initialRoom, false);
    }

    // Sequential polling, bounded in time and count; never overlap slow requests.
    const startBoundedPoll = (poll, pending) => {
        const deadline = Date.now() + 600000;
        let attempts = 0;
        const tick = async () => {
            if (!pending()) return;
            if (Date.now() >= deadline || attempts >= 120) {
                document.querySelectorAll('[data-room-analysis-message]').forEach((element) => {
                    const room = element.closest('[data-room-analysis-watch]');
                    if (room && ['queued', 'processing', 'retrying'].includes(room.dataset.roomAnalysisCurrent)) {
                        element.textContent = 'Automatic status checks have stopped. Refresh this page to check progress or recover a timed-out analysis. Local guidance remains available.';
                    }
                });
                return;
            }
            if (!document.hidden) { attempts++; await poll(); }
            if (pending()) window.setTimeout(tick, 3000);
        };
        window.setTimeout(tick, 3000);
    };
    const activeAnalysisStatuses = ['queued', 'processing', 'retrying'];
    const watchRoomAnalysis = (analysisWatches) => {
    if (analysisWatches.some((element) => activeAnalysisStatuses.includes(element.dataset.roomAnalysisCurrent || (element.dataset.roomReady === 'true' ? 'completed' : 'queued')))) {
        const labels = { queued: 'Room analysis queued', processing: 'Analyzing room image', retrying: 'AI temporarily unavailable · retrying', completed: 'AI analyzed', fallback: 'Local guidance', failed: 'Needs retry' };
        const poll = async () => {
            for (const element of analysisWatches) {
                if (!element.isConnected) continue;
                const current = element.dataset.roomAnalysisCurrent || (element.dataset.roomReady === 'true' ? 'completed' : 'queued');
                if (element.dataset.pollingStopped || !activeAnalysisStatuses.includes(current)) continue;
                try {
                    const watchedFrame = document.querySelector('[data-room-preview]');
                    const statusUrl = new URL(element.dataset.roomAnalysisWatch, location.href);
                    if (watchedFrame?.dataset.productId) statusUrl.searchParams.set('product', watchedFrame.dataset.productId);
                    const response = await fetch(statusUrl, { signal: AbortSignal.timeout(10000), headers: { Accept: 'application/json' } });
                    if (!response.ok) { if ([401, 403, 404, 429].includes(response.status)) element.dataset.pollingStopped = 'true'; continue; }
                    const payload = await response.json();
                    if (payload.product_id && watchedFrame && String(payload.product_id) !== watchedFrame.dataset.productId) continue;
                    element.dataset.roomAnalysisCurrent = payload.status;
                    const retry = element.querySelector('[data-room-retry]');
                    if (retry) retry.disabled = activeAnalysisStatuses.includes(payload.status);
                    element.dataset.roomReady = String(Boolean(payload.ready));
                    if (roomPicker && element.dataset.roomId === roomPicker.value) {
                        roomPicker.dispatchEvent(new Event('change'));
                    }
                    element.querySelector('[data-room-analysis-label]')?.replaceChildren(document.createTextNode(labels[payload.status] || payload.status));
                    const message = element.querySelector('[data-room-analysis-message]');
                    if (message) message.textContent = payload.message || (payload.ready ? 'Room analysis is ready.' : '');
                    if ((payload.ready && payload.analysis) || payload.status === 'failed') {
                        const previewAnalysis = document.querySelector('[data-preview-analysis]');
                        const previewFrame = document.querySelector('[data-room-preview]');
                        if (payload.analysis && previewAnalysis && previewFrame) {
                            if (payload.placement) {
                                const p = payload.placement;
                                Object.assign(previewFrame.dataset, {
                                    initialX: String(p.x_percent), initialY: String(p.y_percent), initialWidth: String(p.max_width_percent),
                                    initialQuad: JSON.stringify(p.quad_percent), placementRenderable: String(p.renderable), placementSurface: p.surface,
                                    placementSource: p.source, placementConfidence: p.confidence, placementReason: p.reason,
                                    placementScore: String(p.score), placementFlip: String(p.flip === -1),
                                    placementRotation: String(p.rotation_deg), placementPerspective: String(p.perspective_deg),
                                });
                            }
                        }
                        if (payload.analysis) document.querySelectorAll('[data-preview-analysis]').forEach((node) => { node.textContent = JSON.stringify(payload.analysis); });
                        document.querySelectorAll('[data-room-preview]').forEach((frame) => {
                            frame.dataset.placementStatus = payload.status;
                            const previous = frame.roomPreviewEngine;
                            if (previous) previous.runId++;
                            if (previous?.canvas) previous.canvas.width = 0;
                            const engine = window.RoomPreviewEngine ? new window.RoomPreviewEngine(frame) : null;
                            if (engine) { frame.roomPreviewEngine = engine; engine.start(); }
                        });
                    }
                } catch (error) {
                    // Keep the current state; the next poll can recover from a transient network error.
                }
            }
        };
        startBoundedPoll(poll, () => analysisWatches.some(element => !element.dataset.pollingStopped && activeAnalysisStatuses.includes(element.dataset.roomAnalysisCurrent || (element.dataset.roomReady === 'true' ? 'completed' : 'queued'))));
    }
    };
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-room-reanalyze]');
        if (!form) return;

        event.preventDefault();
        const option = form.closest('[data-room-analysis-watch]');
        const button = form.querySelector('[data-room-retry]');
        const message = option?.querySelector('[data-room-analysis-message]');
        if (!option || !button || button.disabled) return;

        button.disabled = true;
        button.dataset.originalLabel ??= button.textContent.trim();
        button.textContent = 'Requesting…';
        if (message) message.textContent = 'Sending the re-analysis request…';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                signal: AbortSignal.timeout(20000),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'The room could not be re-analyzed. Please try again.');

            const status = payload.status || 'queued';
            const labels = { queued: 'Room analysis queued', processing: 'Analyzing room image', retrying: 'AI temporarily unavailable · retrying', completed: 'AI analyzed', fallback: 'Local guidance', failed: 'Needs retry' };
            // Fetch the authoritative status once more even if a queue worker finished
            // before the POST returned, so the room preview can update without a reload.
            option.dataset.roomAnalysisCurrent = activeAnalysisStatuses.includes(status) ? status : 'queued';
            option.dataset.roomReady = String(['completed', 'fallback'].includes(status));
            delete option.dataset.pollingStopped;
            option.querySelector('[data-room-analysis-label]')?.replaceChildren(document.createTextNode(labels[status] || status));
            if (message) message.textContent = payload.message || (activeAnalysisStatuses.includes(status) ? 'Room analysis is in progress.' : 'Room analysis status updated.');
            button.disabled = true;
            button.textContent = button.dataset.originalLabel;

            watchRoomAnalysis([option]);
        } catch (error) {
            button.disabled = false;
            button.textContent = button.dataset.originalLabel;
            if (message) message.textContent = error.message || 'Could not send the re-analysis request. Please try again.';
        }
    });
    watchRoomAnalysis([...document.querySelectorAll('[data-room-analysis-watch]')]);
    const previewWatches = [...document.querySelectorAll('[data-preview-watch]')];
    const activePreviewStatuses = ['queued', 'processing', 'retrying'];
    if (previewWatches.some((element) => activePreviewStatuses.includes(element.dataset.previewStatus))) {
        const pollPreviews = async () => {
            await Promise.all(previewWatches.map(async (element) => {
                if (element.dataset.pollingStopped || !activePreviewStatuses.includes(element.dataset.previewStatus)) return;
                try {
                    const response = await fetch(element.dataset.previewWatch, { signal: AbortSignal.timeout(10000), headers: { Accept: 'application/json' } });
                    if (!response.ok) { if ([401, 403, 404, 429].includes(response.status)) element.dataset.pollingStopped = 'true'; return; }
                    const payload = await response.json();
                    element.dataset.previewStatus = payload.status;
                    const message = element.querySelector('[data-preview-message]');
                    const label = element.querySelector('[data-preview-label]');
                    if (message && payload.message) message.textContent = payload.message;
                    if (label && ['failed', 'fallback', 'cancelled'].includes(payload.status)) label.textContent = 'Showing local 2D placement preview';
                    if (payload.status === 'completed' && message) message.textContent = payload.message || 'The final automatic preview is ready.';
                } catch (error) {
                    // Keep polling after transient network errors.
                }
            }));
        };
        startBoundedPoll(pollPreviews, () => previewWatches.some(element => !element.dataset.pollingStopped && activePreviewStatuses.includes(element.dataset.previewStatus)));
    }

    const recommendationChat = document.querySelector('[data-chat-widget]');
    if (recommendationChat) {
        const panel = recommendationChat.querySelector('[data-chat-panel]');
        const toggle = recommendationChat.querySelector('[data-chat-toggle]');
        const close = recommendationChat.querySelector('[data-chat-close]');
        const log = recommendationChat.querySelector('[data-chat-log]');
        const form = recommendationChat.querySelector('[data-chat-form]');
        const storageKey = recommendationChat.dataset.chatStorageKey;

        const setChatOpen = (open, focusPanel = true) => {
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', String(open));
            if (open) {
                if (log) log.scrollTop = log.scrollHeight;
                if (focusPanel) close.focus({ preventScroll: true });
            } else if (focusPanel) {
                toggle.focus({ preventScroll: true });
            }
        };

        toggle.addEventListener('click', () => setChatOpen(panel.hidden));
        close.addEventListener('click', () => setChatOpen(false));
        recommendationChat.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden) {
                event.preventDefault();
                setChatOpen(false);
            }
        });
        form.addEventListener('submit', () => {
            try { sessionStorage.setItem(storageKey, 'open'); } catch (error) { /* Chat still submits if storage is unavailable. */ }
        });

        try {
            if (sessionStorage.getItem(storageKey) === 'open') {
                sessionStorage.removeItem(storageKey);
                setChatOpen(true, false);
            }
        } catch (error) { /* Chat remains available when storage is unavailable. */ }
    }

    document.querySelectorAll('[data-customization-dialog]').forEach((dialog) => {
        const reservationForm = dialog.closest('form');
        const trigger = reservationForm?.querySelector('[data-customization-open]');
        const close = dialog.querySelector('[data-customization-close]');
        if (!trigger || !close) return;

        trigger.addEventListener('click', () => dialog.showModal());
        close.addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
    });

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }
});
