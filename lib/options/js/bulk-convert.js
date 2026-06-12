/*
 * Magic Convert — parallel bulk conversion (Phase 1.2).
 *
 * Replaces the old strictly-serial admin-ajax loop with an ADAPTIVE promise pool
 * driving the REST API (magic-convert/v1). Design goal: zero configuration for
 * ordinary users. The pool sizes itself from a server recommendation and tunes
 * itself live with AIMD (additive-increase / multiplicative-decrease):
 *
 *   - start at min(2, server-recommended),
 *   - after every 20 consecutive successes, add one worker (up to the server cap,
 *     absolute max 6, or a manual Advanced override if the user set one),
 *   - on any timeout / 429 / 5xx / fetch-rejection, OR any response with
 *     server_busy=true, halve the active workers (floor 1) and back off
 *     exponentially before resuming.
 *
 * A failed file is retried at most once, then listed in a visible error panel.
 * The Phase 1.1 'in-progress' status is surfaced as a soft skip, not an error.
 *
 * The script keeps the legacy element ids (#bulkconvertcontent, #bulkconvertlog)
 * and the openBulkConvertPopup() entry point so the existing popup markup and the
 * Bulk Convert button keep working unchanged.
 */

(function () {
    'use strict';

    // --- localized config (from enqueue_scripts.php) ---------------------------
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

    var ABSOLUTE_MAX_WORKERS = 6;
    var SUCCESS_STREAK_TO_GROW = 20;
    var PER_REQUEST_TIMEOUT_MS = 120000;        // generous; WebP/4K can be slow.
    var AVIF_PER_REQUEST_TIMEOUT_MS = 300000;   // AVIF encodes 5-47x slower than WebP — 5 min.
    var LIST_PAGE_SIZE = 500;
    var BACKOFF_BASE_MS = 500;
    var BACKOFF_MAX_MS = 15000;
    var OVERRIDE_STORAGE_KEY = 'magicconvert_parallel_override';

    var DEFAULT_FORMAT = 'webp';

    // Human labels for the format ids we know about (used in progress + the slow-AVIF note).
    function formatLabel(id) {
        if (id === 'webp') { return 'WebP'; }
        if (id === 'avif') { return 'AVIF'; }
        return String(id).toUpperCase();
    }

    // Per-request timeout for a unit: AVIF gets a much longer window.
    function timeoutForFormat(format) {
        return (format === 'avif') ? AVIF_PER_REQUEST_TIMEOUT_MS : PER_REQUEST_TIMEOUT_MS;
    }

    // Expand one listing item { root, path, formats:[...] } into one work unit per
    // still-missing format: { root, path, format }. Legacy items without a formats
    // array fall back to a single webp unit, preserving the old behaviour exactly.
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

    // A stable per-unit key (format + path) for retry bookkeeping — a file may have
    // independent WebP and AVIF units in flight, each retried separately. JSON-encoding
    // the [format, path] pair gives a collision-free, fully-printable key (no NUL byte),
    // keeping this asset plain UTF-8 text so minifiers/CDNs/SVN deploys don't choke.
    function unitKey(unit) {
        return JSON.stringify([unit.format, unit.path]);
    }

    // Single live run. Null when idle.
    var run = null;

    // ---------------------------------------------------------------------------
    //  Advanced override (localStorage-persisted manual cap)
    // ---------------------------------------------------------------------------
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
        } catch (e) { /* ignore */ }
    }
    // The hard cap: either the manual override, or the server recommendation,
    // never above the absolute max.
    function hardCap(serverMax) {
        var cap = Math.min(serverMax || ABSOLUTE_MAX_WORKERS, ABSOLUTE_MAX_WORKERS);
        var ov = getOverride();
        if (ov !== 'auto') {
            var n = parseInt(ov, 10);
            if (!isNaN(n) && n >= 1) {
                cap = Math.min(cap, n);
            }
        }
        return Math.max(1, cap);
    }

    // ---------------------------------------------------------------------------
    //  Small DOM / formatting helpers
    // ---------------------------------------------------------------------------
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

    // ---------------------------------------------------------------------------
    //  Entry point — called by the "Bulk Convert" button (unchanged markup)
    // ---------------------------------------------------------------------------
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

        // Step 6 — work estimate: file count per format before starting.
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

        // When AVIF is among the enabled formats, set expectations: AVIF encoding is slow.
        if (avifEnabled) {
            html += '<p style="color:#b26500;"><b>AVIF encoding is slower</b> — large libraries may take a while. ' +
                    'The WP-CLI command is faster for very large sites.</p>';
        }

        html += '<p><button id="bulkStartBtn" class="button button-primary" type="button">Start conversion</button></p>';

        // Advanced (collapsed) — a single manual override, default Automatic.
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

    // ---------------------------------------------------------------------------
    //  REST helpers
    // ---------------------------------------------------------------------------
    function fetchWithTimeout(url, options, timeoutMs) {
        // AbortController gives us a real client-side timeout that rejects the
        // promise — which the pool treats like a 5xx (back off + retry once).
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

    // Resolves to { ok, retriable, busy, result } — never rejects.
    // 'unit' carries { root, path, format }; AVIF units get a longer timeout.
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
            // Transport-level back-off triggers: 429 (rate limit) and 5xx.
            var retriable = (resp.status === 429 || resp.status >= 500);
            return resp.json().then(function (data) {
                if (data && data.nonce) { setRestNonce(data.nonce); }
                return {
                    ok: resp.ok && data && data.success === true,
                    retriable: retriable,
                    busy: !!(data && data.server_busy),
                    httpStatus: resp.status,
                    result: data || {}
                };
            }, function () {
                // Body was not JSON (e.g. an auth/HTML error page).
                return { ok: false, retriable: retriable, busy: false, httpStatus: resp.status, result: {} };
            });
        }, function () {
            // Network failure or client timeout (AbortController). Back off + retry.
            return { ok: false, retriable: true, busy: true, httpStatus: 0, result: {} };
        });
    }

    // ---------------------------------------------------------------------------
    //  The run / adaptive pool
    // ---------------------------------------------------------------------------
    function startRun(firstResponse, recommended, serverMax) {
        var formats = (firstResponse.formats && firstResponse.formats.length) ? firstResponse.formats : [DEFAULT_FORMAT];
        var formatTotals = firstResponse.format_totals || {};

        // The work unit is one (file, format) pair. The list 'total' counts FILES; the
        // run total counts UNITS (sum of per-format pending counts). Fall back to file
        // total when the server did not send per-format breakdowns (legacy list).
        var totalUnits = 0;
        var perFormatTotal = {};
        for (var i = 0; i < formats.length; i++) {
            var fid = formats[i];
            var cnt = (typeof formatTotals[fid] === 'number') ? formatTotals[fid] : firstResponse.total;
            perFormatTotal[fid] = cnt;
            totalUnits += cnt;
        }
        if (totalUnits === 0) { totalUnits = firstResponse.total; }

        // Per-format processed counters, all starting at 0.
        var perFormatDone = {};
        for (var j = 0; j < formats.length; j++) { perFormatDone[formats[j]] = 0; }

        run = {
            listId: firstResponse.list_id,
            total: totalUnits,                              // total work UNITS
            fileTotal: firstResponse.total,                 // total FILES (for reference)
            formats: formats,
            perFormatTotal: perFormatTotal,
            perFormatDone: perFormatDone,
            serverMax: serverMax,
            queue: expandListToUnits(firstResponse.files),  // pending {root,path,format}
            nextPage: 2,                                    // pages already drained: 1
            pagesExhausted: (firstResponse.files || []).length >= firstResponse.total,
            pageFetchInFlight: false,

            // progress counters
            converted: 0,
            skipped: 0,
            failed: 0,
            processed: 0,
            orgBytes: 0,
            convBytes: 0,
            errors: [],            // { path, format, msg }
            retryCounts: {},       // unitKey -> attempts

            // pool state
            desired: Math.max(1, Math.min(2, recommended)),  // start small
            activeWorkers: 0,
            successStreak: 0,
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
            fillPool();   // resume: refill the drained pool
        }
        updateProgressUi();
    }

    function updateProgressUi() {
        if (!run) { return; }
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

        // Per-format progress line, e.g. "WebP: 1,204/3,000 · AVIF: 87/3,000".
        // Only shown when more than one format is in play (zero noise for the default install).
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

    // Top up the queue from the next list page if it is running low.
    function maybeFetchMorePages() {
        if (!run || run.pagesExhausted || run.pageFetchInFlight) { return; }
        if (run.queue.length > LIST_PAGE_SIZE / 2) { return; }
        run.pageFetchInFlight = true;
        var page = run.nextPage;
        fetchPage(run.listId, page).then(function (data) {
            run.pageFetchInFlight = false;
            if (data && data.success && data.files && data.files.length) {
                run.queue = run.queue.concat(expandListToUnits(data.files));
                run.nextPage = page + 1;
                // Paging is over FILES (run.fileTotal), not work units (run.total).
                if ((page) * LIST_PAGE_SIZE >= run.fileTotal) {
                    run.pagesExhausted = true;
                }
                fillPool();
            } else {
                run.pagesExhausted = true;
            }
        }).catch(function () {
            run.pageFetchInFlight = false;
            // leave pagesExhausted false so a later top-up can retry
        });
    }

    // Spawn workers up to the desired count (respecting pause / backoff / cap).
    function fillPool() {
        if (!run || run.stopped || run.paused) { return; }

        maybeFetchMorePages();

        var cap = hardCap(run.serverMax);
        var target = Math.min(run.desired, cap);

        while (run.activeWorkers < target && run.queue.length > 0 && Date.now() >= run.backoffUntil) {
            spawnWorker();
        }

        // Completion: nothing active, queue empty, no more pages to fetch.
        if (run.activeWorkers === 0 && run.queue.length === 0 && run.pagesExhausted && !run.pageFetchInFlight) {
            finishRun();
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
            // Drain this worker; it will be re-spawned by fillPool on resume.
            run.activeWorkers--;
            scheduleRefill();
            return;
        }
        var unit = run.queue.shift();
        if (!unit) {
            run.activeWorkers--;
            // Maybe more pages are coming; otherwise this may complete the run.
            fillPool();
            return;
        }

        var key = unitKey(unit);
        var attempts = run.retryCounts[key] || 0;

        convertOne(unit, false).then(function (outcome) {
            handleOutcome(unit, attempts, outcome);
        });
    }

    // Mark one unit as finished (converted or skipped) for both the overall and the
    // per-format progress denominators.
    function markUnitDone(unit) {
        run.processed++;
        var fmt = unit.format || DEFAULT_FORMAT;
        if (typeof run.perFormatDone[fmt] === 'number') {
            run.perFormatDone[fmt]++;
        }
    }

    function handleOutcome(unit, attempts, outcome) {
        if (!run) { return; }

        var data = outcome.result || {};
        var status = data.status;

        // server_busy or transport error => multiplicative decrease + backoff.
        if (outcome.busy || outcome.retriable) {
            applyBackoff();
        }

        if (outcome.ok) {
            // Soft skip: already fresh (idempotent) is a success, counted apart.
            if (status === 'already-converted') {
                run.skipped++;
            } else {
                run.converted++;
                if (typeof data['filesize-original'] === 'number') { run.orgBytes += data['filesize-original']; }
                // The destination filesize is reported as 'filesize-webp' for every format
                // (it is the encoded output size regardless of format).
                if (typeof data['filesize-webp'] === 'number') { run.convBytes += data['filesize-webp']; }
            }
            markUnitDone(unit);
            registerSuccess();
        } else if (status === 'in-progress') {
            // Phase 1.1: another process holds the lock. Soft skip, NOT an error.
            run.skipped++;
            markUnitDone(unit);
            // Do not count toward the success streak, but do not back off either.
        } else if (outcome.retriable && attempts < 1) {
            // Retry once after backoff: requeue with an incremented attempt count.
            run.retryCounts[unitKey(unit)] = attempts + 1;
            run.queue.push(unit);
        } else if (!outcome.retriable && attempts < 1 && !outcome.ok && outcome.httpStatus !== 400) {
            // A soft (non-retriable) conversion failure: still allow one retry.
            run.retryCounts[unitKey(unit)] = attempts + 1;
            run.queue.push(unit);
        } else {
            // Out of retries (or a hard 400 invalid input): record the failure (with format).
            run.failed++;
            markUnitDone(unit);
            run.errors.push({
                path: unit.root + '/' + unit.path,
                format: unit.format || DEFAULT_FORMAT,
                msg: data.msg || ('HTTP ' + outcome.httpStatus)
            });
            run.successStreak = 0;
        }

        run.activeWorkers--;
        scheduleRefill();
        updateProgressUi();
    }

    function registerSuccess() {
        run.successStreak++;
        if (run.successStreak >= SUCCESS_STREAK_TO_GROW) {
            run.successStreak = 0;
            var cap = hardCap(run.serverMax);
            if (run.desired < cap) {
                run.desired++;
            }
        }
    }

    function applyBackoff() {
        // Multiplicative decrease: halve desired workers (floor 1).
        run.desired = Math.max(1, Math.floor(run.desired / 2));
        run.successStreak = 0;
        // Exponential backoff window, capped. Uses current desired as the
        // exponent base so deeper cuts back off longer.
        var factor = Math.min(BACKOFF_MAX_MS, BACKOFF_BASE_MS * Math.pow(2, Math.max(0, 3 - run.desired)));
        var until = Date.now() + factor;
        if (until > run.backoffUntil) { run.backoffUntil = until; }
    }

    // Coalesce refills so many concurrent worker completions schedule one tick.
    var refillScheduled = false;
    function scheduleRefill() {
        if (refillScheduled) { return; }
        refillScheduled = true;
        var delay = (run && Date.now() < run.backoffUntil) ? (run.backoffUntil - Date.now()) : 0;
        setTimeout(function () {
            refillScheduled = false;
            fillPool();
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
