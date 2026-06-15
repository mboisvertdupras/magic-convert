(function () {
    'use strict';

    function restRoot() {
        return (window.magicConvert && window.magicConvert.rest && window.magicConvert.rest.root) || '';
    }
    function restNonce() {
        return (window.magicConvert && window.magicConvert.rest && window.magicConvert.rest.nonce) || '';
    }
    function setRestNonce(n) {
        if (n && window.magicConvert && window.magicConvert.rest) {
            window.magicConvert.rest.nonce = n;
        }
    }

    var ABSOLUTE_MAX_WORKERS = 8;
    var PER_REQUEST_TIMEOUT_MS = 120000;
    var AVIF_PER_REQUEST_TIMEOUT_MS = 300000;
    var LIST_PAGE_SIZE = 500;
    var BACKOFF_BASE_MS = 500;
    var BACKOFF_MAX_MS = 15000;
    var BACKOFF_DEBOUNCE_MS = 1500;
    var PAGE_FETCH_RETRY_MS = 3000;
    var PAGE_FETCH_MAX_RETRIES = 5;
    var GROW_AFTER_SUCCESSES = 4;
    var GROW_COOLDOWN_MS = 4000;
    var LATENCY_MIN_SAMPLES = 4;
    var LATENCY_BASE_LEAK = 0.002;
    var LATENCY_EWMA_ALPHA = 0.3;
    var LATENCY_GROW_MAX_RATIO = 1.5;
    var LATENCY_SHRINK_RATIO = 2.0;
    var FAIL_WINDOW = 10;
    var FAIL_WINDOW_TRIP = 8;
    var OVERRIDE_STORAGE_KEY = 'magicconvert_parallel_override';

    var DEFAULT_FORMAT = 'webp';

    function formatLabel(id) {
        if (id === 'webp') { return 'WebP'; }
        if (id === 'avif') { return 'AVIF'; }
        return String(id).toUpperCase();
    }

    function timeoutForFormat(format) {
        return (format === 'avif') ? AVIF_PER_REQUEST_TIMEOUT_MS : PER_REQUEST_TIMEOUT_MS;
    }

    function expandToUnits(file) {
        var units = [];
        var formats = (file && file.formats && file.formats.length) ? file.formats : [DEFAULT_FORMAT];
        for (var i = 0; i < formats.length; i++) {
            units.push({ root: file.root, path: file.path, format: formats[i] });
        }
        return units;
    }

    function expandListToUnits(files) {
        var units = [];
        for (var i = 0; i < (files || []).length; i++) {
            units = units.concat(expandToUnits(files[i]));
        }
        return units;
    }

    function unitKey(unit) {
        return JSON.stringify([unit.format, unit.path]);
    }

    var run = null;

    function getOverride() {
        try {
            var v = window.localStorage.getItem(OVERRIDE_STORAGE_KEY);
            if (v === null) {
                return 'auto';
            }
            return v;
        } catch (e) {
            return 'auto';
        }
    }
    function setOverride(v) {
        try {
            window.localStorage.setItem(OVERRIDE_STORAGE_KEY, v);
        } catch (e) {}
    }
    function growthCeiling() {
        var ov = getOverride();
        if (ov !== 'auto') {
            var n = parseInt(ov, 10);
            if (!isNaN(n) && n >= 1) {
                return Math.max(1, Math.min(n, ABSOLUTE_MAX_WORKERS));
            }
        }
        return (run && run.target) ? run.target : ABSOLUTE_MAX_WORKERS;
    }

    function computeTarget(conc, formats, recommended, hardMax) {
        var targets = conc.targets || {};
        var target;
        var allPresent = true;
        var min = Infinity;
        for (var i = 0; i < formats.length; i++) {
            var tv = targets[formats[i]];
            if (typeof tv === 'number' && tv >= 1) {
                min = Math.min(min, tv);
            } else {
                allPresent = false;
            }
        }
        if (allPresent && isFinite(min)) {
            target = min;
        } else if (typeof recommended === 'number' && recommended >= 1) {
            target = recommended;
        } else {
            target = hardMax;
        }
        var ov = getOverride();
        if (ov !== 'auto') {
            var n = parseInt(ov, 10);
            if (!isNaN(n) && n >= 1) { target = n; }
        }
        return Math.max(1, Math.min(target, hardMax));
    }

    function el(id) { return document.getElementById(id); }

    function setContent(html) {
        var c = el('bulkconvertcontent');
        if (c) { c.innerHTML = html; }
    }

    function humanBytes(b) {
        if (b < 1024) { return b + ' B'; }
        if (b < 1024 * 1024) { return Math.round(b / 1024) + ' KB'; }
        return (Math.round(b / (1024 * 1024) * 10) / 10) + ' MB';
    }

    function reductionPct(org, conv) {
        if (!org) { return 0; }
        return Math.round((org - conv) / org * 100);
    }

    window.openBulkConvertPopup = function () {
        if (el('bulkconvertlog')) { el('bulkconvertlog').innerHTML = ''; }
        setContent('<div>Preparing list of files to convert...</div>');
        if (typeof tb_show === 'function') {
            tb_show('Bulk Convert', '#TB_inline?inlineId=bulkconvertpopup');
        }

        fetchFirstPage().then(function (firstResponse) {
            if (!firstResponse || firstResponse.success !== true) {
                var msg = (firstResponse && firstResponse.msg) ? firstResponse.msg : 'unknown error';
                setContent('<h1>Error</h1><p>' + magicconvert_escapeHTML(String(msg)) + '</p>');
                return;
            }
            renderStartScreen(firstResponse);
        }).catch(function (err) {
            setContent('<h1>Error</h1><p>Could not fetch the list of files to convert.</p>' +
                '<p>' + magicconvert_escapeHTML(String(err && err.message ? err.message : err)) + '</p>');
        });
    };

    function renderStartScreen(firstResponse) {
        var total = firstResponse.total || 0;
        var recommended = (firstResponse.concurrency && firstResponse.concurrency.recommended) || 2;
        var serverMax = (firstResponse.concurrency && firstResponse.concurrency.max) || ABSOLUTE_MAX_WORKERS;

        if (total === 0) {
            setContent('<p>There are no unconverted files.</p>');
            return;
        }

        var formats = (firstResponse.formats && firstResponse.formats.length) ? firstResponse.formats : [DEFAULT_FORMAT];
        var formatTotals = firstResponse.format_totals || {};
        var avifEnabled = (formats.indexOf('avif') !== -1);

        var html = '';
        html += '<div>';
        html += '<p>There are <b>' + total + '</b> unconverted files.</p>';

        if (formats.length > 1) {
            var parts = [];
            for (var f = 0; f < formats.length; f++) {
                var fid = formats[f];
                var cnt = (typeof formatTotals[fid] === 'number') ? formatTotals[fid] : total;
                parts.push('<b>' + cnt.toLocaleString() + '</b> ' + magicconvert_escapeHTML(formatLabel(fid)));
            }
            html += '<p>To convert: ' + parts.join(' &middot; ') + '.</p>';
        }

        html += '<p><i>Note: in a typical setup, redirect rules trigger conversion on demand, so you do not need bulk ' +
                'conversion. Bulk conversion also converts thumbnails that may never be used, taking extra disk space.</i></p>';

        if (avifEnabled) {
            html += '<p style="color:#b26500;"><b>AVIF encoding is slower</b> — large libraries may take a while. ' +
                    'The WP-CLI command is faster for very large sites.</p>';
        }

        html += '<p><button id="bulkStartBtn" class="button button-primary" type="button">Start conversion</button></p>';

        html += '<details style="margin-top:12px;">';
        html += '<summary style="cursor:pointer; color:#666;">Advanced</summary>';
        html += '<p style="margin-top:8px;"><label>Parallel conversions: ';
        html += '<select id="bulkParallelOverride">';
        var ov = getOverride();
        html += '<option value="auto"' + (ov === 'auto' ? ' selected' : '') + '>Automatic (recommended)</option>';
        for (var i = 1; i <= ABSOLUTE_MAX_WORKERS; i++) {
            html += '<option value="' + i + '"' + (ov === String(i) ? ' selected' : '') + '>' + i + '</option>';
        }
        html += '</select></label></p>';
        html += '<p style="color:#888; font-size:12px;">Automatic detects your server resources and adapts. ' +
                'Choosing a number sets a hard cap (the system still slows down on errors, but never exceeds it).</p>';
        html += '</details>';
        html += '</div>';

        setContent(html);

        el('bulkParallelOverride').addEventListener('change', function () {
            setOverride(this.value);
        });
        el('bulkStartBtn').addEventListener('click', function () {
            startRun(firstResponse, recommended, serverMax);
        });
    }

    function fetchWithTimeout(url, options, timeoutMs) {
        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        if (controller) { options.signal = controller.signal; }
        var timer = setTimeout(function () {
            if (controller) { controller.abort(); }
        }, timeoutMs);

        return fetch(url, options).then(function (resp) {
            clearTimeout(timer);
            return resp;
        }, function (err) {
            clearTimeout(timer);
            throw err;
        });
    }

    function fetchFirstPage() {
        var url = restRoot() + '/unconverted?per_page=' + LIST_PAGE_SIZE;
        return fetchWithTimeout(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': restNonce() }
        }, 30000).then(function (resp) {
            return resp.json();
        }).then(function (data) {
            if (data && data.nonce) { setRestNonce(data.nonce); }
            return data;
        });
    }

    function fetchPage(listId, page) {
        var url = restRoot() + '/unconverted?list_id=' + encodeURIComponent(listId) +
                  '&page=' + page + '&per_page=' + LIST_PAGE_SIZE;
        return fetchWithTimeout(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': restNonce() }
        }, 30000).then(function (resp) {
            return resp.json();
        }).then(function (data) {
            if (data && data.nonce) { setRestNonce(data.nonce); }
            return data;
        });
    }

    function convertOne(unit, reconvert) {
        var url = restRoot() + '/convert';
        var body = JSON.stringify({
            root: unit.root,
            path: unit.path,
            format: unit.format || DEFAULT_FORMAT,
            reconvert: !!reconvert
        });

        return fetchWithTimeout(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': restNonce()
            },
            body: body
        }, timeoutForFormat(unit.format)).then(function (resp) {
            var retriable = (resp.status === 429 || resp.status >= 500);
            var retryAfterMs = parseRetryAfter(resp);
            return resp.json().then(function (data) {
                if (data && data.nonce) { setRestNonce(data.nonce); }
                return {
                    ok: resp.ok && data && data.success === true,
                    retriable: retriable,
                    httpStatus: resp.status,
                    retryAfterMs: retryAfterMs,
                    result: data || {}
                };
            }, function () {
                return { ok: false, retriable: retriable, httpStatus: resp.status, retryAfterMs: retryAfterMs, result: {} };
            });
        }, function () {
            return { ok: false, retriable: true, httpStatus: 0, retryAfterMs: 0, result: {} };
        });
    }

    function parseRetryAfter(resp) {
        try {
            if (!resp || !resp.headers || typeof resp.headers.get !== 'function') { return 0; }
            var raw = resp.headers.get('Retry-After');
            if (!raw) { return 0; }
            var secs = parseInt(raw, 10);
            if (isNaN(secs) || secs < 0) { return 0; }
            return secs * 1000;
        } catch (e) {
            return 0;
        }
    }

    function startRun(firstResponse, recommended, serverMax) {
        if (run) { run.stopped = true; }
        refillScheduled = false;

        var formats = (firstResponse.formats && firstResponse.formats.length) ? firstResponse.formats : [DEFAULT_FORMAT];
        var formatTotals = firstResponse.format_totals || {};

        var totalUnits = 0;
        var perFormatTotal = {};
        for (var i = 0; i < formats.length; i++) {
            var fid = formats[i];
            var cnt = (typeof formatTotals[fid] === 'number') ? formatTotals[fid] : firstResponse.total;
            perFormatTotal[fid] = cnt;
            totalUnits += cnt;
        }
        if (totalUnits === 0) { totalUnits = firstResponse.total; }

        var perFormatDone = {};
        for (var j = 0; j < formats.length; j++) { perFormatDone[formats[j]] = 0; }

        var conc = firstResponse.concurrency || {};
        var hardMax = Math.max(1, Math.min(ABSOLUTE_MAX_WORKERS, conc.max || ABSOLUTE_MAX_WORKERS));
        var target = computeTarget(conc, formats, recommended, hardMax);

        run = {
            listId: firstResponse.list_id,
            total: totalUnits,
            fileTotal: firstResponse.total,
            formats: formats,
            perFormatTotal: perFormatTotal,
            perFormatDone: perFormatDone,
            queue: expandListToUnits(firstResponse.files),
            nextPage: 2,
            pagesExhausted: (firstResponse.files || []).length >= firstResponse.total,
            pageFetchInFlight: false,
            pageFetchFailures: 0,

            converted: 0,
            skipped: 0,
            failed: 0,
            processed: 0,
            orgBytes: 0,
            convBytes: 0,
            errors: [],
            retryCounts: {},

            target: target,
            floor: 1,
            desired: Math.min(2, target),
            activeWorkers: 0,
            successCount: 0,
            consecutiveFailWindows: 0,
            lastDecrementAt: 0,
            lastShrinkAt: 0,
            latBase: 0,
            latEwma: 0,
            latSamples: 0,
            recentOk: [],
            paused: false,
            stopped: false,
            backoffUntil: 0,
            startedAt: Date.now()
        };

        renderRunningUi();
        fillPool();
    }

    function renderRunningUi() {
        var html = '';
        html += '<p>';
        html += '<button id="bulkPauseResumeBtn" class="button button-primary" type="button">Pause</button> ';
        html += '<span id="bulkStatusLine" style="margin-left:10px; color:#666;"></span>';
        html += '</p>';
        html += '<div id="bulkProgressBarOuter" style="background:#eee; height:18px; border-radius:3px; overflow:hidden; max-width:480px;">';
        html += '<div id="bulkProgressBarInner" style="background:#46b450; height:18px; width:0;"></div>';
        html += '</div>';
        html += '<p id="bulkPerFormatText" style="margin-top:6px; color:#444;"></p>';
        html += '<p id="bulkProgressText" style="margin-top:6px;"></p>';
        html += '<div id="bulkErrorsPanel" style="margin-top:10px;"></div>';
        setContent(html);

        el('bulkPauseResumeBtn').addEventListener('click', togglePause);
        updateProgressUi();
    }

    function togglePause() {
        if (!run) { return; }
        run.paused = !run.paused;
        var btn = el('bulkPauseResumeBtn');
        if (btn) { btn.innerText = run.paused ? 'Resume' : 'Pause'; }
        if (!run.paused) {
            fillPool();
        }
        updateProgressUi();
    }

    function updateProgressUi() {
        if (!run || run.finished) { return; }
        var pct = run.total ? Math.round(run.processed / run.total * 100) : 0;
        var inner = el('bulkProgressBarInner');
        if (inner) { inner.style.width = pct + '%'; }

        var status = el('bulkStatusLine');
        if (status) {
            if (run.paused) {
                status.innerText = 'Paused';
            } else if (Date.now() < run.backoffUntil) {
                status.innerText = 'Server busy — backing off…';
            } else {
                status.innerText = 'Converting with ' + run.activeWorkers + ' parallel worker' +
                    (run.activeWorkers === 1 ? '' : 's') + ' (automatic)';
            }
        }

        var perFmt = el('bulkPerFormatText');
        if (perFmt && run.formats && run.formats.length > 1) {
            var segs = [];
            for (var i = 0; i < run.formats.length; i++) {
                var fid = run.formats[i];
                var done = run.perFormatDone[fid] || 0;
                var tot = run.perFormatTotal[fid] || 0;
                segs.push(magicconvert_escapeHTML(formatLabel(fid)) + ': ' +
                    done.toLocaleString() + '/' + tot.toLocaleString());
            }
            perFmt.innerHTML = segs.join(' &middot; ');
        }

        var txt = el('bulkProgressText');
        if (txt) {
            var saved = run.orgBytes - run.convBytes;
            txt.innerHTML =
                run.processed + ' / ' + run.total + ' processed' +
                ' &middot; <span style="color:#46b450;">' + run.converted + ' converted</span>' +
                (run.skipped ? ' &middot; ' + run.skipped + ' skipped' : '') +
                (run.failed ? ' &middot; <span style="color:#dc3232;">' + run.failed + ' failed</span>' : '') +
                ' &middot; ' + humanBytes(saved > 0 ? saved : 0) + ' saved';
        }

        renderErrors();
    }

    function renderErrors() {
        var panel = el('bulkErrorsPanel');
        if (!panel) { return; }
        if (!run.errors.length) { panel.innerHTML = ''; return; }
        var html = '<details open><summary style="cursor:pointer; color:#dc3232;">' +
            run.errors.length + ' file' + (run.errors.length === 1 ? '' : 's') + ' failed</summary>';
        html += '<ul style="max-height:180px; overflow:auto; font-size:12px;">';
        for (var i = 0; i < run.errors.length; i++) {
            var e = run.errors[i];
            var fmtTag = e.format ? ('<b>[' + magicconvert_escapeHTML(formatLabel(e.format)) + ']</b> ') : '';
            html += '<li>' + fmtTag + '<code>' + magicconvert_escapeHTML(String(e.path)) + '</code>: ' +
                magicconvert_escapeHTML(String(e.msg || 'unknown error')) + '</li>';
        }
        html += '</ul></details>';
        panel.innerHTML = html;
    }

    function maybeFetchMorePages() {
        if (!run || run.pagesExhausted || run.pageFetchInFlight) { return; }
        if (run.queue.length > LIST_PAGE_SIZE / 2) { return; }
        run.pageFetchInFlight = true;
        var page = run.nextPage;
        fetchPage(run.listId, page).then(function (data) {
            run.pageFetchInFlight = false;
            if (data && data.success && data.files && data.files.length) {
                run.pageFetchFailures = 0;
                run.queue = run.queue.concat(expandListToUnits(data.files));
                run.nextPage = page + 1;
                if ((page) * LIST_PAGE_SIZE >= run.fileTotal) {
                    run.pagesExhausted = true;
                }
                fillPool();
            } else {
                run.pagesExhausted = true;
                fillPool();
            }
        }).catch(function () {
            run.pageFetchInFlight = false;
            run.pageFetchFailures++;
            if (run.pageFetchFailures >= PAGE_FETCH_MAX_RETRIES) {
                run.pagesExhausted = true;
                run.errors.push({
                    path: '(more files)',
                    format: '',
                    msg: 'Could not fetch more files to convert after ' +
                         run.pageFetchFailures + ' attempts (network error).'
                });
                fillPool();
                return;
            }
            var scheduledRun = run;
            setTimeout(function () {
                if (run === scheduledRun && !run.stopped) { fillPool(); }
            }, PAGE_FETCH_RETRY_MS);
        });
    }

    function fillPool() {
        if (!run || run.stopped || run.paused) { return; }

        maybeFetchMorePages();

        var target = Math.min(run.desired, growthCeiling());

        while (run.activeWorkers < target && run.queue.length > 0 && Date.now() >= run.backoffUntil) {
            spawnWorker();
        }

        if (run.activeWorkers <= 0) {
            if (run.queue.length === 0 && run.pagesExhausted && !run.pageFetchInFlight) {
                finishRun();
            } else if (run.queue.length > 0 && Date.now() < run.backoffUntil) {
                scheduleRefill();
            }
        }

        updateProgressUi();
    }

    function spawnWorker() {
        run.activeWorkers++;
        workerLoop();
    }

    function workerLoop() {
        if (!run || run.stopped) { run.activeWorkers--; return; }
        if (run.paused || Date.now() < run.backoffUntil) {
            run.activeWorkers--;
            scheduleRefill();
            return;
        }
        var unit = run.queue.shift();
        if (!unit) {
            run.activeWorkers--;
            fillPool();
            return;
        }

        var key = unitKey(unit);
        var attempts = run.retryCounts[key] || 0;
        var thisRun = run;
        var startedAt = Date.now();

        convertOne(unit, false).then(function (outcome) {
            handleOutcome(unit, attempts, outcome, startedAt, thisRun);
        });
    }

    function markUnitDone(unit) {
        run.processed++;
        var fmt = unit.format || DEFAULT_FORMAT;
        if (typeof run.perFormatDone[fmt] === 'number') {
            run.perFormatDone[fmt]++;
        }
    }

    function handleOutcome(unit, attempts, outcome, startedAt, thisRun) {
        if (!run || run !== thisRun) { return; }

        var data = outcome.result || {};
        var status = data.status;
        var realFailure = outcome.retriable || outcome.httpStatus === 0;

        if (realFailure) { applyFailureBackoff(outcome); }

        if (outcome.ok) {
            recordOutcome(true);
            if (status === 'already-converted') {
                run.skipped++;
            } else {
                run.converted++;
                if (typeof data['filesize-original'] === 'number') { run.orgBytes += data['filesize-original']; }
                if (typeof data['filesize-webp'] === 'number') { run.convBytes += data['filesize-webp']; }
                if (status === 'converted') { onLatencySample(Date.now() - startedAt); }
            }
            markUnitDone(unit);
            registerSuccess();
        } else if (status === 'in-progress') {
            recordOutcome(true);
            run.skipped++;
            markUnitDone(unit);
        } else if (realFailure && attempts < retryCapFor(outcome)) {
            recordOutcome(false);
            run.retryCounts[unitKey(unit)] = attempts + 1;
            run.queue.push(unit);
        } else if (!realFailure && attempts < 1 && outcome.httpStatus !== 400) {
            recordOutcome(false);
            run.retryCounts[unitKey(unit)] = attempts + 1;
            run.queue.push(unit);
        } else {
            recordOutcome(false);
            run.failed++;
            markUnitDone(unit);
            run.errors.push({
                path: unit.root + '/' + unit.path,
                format: unit.format || DEFAULT_FORMAT,
                msg: data.msg || ('HTTP ' + outcome.httpStatus)
            });
        }

        run.activeWorkers--;
        if (run.activeWorkers < 0) { run.activeWorkers = 0; }
        driveNext();
        updateProgressUi();
    }

    function retryCapFor(outcome) {
        if (outcome.httpStatus === 502 || outcome.httpStatus === 504) { return 0; }
        return 1;
    }

    function driveNext() {
        if (!run || run.stopped || run.paused) { return; }
        if (Date.now() >= run.backoffUntil) {
            fillPool();
        } else {
            scheduleRefill();
        }
    }

    function registerSuccess() {
        run.successCount++;
        if (run.successCount < GROW_AFTER_SUCCESSES) { return; }
        run.successCount = 0;
        if (run.consecutiveFailWindows > 0) { run.consecutiveFailWindows--; }
        if (Date.now() - run.lastShrinkAt < GROW_COOLDOWN_MS) { return; }
        if (run.latSamples >= LATENCY_MIN_SAMPLES && run.latBase > 0 &&
            (run.latEwma / run.latBase) > LATENCY_GROW_MAX_RATIO) { return; }
        if (run.desired < growthCeiling()) { run.desired++; }
    }

    function onLatencySample(latency) {
        if (!(latency > 0)) { return; }
        run.latSamples++;
        if (run.latBase <= 0) {
            run.latBase = latency;
        } else {
            run.latBase = Math.min(run.latBase * (1 + LATENCY_BASE_LEAK), latency);
        }
        run.latEwma = (run.latEwma > 0)
            ? (run.latEwma + LATENCY_EWMA_ALPHA * (latency - run.latEwma))
            : latency;
        if (run.latSamples >= LATENCY_MIN_SAMPLES && run.latBase > 0 &&
            (run.latEwma / run.latBase) >= LATENCY_SHRINK_RATIO) {
            shrinkForLatency();
        }
    }

    function recordOutcome(ok) {
        run.recentOk.push(!!ok);
        if (run.recentOk.length > FAIL_WINDOW) { run.recentOk.shift(); }
        if (run.recentOk.length < FAIL_WINDOW) { return; }
        var fails = 0;
        for (var i = 0; i < run.recentOk.length; i++) {
            if (!run.recentOk[i]) { fails++; }
        }
        if (fails >= FAIL_WINDOW_TRIP) {
            run.recentOk = [];
            applyFailureBackoff({});
        }
    }

    function shrinkForLatency() {
        var now = Date.now();
        if (now - run.lastDecrementAt >= BACKOFF_DEBOUNCE_MS) {
            run.desired = Math.max(run.floor, run.desired - 1);
            run.lastDecrementAt = now;
            run.lastShrinkAt = now;
        }
    }

    function applyFailureBackoff(outcome) {
        var now = Date.now();
        run.successCount = 0;
        if (now - run.lastDecrementAt >= BACKOFF_DEBOUNCE_MS) {
            run.desired = Math.max(run.floor, run.desired - 1);
            run.lastDecrementAt = now;
            run.lastShrinkAt = now;
            run.consecutiveFailWindows++;
        }
        var pause = Math.min(BACKOFF_MAX_MS, BACKOFF_BASE_MS * Math.pow(2, Math.min(5, run.consecutiveFailWindows)));
        var retryAfter = outcome && outcome.retryAfterMs;
        if (retryAfter && retryAfter > pause) {
            pause = Math.min(retryAfter, BACKOFF_MAX_MS * 4);
        }
        if (now + pause > run.backoffUntil) { run.backoffUntil = now + pause; }
    }

    var refillScheduled = false;
    function scheduleRefill() {
        if (refillScheduled || !run) { return; }
        refillScheduled = true;
        var scheduledRun = run;
        var delay = (Date.now() < run.backoffUntil) ? (run.backoffUntil - Date.now()) : 0;
        setTimeout(function () {
            refillScheduled = false;
            if (run === scheduledRun && !run.stopped) { fillPool(); }
        }, Math.max(0, delay));
    }

    function finishRun() {
        if (!run || run.finished) { return; }
        run.finished = true;
        var elapsed = Math.round((Date.now() - run.startedAt) / 1000);
        var saved = run.orgBytes - run.convBytes;

        var btn = el('bulkPauseResumeBtn');
        if (btn) { btn.style.display = 'none'; }
        var status = el('bulkStatusLine');
        if (status) { status.innerText = 'Done'; }

        var summary = el('bulkProgressText');
        if (summary) {
            summary.innerHTML =
                '<b>Done.</b> ' + run.converted + ' converted' +
                (run.skipped ? ', ' + run.skipped + ' skipped' : '') +
                (run.failed ? ', ' + run.failed + ' failed' : '') +
                ' of ' + run.total + '. ' +
                humanBytes(saved > 0 ? saved : 0) + ' saved' +
                (run.orgBytes ? ' (' + reductionPct(run.orgBytes, run.convBytes) + '%)' : '') +
                ' in ' + elapsed + 's.';
        }
        renderErrors();
    }
})();
