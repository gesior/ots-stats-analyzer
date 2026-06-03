(function () {
    'use strict';

    const API_BASE = '/api.php';
    const RANGE_SECONDS = { hour: 3600, day: 86400, '7d': 604800 };

    const state = {
        sources: [],
        source: 'dispatcher',
        range: 'day',
        end: null,
        earliest: null,
        latest: null,
        sort: 'count',
        selectedDescriptionId: null,
        loadToken: 0,
    };

    let overviewChart = null;
    let functionChart = null;
    let endTimeDebounce = null;
    let lastOverviewData = null;

    const els = {
        sourceTabs: document.getElementById('source-tabs'),
        rangeButtons: document.querySelectorAll('.range-buttons button'),
        endTime: document.getElementById('end-time'),
        timePrev: document.getElementById('time-prev'),
        timeNext: document.getElementById('time-next'),
        sortSelect: document.getElementById('sort-select'),
        loading: document.getElementById('loading'),
        error: document.getElementById('error'),
        summaryCards: document.getElementById('summary-cards'),
        overviewTitle: document.getElementById('overview-title'),
        functionList: document.getElementById('function-list'),
        functionSection: document.getElementById('function-section'),
        functionTitle: document.getElementById('function-title'),
    };

    init();

    async function init() {
        bindEvents();
        els.sortSelect.value = state.sort;
        await loadMeta();
        await refresh();
    }

    function bindEvents() {
        els.rangeButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                setRange(btn.dataset.range);
            });
        });

        els.endTime.addEventListener('change', () => {
            clearTimeout(endTimeDebounce);
            endTimeDebounce = setTimeout(() => {
                const ts = parseDatetimeLocal(els.endTime.value);
                if (ts !== null) {
                    state.end = clampEnd(ts);
                    refresh();
                }
            }, 300);
        });

        els.timePrev.addEventListener('click', () => shiftEnd(-1));
        els.timeNext.addEventListener('click', () => shiftEnd(1));
        els.sortSelect.addEventListener('change', () => {
            state.sort = els.sortSelect.value;
            refreshFunctionsOnly();
        });
    }

    async function loadMeta() {
        const meta = await apiGet({ action: 'slow-meta' });
        state.sources = meta.sources || [];
        state.earliest = meta.earliest_occurred_at;
        state.latest = meta.latest_occurred_at;
        state.end = meta.default_end;

        renderSourceTabs();
        updateEndTimeInput();
    }

    function renderSourceTabs() {
        els.sourceTabs.innerHTML = '';
        state.sources.forEach((source) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = source;
            btn.className = source === state.source ? 'active' : '';
            btn.addEventListener('click', () => {
                if (state.source === source) {
                    return;
                }
                state.source = source;
                state.selectedDescriptionId = null;
                renderSourceTabs();
                refresh();
            });
            els.sourceTabs.appendChild(btn);
        });
    }

    function setRange(range) {
        if (state.range === range) {
            return;
        }
        state.range = range;
        els.rangeButtons.forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.range === range);
        });
        refresh();
    }

    function shiftEnd(direction) {
        if (state.end === null) {
            return;
        }
        const delta = RANGE_SECONDS[state.range] * direction;
        state.end = clampEnd(state.end + delta);
        updateEndTimeInput();
        refresh();
    }

    function clampEnd(ts) {
        if (state.earliest !== null) {
            ts = Math.max(ts, state.earliest + RANGE_SECONDS[state.range]);
        }
        if (state.latest !== null) {
            ts = Math.min(ts, state.latest);
        }
        return ts;
    }

    function updateEndTimeInput() {
        if (state.end === null) {
            els.endTime.value = '';
            return;
        }
        els.endTime.value = formatDatetimeLocal(state.end);
    }

    async function refresh() {
        const token = ++state.loadToken;
        setLoading(true);
        setError('');

        try {
            const params = buildParams();
            const [overview, topFunctions] = await Promise.all([
                apiGet({ action: 'slow-overview', ...params }),
                apiGet({ action: 'slow-top-functions', ...params, sort: state.sort }),
            ]);

            if (token !== state.loadToken) {
                return;
            }

            renderSummaryCards(overview.comparison);
            renderOverview(overview);
            renderFunctionList(topFunctions.functions || []);

            if (state.selectedDescriptionId !== null) {
                await loadFunctionSeries(token);
            } else {
                els.functionSection.classList.add('hidden');
            }
        } catch (err) {
            if (token !== state.loadToken) {
                return;
            }
            setError(err.message || 'Failed to load data.');
        } finally {
            if (token === state.loadToken) {
                setLoading(false);
            }
        }
    }

    async function refreshFunctionsOnly() {
        const token = ++state.loadToken;
        setLoading(true);
        setError('');

        try {
            const params = buildParams();
            const topFunctions = await apiGet({
                action: 'slow-top-functions',
                ...params,
                sort: state.sort,
            });

            if (token !== state.loadToken) {
                return;
            }

            renderFunctionList(topFunctions.functions || []);
        } catch (err) {
            if (token !== state.loadToken) {
                return;
            }
            setError(err.message || 'Failed to load function list.');
        } finally {
            if (token === state.loadToken) {
                setLoading(false);
            }
        }
    }

    async function loadFunctionSeries(token) {
        const params = buildParams();
        const series = await apiGet({
            action: 'slow-function-series',
            ...params,
            description_id: state.selectedDescriptionId,
        });

        if (token !== state.loadToken) {
            return;
        }

        renderFunctionSeries(series);
    }

    function buildParams() {
        const params = {
            source: state.source,
            range: state.range,
        };
        if (state.end !== null) {
            params.end = state.end;
        }
        return params;
    }

    function renderSummaryCards(comparison) {
        if (!comparison) {
            els.summaryCards.classList.add('hidden');
            els.summaryCards.innerHTML = '';
            return;
        }

        const current = comparison.current || {};
        const delta = comparison.delta || {};

        els.summaryCards.innerHTML = [
            buildSummaryCard('Events', current.event_count, delta.event_count_pct, false),
            buildSummaryCard('Max time', formatMs(current.max_execution_ms), delta.max_execution_ms_pct, true),
            buildSummaryCard('Avg time', formatMs(current.avg_execution_ms), delta.avg_execution_ms_pct, true),
            buildSummaryCard('Unique functions', current.unique_functions, null, false),
        ].join('');
        els.summaryCards.classList.remove('hidden');
    }

    function buildSummaryCard(label, value, deltaPct, lowerIsBetter) {
        let deltaHtml = '';
        if (deltaPct !== null && deltaPct !== undefined) {
            const cls = deltaClass(deltaPct, lowerIsBetter);
            const sign = deltaPct > 0 ? '+' : '';
            deltaHtml = `<div class="delta ${cls}">${sign}${deltaPct.toFixed(1)}% vs previous period</div>`;
        }

        return (
            `<div class="summary-card">` +
            `<div class="label">${escapeHtml(label)}</div>` +
            `<div class="value">${escapeHtml(String(value ?? '—'))}</div>` +
            deltaHtml +
            `</div>`
        );
    }

    function deltaClass(deltaPct, lowerIsBetter) {
        if (Math.abs(deltaPct) < 0.05) {
            return 'neutral';
        }

        const improved = lowerIsBetter ? deltaPct < 0 : deltaPct > 0;
        return improved ? 'positive' : 'negative';
    }

    function renderOverview(data) {
        lastOverviewData = data;
        els.overviewTitle.textContent = `Lag activity (${data.source})`;

        const labels = data.points.map((p) => formatLabel(p.t));

        overviewChart = createOrUpdateChart(
            overviewChart,
            'overview-chart',
            {
                labels,
                datasets: [
                    {
                        label: 'Events',
                        data: data.points.map((p) => p.event_count),
                        borderColor: '#ff6b6b',
                        backgroundColor: 'rgba(255, 107, 107, 0.15)',
                        yAxisID: 'events',
                        tension: 0.15,
                        pointRadius: 0,
                        borderWidth: 2,
                    },
                    {
                        label: 'Max time (ms)',
                        data: data.points.map((p) => p.max_execution_ms),
                        borderColor: '#ff9f43',
                        backgroundColor: 'rgba(255, 159, 67, 0.12)',
                        yAxisID: 'time',
                        tension: 0.15,
                        pointRadius: 0,
                        borderWidth: 2,
                    },
                    {
                        label: 'Avg time (ms)',
                        data: data.points.map((p) => p.avg_execution_ms),
                        borderColor: '#8b9cb3',
                        backgroundColor: 'transparent',
                        yAxisID: 'time',
                        borderDash: [4, 4],
                        tension: 0.15,
                        pointRadius: 0,
                        borderWidth: 1.5,
                    },
                ],
            },
            {
                x: {
                    ticks: { maxTicksLimit: 12, color: '#8b9cb3' },
                    grid: { color: 'rgba(45, 58, 79, 0.6)' },
                },
                events: {
                    type: 'linear',
                    position: 'left',
                    min: 0,
                    title: { display: true, text: 'Events', color: '#8b9cb3' },
                    ticks: { color: '#8b9cb3' },
                    grid: { color: 'rgba(45, 58, 79, 0.6)' },
                },
                time: {
                    type: 'linear',
                    position: 'right',
                    min: 0,
                    title: { display: true, text: 'Time (ms)', color: '#8b9cb3' },
                    ticks: { color: '#8b9cb3' },
                    grid: { drawOnChartArea: false },
                },
            },
        );
    }

    function renderFunctionList(functions) {
        els.functionList.innerHTML = '';

        if (functions.length === 0) {
            const li = document.createElement('li');
            li.textContent = 'No data in selected range.';
            li.style.padding = '0.75rem 0.35rem';
            li.style.color = '#8b9cb3';
            els.functionList.appendChild(li);
            return;
        }

        functions.forEach((fn) => {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = fn.description_id === state.selectedDescriptionId ? 'active' : '';
            btn.innerHTML =
                `<span class="fn-name">${escapeHtml(fn.description)}</span>` +
                `<span class="fn-stats">${fn.event_count} events · max ${fn.max_execution_ms} ms · avg ${fn.avg_execution_ms.toFixed(1)} ms · min ${fn.min_execution_ms} ms</span>`;
            btn.addEventListener('click', async () => {
                state.selectedDescriptionId = fn.description_id;
                renderFunctionList(functions);
                const token = ++state.loadToken;
                setLoading(true);
                try {
                    await loadFunctionSeries(token);
                    els.functionSection.classList.remove('hidden');
                } catch (err) {
                    setError(err.message || 'Failed to load function chart.');
                } finally {
                    if (token === state.loadToken) {
                        setLoading(false);
                    }
                }
            });
            li.appendChild(btn);
            els.functionList.appendChild(li);
        });
    }

    function alignFunctionPoints(overview, seriesPoints) {
        const overviewPoints = overview?.points ?? [];
        if (overviewPoints.length === 0) {
            return seriesPoints;
        }

        const byT = new Map(seriesPoints.map((p) => [p.t, p]));
        return overviewPoints.map((op) => {
            const matched = byT.get(op.t);
            if (matched) {
                return matched;
            }
            return {
                t: op.t,
                event_count: null,
                min_execution_ms: null,
                max_execution_ms: null,
                avg_execution_ms: null,
            };
        });
    }

    function renderFunctionSeries(data) {
        els.functionTitle.textContent = data.description;

        const points = alignFunctionPoints(lastOverviewData, data.points);
        const labels = points.map((p) => formatLabel(p.t));
        const showMin = points.some((p) => p.event_count >= 3 && p.min_execution_ms !== null);

        const datasets = [
            {
                type: 'bar',
                label: 'Events',
                data: points.map((p) => p.event_count),
                backgroundColor: 'rgba(255, 107, 107, 0.55)',
                borderColor: '#ff6b6b',
                yAxisID: 'events',
            },
            {
                type: 'line',
                label: 'Max time (ms)',
                data: points.map((p) => p.max_execution_ms),
                borderColor: '#ff9f43',
                backgroundColor: 'rgba(255, 159, 67, 0.12)',
                yAxisID: 'time',
                tension: 0.15,
                pointRadius: 0,
                borderWidth: 2,
            },
            {
                type: 'line',
                label: 'Avg time (ms)',
                data: points.map((p) => p.avg_execution_ms),
                borderColor: '#4da3ff',
                backgroundColor: 'transparent',
                yAxisID: 'time',
                tension: 0.15,
                pointRadius: 0,
                borderWidth: 2,
            },
        ];

        if (showMin) {
            datasets.push({
                type: 'line',
                label: 'Min time (ms)',
                data: points.map((p) => (p.event_count >= 3 ? p.min_execution_ms : null)),
                borderColor: '#8b9cb3',
                backgroundColor: 'transparent',
                yAxisID: 'time',
                borderDash: [4, 4],
                tension: 0.15,
                pointRadius: 0,
                borderWidth: 1.5,
            });
        }

        functionChart = createOrUpdateMixedChart(
            functionChart,
            'function-chart',
            { labels, datasets },
            {
                x: {
                    ticks: { maxTicksLimit: 12, color: '#8b9cb3' },
                    grid: { color: 'rgba(45, 58, 79, 0.6)' },
                },
                events: {
                    type: 'linear',
                    position: 'left',
                    min: 0,
                    title: { display: true, text: 'Events', color: '#8b9cb3' },
                    ticks: { color: '#8b9cb3' },
                    grid: { color: 'rgba(45, 58, 79, 0.6)' },
                },
                time: {
                    type: 'linear',
                    position: 'right',
                    min: 0,
                    title: { display: true, text: 'Time (ms)', color: '#8b9cb3' },
                    ticks: { color: '#8b9cb3' },
                    grid: { drawOnChartArea: false },
                },
            },
        );
    }

    function createOrUpdateChart(existing, canvasId, data, scales) {
        const ctx = document.getElementById(canvasId).getContext('2d');
        const options = buildChartOptions(scales);

        if (existing) {
            existing.data = data;
            existing.options = options;
            existing.update();
            return existing;
        }

        return new Chart(ctx, { type: 'line', data, options });
    }

    function createOrUpdateMixedChart(existing, canvasId, data, scales) {
        const ctx = document.getElementById(canvasId).getContext('2d');
        const options = buildChartOptions(scales);

        if (existing) {
            existing.data = data;
            existing.options = options;
            existing.update();
            return existing;
        }

        return new Chart(ctx, { data, options });
    }

    function buildChartOptions(scales) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#e7ecf3' } },
            },
            scales,
        };
    }

    async function apiGet(params) {
        const query = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined && value !== null) {
                query.set(key, String(value));
            }
        });

        const response = await fetch(`${API_BASE}?${query.toString()}`);
        const body = await response.json();

        if (!response.ok) {
            throw new Error(body.error || `HTTP ${response.status}`);
        }

        return body;
    }

    function setLoading(isLoading) {
        els.loading.classList.toggle('hidden', !isLoading);
    }

    function setError(message) {
        if (!message) {
            els.error.classList.add('hidden');
            els.error.textContent = '';
            return;
        }
        els.error.textContent = message;
        els.error.classList.remove('hidden');
    }

    function formatMs(value) {
        if (value === null || value === undefined) {
            return '—';
        }
        return `${Math.round(value)} ms`;
    }

    function formatLabel(unix) {
        const d = new Date(unix * 1000);
        return d.toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function formatDatetimeLocal(unix) {
        const d = new Date(unix * 1000);
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    }

    function parseDatetimeLocal(value) {
        if (!value) {
            return null;
        }
        const ms = Date.parse(value);
        return Number.isNaN(ms) ? null : Math.floor(ms / 1000);
    }

    function escapeHtml(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
