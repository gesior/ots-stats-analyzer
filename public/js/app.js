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
        sort: 'total',
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
        const meta = await apiGet({ action: 'meta' });
        state.sources = meta.sources || [];
        state.earliest = meta.earliest_reported_at;
        state.latest = meta.latest_reported_at;
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
                apiGet({ action: 'overview', ...params }),
                apiGet({ action: 'top-functions', ...params, sort: state.sort }),
            ]);

            if (token !== state.loadToken) {
                return;
            }

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
                action: 'top-functions',
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
            action: 'function-series',
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

    function renderOverview(data) {
        lastOverviewData = data;
        const isDispatcher = data.source === 'dispatcher';
        els.overviewTitle.textContent = isDispatcher
            ? 'CPU and online players (dispatcher)'
            : `CPU usage (${data.source})`;

        const labels = data.points.map((p) => formatLabel(p.t));
        const cpuKey = isDispatcher ? 'cpu_usage' : 'real_usage';
        const cpuLabel = isDispatcher ? 'CPU %' : 'Real usage %';

        const datasets = [
            {
                label: cpuLabel,
                data: data.points.map((p) => p[cpuKey]),
                borderColor: '#4da3ff',
                backgroundColor: 'rgba(77, 163, 255, 0.12)',
                yAxisID: 'cpu',
                tension: 0.15,
                pointRadius: 0,
                borderWidth: 2,
            },
        ];

        if (isDispatcher) {
            datasets.push({
                label: 'Online players',
                data: data.points.map((p) => p.players_online),
                borderColor: '#ff9f43',
                backgroundColor: 'rgba(255, 159, 67, 0.12)',
                yAxisID: 'players',
                tension: 0.15,
                pointRadius: 0,
                borderWidth: 2,
            });
        }

        const scales = {
            x: {
                ticks: { maxTicksLimit: 12, color: '#8b9cb3' },
                grid: { color: 'rgba(45, 58, 79, 0.6)' },
            },
            cpu: {
                type: 'linear',
                position: 'left',
                min: 0,
                max: 100,
                title: { display: true, text: cpuLabel, color: '#8b9cb3' },
                ticks: { color: '#8b9cb3' },
                grid: { color: 'rgba(45, 58, 79, 0.6)' },
            },
        };

        if (isDispatcher) {
            scales.players = {
                type: 'linear',
                position: 'right',
                title: { display: true, text: 'Online players', color: '#8b9cb3' },
                ticks: { color: '#8b9cb3' },
                grid: { drawOnChartArea: false },
            };
        }

        overviewChart = createOrUpdateChart(
            overviewChart,
            'overview-chart',
            { labels, datasets },
            scales,
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
                `<span class="fn-stats">max ${fn.max_real_usage.toFixed(4)}% · avg ${fn.avg_real_usage.toFixed(4)}% · ${fn.total_time_ms} ms · ${fn.total_calls} calls</span>`;
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
                real_usage: null,
                time_ms: null,
                calls: null,
                players_online: null,
            };
        });
    }

    function renderFunctionSeries(data) {
        els.functionTitle.textContent = data.description;

        const points = alignFunctionPoints(lastOverviewData, data.points);
        const labels = points.map((p) => formatLabel(p.t));
        const hasPlayers = points.some((p) => p.players_online !== null && p.players_online !== undefined);
        const hasCalls = points.some((p) => p.calls !== null && p.calls !== undefined);

        const datasets = [
            {
                label: 'Real usage %',
                data: points.map((p) => p.real_usage),
                borderColor: '#4da3ff',
                backgroundColor: 'rgba(77, 163, 255, 0.12)',
                yAxisID: 'cpu',
                tension: 0.15,
                pointRadius: 0,
                borderWidth: 2,
            },
        ];

        const scales = {
            x: {
                ticks: { maxTicksLimit: 12, color: '#8b9cb3' },
                grid: { color: 'rgba(45, 58, 79, 0.6)' },
            },
            cpu: {
                type: 'linear',
                position: 'left',
                title: { display: true, text: 'Real usage %', color: '#8b9cb3' },
                ticks: { color: '#8b9cb3' },
                grid: { color: 'rgba(45, 58, 79, 0.6)' },
            },
        };

        if (hasPlayers) {
            datasets.push({
                label: 'Online players',
                data: points.map((p) => p.players_online),
                borderColor: '#ff9f43',
                backgroundColor: 'rgba(255, 159, 67, 0.12)',
                yAxisID: 'players',
                tension: 0.15,
                pointRadius: 0,
                borderWidth: 2,
            });
            scales.players = {
                type: 'linear',
                position: 'right',
                title: { display: true, text: 'Online players', color: '#8b9cb3' },
                ticks: { color: '#8b9cb3' },
                grid: { drawOnChartArea: false },
            };
        }

        if (hasCalls) {
            datasets.push({
                label: 'Calls',
                data: points.map((p) => p.calls),
                borderColor: '#54d390',
                backgroundColor: 'rgba(84, 211, 144, 0.12)',
                yAxisID: 'calls',
                tension: 0.15,
                pointRadius: 0,
                borderWidth: 2,
            });
            scales.calls = {
                type: 'linear',
                position: 'right',
                offset: hasPlayers,
                title: { display: true, text: 'Calls', color: '#8b9cb3' },
                ticks: { color: '#8b9cb3' },
                grid: { drawOnChartArea: false },
            };
        }

        functionChart = createOrUpdateChart(
            functionChart,
            'function-chart',
            { labels, datasets },
            scales,
        );
    }

    function createOrUpdateChart(existing, canvasId, data, scales) {
        const ctx = document.getElementById(canvasId).getContext('2d');
        const options = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#e7ecf3' } },
                tooltip: {
                    callbacks: {
                        title(items) {
                            if (!items.length) {
                                return '';
                            }
                            const idx = items[0].dataIndex;
                            const ts = data.labels[idx];
                            return ts;
                        },
                    },
                },
            },
            scales,
        };

        if (existing) {
            existing.data = data;
            existing.options = options;
            existing.update();
            return existing;
        }

        return new Chart(ctx, { type: 'line', data, options });
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
