/* Storefront has no third-party browser dependencies. */
(function () {
    'use strict';
    var config = window.VTO_CONFIG;
    if (!config) { return; }
    document.querySelectorAll('[data-vto-app]').forEach(function (app) {
        var form = app.querySelector('[data-vto-form]');
        if (!form) { return; }
        var labels = config.labels;
        var fileInput = form.querySelector('input[type="file"]');
        var preview = app.querySelector('[data-vto-preview]');
        var placeholder = app.querySelector('[data-vto-placeholder]');
        var photoActions = app.querySelector('[data-vto-photo-actions]');
        var submit = app.querySelector('[data-vto-submit]');
        var feedback = app.querySelector('[data-vto-feedback]');
        var jobPanel = app.querySelector('[data-vto-job]');
        var jobTitle = app.querySelector('[data-vto-job-title]');
        var jobMessage = app.querySelector('[data-vto-job-message]');
        var spinner = app.querySelector('[data-vto-spinner]');
        var result = app.querySelector('[data-vto-result]');
        var download = app.querySelector('[data-vto-download]');
        var expiry = app.querySelector('[data-vto-expiry]');
        var tracking = app.querySelector('[data-vto-tracking]');
        var checkButton = app.querySelector('[data-vto-check]');
        var currentFile = null;
        var objectUrl = null;
        var token = null;
        var timer = null;
        var failures = 0;
        var generation = 0;
        var sending = false;

        function showError(message, focus) {
            feedback.textContent = message;
            feedback.hidden = !message;
            if (message && focus) { feedback.focus(); }
        }

        function step(number) {
            app.querySelectorAll('[data-vto-step]').forEach(function (item) {
                var value = Number(item.dataset.vtoStep);
                item.classList.toggle('is-current', value === number);
                item.classList.toggle('is-done', value < number);
                if (value === number) { item.setAttribute('aria-current', 'step'); }
                else { item.removeAttribute('aria-current'); }
            });
        }

        function updateStep() {
            step(currentFile ? 2 : 1);
        }

        function clearPhoto() {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); }
            objectUrl = null;
            currentFile = null;
            fileInput.value = '';
            preview.removeAttribute('src');
            preview.hidden = true;
            placeholder.hidden = false;
            photoActions.hidden = true;
            updateStep();
        }

        function chooseFile(file) {
            if (!file) { return; }
            showError('');
            if (['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) {
                clearPhoto();
                showError(labels.error_file_type, true);
                return;
            }
            if (file.size > config.maxUploadMb * 1024 * 1024 || file.size === 0) {
                clearPhoto();
                showError(labels.error_file_size, true);
                return;
            }
            if (objectUrl) { URL.revokeObjectURL(objectUrl); }
            currentFile = file;
            objectUrl = URL.createObjectURL(file);
            preview.onload = function () {
                preview.hidden = false;
                placeholder.hidden = true;
                photoActions.hidden = false;
                updateStep();
            };
            preview.onerror = function () {
                clearPhoto();
                showError(labels.error_file_read, true);
            };
            preview.src = objectUrl;
        }

        function safeUrl(value, sameOrigin) {
            try {
                var parsed = new URL(value, window.location.href);
                if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') { return null; }
                if (sameOrigin && parsed.origin !== window.location.origin) { return null; }
                return parsed;
            } catch (error) { return null; }
        }

        function trackingUrl(jobToken) {
            var url = new URL(window.location.href);
            url.searchParams.set('vto_job', jobToken);
            url.hash = '';
            return url.href;
        }

        function saveTracking(jobToken) {
            tracking.value = trackingUrl(jobToken);
            try { window.history.replaceState(null, '', tracking.value); } catch (error) { /* Form still works with the visible link. */ }
        }

        async function api(path, options) {
            var opts = Object.assign({ credentials: 'same-origin', cache: 'no-store', referrerPolicy: 'no-referrer' }, options || {});
            var response;
            try { response = await fetch(config.restUrl + path, opts); }
            catch (error) { throw { message: labels.error_network, network: true }; }
            var body;
            try { body = await response.json(); }
            catch (error) { throw { message: labels.error_unknown, network: response.status >= 500 }; }
            if (!response.ok) {
                throw { message: body.message || labels.error_unknown, code: body.code || '', status: response.status, network: response.status >= 500 };
            }
            return body;
        }

        async function freshNonce() {
            // admin-ajax resolves current login cookies without requiring an
            // existing REST nonce, including when page HTML came from a cache.
            var response;
            try {
                response = await fetch(config.ajaxUrl, {
                    credentials: 'same-origin', cache: 'no-store', referrerPolicy: 'no-referrer'
                });
            } catch (error) { throw { message: labels.error_network, network: true }; }
            var session;
            try { session = await response.json(); }
            catch (error) { throw { message: labels.error_unknown }; }
            if (!response.ok || !session || typeof session.nonce !== 'string' || !session.nonce) {
                throw { message: labels.error_unknown };
            }
            return session.nonce;
        }

        function openJob(jobToken) {
            if (!/^[a-zA-Z0-9_-]{20,128}$/.test(jobToken || '')) {
                showError(labels.error_expired, true);
                return;
            }
            generation += 1;
            token = jobToken;
            failures = 0;
            form.hidden = true;
            jobPanel.hidden = false;
            result.hidden = true;
            download.hidden = true;
            expiry.hidden = true;
            spinner.hidden = false;
            checkButton.hidden = true;
            app.querySelector('[data-vto-result-note]').hidden = true;
            jobTitle.textContent = labels.waiting_label;
            jobMessage.textContent = labels.queued_message;
            jobPanel.setAttribute('aria-busy', 'true');
            step(3);
            saveTracking(jobToken);
            poll(generation);
        }

        function schedule(myGeneration, delay) {
            clearTimeout(timer);
            timer = setTimeout(function () { poll(myGeneration); }, delay);
        }

        async function poll(myGeneration) {
            if (!token || myGeneration !== generation) { return; }
            checkButton.hidden = true;
            try {
                var data = await api('jobs/' + encodeURIComponent(token));
                if (myGeneration !== generation) { return; }
                failures = 0;
                if (data.status === 'completed') {
                    spinner.hidden = true;
                    jobPanel.setAttribute('aria-busy', 'false');
                    jobTitle.textContent = labels.result_label;
                    jobMessage.textContent = data.mode === 'demo' ? (data.message || labels.demo_notice) : config.successMessage;
                    var imageUrl = safeUrl(data.image_url, true);
                    if (!imageUrl) {
                        jobMessage.textContent = labels.error_unknown;
                        return;
                    }
                    result.src = imageUrl.href;
                    result.hidden = false;
                    imageUrl.searchParams.set('download', '1');
                    download.href = imageUrl.href;
                    download.hidden = false;
                    app.querySelector('[data-vto-result-note]').hidden = false;
                    if (data.expires_at) {
                        var date = new Date(data.expires_at);
                        if (!Number.isNaN(date.getTime())) {
                            expiry.textContent = labels.expires_label + ' ' + date.toLocaleString('fa-IR');
                            expiry.hidden = false;
                        }
                    }
                    return;
                }
                if (data.status === 'failed') {
                    spinner.hidden = true;
                    jobPanel.setAttribute('aria-busy', 'false');
                    jobTitle.textContent = labels.failed_label;
                    jobMessage.textContent = data.message || labels.error_unknown;
                    return;
                }
                jobMessage.textContent = data.message || (data.status === 'processing' ? labels.processing_message : labels.queued_message);
                schedule(myGeneration, document.hidden ? 15000 : 4000);
            } catch (error) {
                if (myGeneration !== generation) { return; }
                failures += 1;
                if (error.status === 404 || error.status === 410) {
                    spinner.hidden = true;
                    jobPanel.setAttribute('aria-busy', 'false');
                    jobTitle.textContent = labels.failed_label;
                    jobMessage.textContent = labels.error_expired;
                    return;
                }
                jobMessage.textContent = error.network ? labels.connection_message : error.message;
                if (failures < 8) { schedule(myGeneration, Math.min(30000, 5000 * failures)); }
                else {
                    spinner.hidden = true;
                    jobPanel.setAttribute('aria-busy', 'false');
                    checkButton.hidden = false;
                }
            }
        }

        fileInput.addEventListener('change', function () { chooseFile(fileInput.files[0]); });
        form.querySelectorAll('input[name="product_id"]').forEach(function (radio) {
            radio.addEventListener('change', updateStep);
        });
        app.querySelector('[data-vto-remove]').addEventListener('click', clearPhoto);
        var dropzone = app.querySelector('[data-vto-dropzone]');
        ['dragenter', 'dragover'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) { event.preventDefault(); dropzone.classList.add('is-dragging'); });
        });
        ['dragleave', 'drop'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) { event.preventDefault(); dropzone.classList.remove('is-dragging'); });
        });
        dropzone.addEventListener('drop', function (event) {
            if (!sending && event.dataTransfer && event.dataTransfer.files.length) { chooseFile(event.dataTransfer.files[0]); }
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (sending) { return; }
            showError('');
            var product = form.querySelector('input[name="product_id"]:checked');
            if (!product) { showError(labels.error_product, true); return; }
            if (!currentFile) { showError(labels.error_photo, true); return; }
            if (!form.querySelector('input[name="consent"]').checked) { showError(labels.error_consent, true); return; }
            sending = true;
            submit.disabled = true;
            submit.querySelector('span').textContent = labels.submitting_message;
            form.setAttribute('aria-busy', 'true');
            try {
                var nonce = await freshNonce();
                var data = new FormData();
                data.append('product_id', product.value);
                data.append('image', currentFile, currentFile.name);
                data.append('consent', '1');
                data.append('nonce', nonce);
                var page = new URL(window.location.href);
                page.searchParams.delete('vto_job');
                page.hash = '';
                data.append('page_url', page.href);
                var job = await api('jobs', { method: 'POST', headers: { 'X-WP-Nonce': nonce }, body: data });
                openJob(job.token);
                clearPhoto();
                step(3);
            } catch (error) {
                showError(error.message || labels.error_unknown, true);
            } finally {
                sending = false;
                submit.disabled = false;
                submit.querySelector('span').textContent = config.buttonLabel;
                form.setAttribute('aria-busy', 'false');
            }
        });

        app.querySelector('[data-vto-new]').addEventListener('click', function () {
            generation += 1;
            clearTimeout(timer);
            token = null;
            result.removeAttribute('src');
            result.hidden = true;
            jobPanel.hidden = true;
            form.hidden = false;
            form.querySelector('input[name="consent"]').checked = false;
            showError('');
            var url = new URL(window.location.href);
            url.searchParams.delete('vto_job');
            try { window.history.replaceState(null, '', url.href); } catch (error) { /* No navigation needed. */ }
            updateStep();
            app.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
        });
        checkButton.addEventListener('click', function () {
            failures = 0;
            spinner.hidden = false;
            jobPanel.setAttribute('aria-busy', 'true');
            poll(generation);
        });
        app.querySelector('[data-vto-copy]').addEventListener('click', async function () {
            var button = this;
            try {
                await navigator.clipboard.writeText(tracking.value);
                button.textContent = labels.copied;
                setTimeout(function () { button.textContent = labels.copy_link; }, 2500);
            } catch (error) { tracking.focus(); tracking.select(); }
        });
        result.addEventListener('error', function () {
            result.hidden = true;
            download.hidden = true;
            jobMessage.textContent = labels.error_network;
            checkButton.hidden = false;
        });
        window.addEventListener('pagehide', function () {
            clearTimeout(timer);
            if (objectUrl) { URL.revokeObjectURL(objectUrl); }
        });
        var incomingToken = new URL(window.location.href).searchParams.get('vto_job');
        if (incomingToken) { openJob(incomingToken); }
    });
}());
