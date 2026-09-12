(function () {
    'use strict';

    const root = document.querySelector('[data-navimow-map]');
    if (!root) {
        return;
    }
    const surface = root.querySelector('[data-map-surface]');
    const stage = root.querySelector('[data-map-stage]');
    const status = root.querySelector('[data-status]');
    const statistics = root.querySelector('[data-statistics]');
    const zoneSelect = root.querySelector('[data-zone]');
    const followButton = root.querySelector('[data-follow]');
    const pointers = new Map();
    const state = {
        svg: null,
        fitViewBox: null,
        viewBox: null,
        geometryKey: null,
        following: false,
        drag: null,
        pinch: null,
    };

    function parseViewBox(svg)
    {
        const values = svg.getAttribute('viewBox').trim().split(/\s+/)
            .map(Number);
        if (values.length !== 4 || values.some((value) => !Number.isFinite(value))) {
            throw new Error('Kartenansicht ist ungültig.');
        }
        return values;
    }

    function applyViewBox(values)
    {
        if (!state.svg) {
            return;
        }
        state.viewBox = values;
        state.svg.setAttribute('viewBox', values.join(' '));
    }

    function zoom(factor, clientX, clientY)
    {
        if (!state.svg || !state.viewBox) {
            return;
        }
        const rect = surface.getBoundingClientRect();
        const xRatio = rect.width > 0 ? (clientX - rect.left) / rect.width : 0.5;
        const yRatio = rect.height > 0 ? (clientY - rect.top) / rect.height : 0.5;
        const current = state.viewBox;
        const minimumWidth = state.fitViewBox[2] * 0.08;
        const maximumWidth = state.fitViewBox[2] * 8;
        const width = Math.min(maximumWidth, Math.max(minimumWidth, current[2] * factor));
        const height = width * current[3] / current[2];
        applyViewBox([
            current[0] + (current[2] - width) * xRatio,
            current[1] + (current[3] - height) * yRatio,
            width,
            height,
        ]);
    }

    function fit()
    {
        if (state.fitViewBox) {
            applyViewBox([...state.fitViewBox]);
        }
    }

    function focusElement(element, paddingFactor)
    {
        if (!state.svg || !element) {
            return;
        }
        const box = element.getBBox();
        if (!(box.width > 0 && box.height > 0)) {
            return;
        }
        const padding = Math.max(box.width, box.height) * paddingFactor;
        applyViewBox([
            box.x - padding,
            box.y - padding,
            box.width + 2 * padding,
            box.height + 2 * padding,
        ]);
    }

    function focusMower()
    {
        const mower = state.svg ? state.svg.querySelector('.mower') : null;
        if (!mower || !state.viewBox) {
            return;
        }
        const transform = mower.transform.baseVal.consolidate();
        if (!transform) {
            return;
        }
        const point = state.svg.createSVGPoint();
        point.x = 0;
        point.y = 0;
        const projected = point.matrixTransform(transform.matrix);
        applyViewBox([
            projected.x - state.viewBox[2] / 2,
            projected.y - state.viewBox[3] / 2,
            state.viewBox[2],
            state.viewBox[3],
        ]);
    }

    function formatArea(value)
    {
        return Number.isFinite(value)
            ? new Intl.NumberFormat('de-DE', {maximumFractionDigits : 1}).format(value) + ' m²'
            : '–';
    }

    function formatPercent(value)
    {
        return Number.isFinite(value)
            ? new Intl.NumberFormat('de-DE', {maximumFractionDigits : 1}).format(value) + ' %'
            : '–';
    }

    function recencyText(zone)
    {
        if (!Number.isInteger(zone.recencyDays)) {
            return 'noch ohne Verlauf';
        }
        if (zone.recencyDays === 0) {
            return 'heute gemäht';
        }
        return 'vor ' + zone.recencyDays + ' Tagen';
    }

    function renderStatistics(analytics)
    {
        statistics.replaceChildren();
        const zones = analytics && Array.isArray(analytics.zones)
            ? analytics.zones
            : [];
        zones.forEach(function (zone) {
            const row = document.createElement('div');
            row.className = 'nav-map__zone-stat';
            row.dataset.recency = String(zone.recencyState || 0);
            const title = document.createElement('strong');
            title.textContent = zone.label;
            const recency = document.createElement('span');
            recency.textContent = recencyText(zone);
            const coverage = document.createElement('span');
            coverage.textContent = 'Lauf ' + formatPercent(zone.latestRunCoveragePercent);
            const week = document.createElement('span');
            week.textContent = 'Woche ' + formatArea(zone.weekEstimatedArea);
            row.append(title, recency, coverage, week);
            statistics.append(row);
        });
    }

    function zoneEntries(analytics)
    {
        if (analytics && Array.isArray(analytics.zones)
            && analytics.zones.length > 0) {
            return analytics.zones.map(function (zone) {
                return {zoneId: zone.zoneId, label: zone.label};
            });
        }
        if (!state.svg) {
            return [];
        }
        return [...state.svg.querySelectorAll('.zone[data-zone-id]')]
            .map(function (zone) {
                const title = zone.querySelector('title');
                return {
                    zoneId: zone.dataset.zoneId,
                    label: title ? title.textContent.split(';', 1)[0] : '',
                };
            })
            .filter(function (zone) {
                return zone.zoneId !== '' && zone.label !== '';
            });
    }

    function populateZones(analytics)
    {
        const selected = zoneSelect.value;
        zoneSelect.replaceChildren();
        const all = document.createElement('option');
        all.value = '';
        all.textContent = 'Alle Zonen';
        zoneSelect.append(all);
        zoneEntries(analytics).forEach(function (zone) {
            const option = document.createElement('option');
            option.value = String(zone.zoneId);
            option.textContent = zone.label;
            zoneSelect.append(option);
        });
        if ([...zoneSelect.options].some((option) => option.value === selected)) {
            zoneSelect.value = selected;
        }
    }

    function render(payload)
    {
        if (typeof payload.svg !== 'string' || payload.svg.length > 1048576) {
            throw new Error('Kartendaten sind ungültig.');
        }
        const previous = state.viewBox ? [...state.viewBox] : null;
        const sameGeometry = state.geometryKey
            && payload.analytics
            && state.geometryKey === payload.analytics.geometryKey;
        stage.innerHTML = payload.svg;
        state.svg = stage.querySelector('svg');
        if (!state.svg) {
            throw new Error('Karte konnte nicht aufgebaut werden.');
        }
        state.fitViewBox = parseViewBox(state.svg);
        state.geometryKey = payload.analytics ? payload.analytics.geometryKey : null;
        applyViewBox(sameGeometry && previous ? previous : [...state.fitViewBox]);
        populateZones(payload.analytics);
        renderStatistics(payload.analytics);
        root.dataset.theme = payload.theme === 'light' ? 'light' : 'dark';
        status.textContent = payload.status === 'stale'
            ? 'Position veraltet'
            : '';
        if (state.following) {
            focusMower();
        }
    }

    function handleMessage(message)
    {
        try {
            const payload = typeof message === 'string' ? JSON.parse(message) : message;
            if (!payload || typeof payload !== 'object') {
                throw new Error('Kartennachricht ist ungültig.');
            }
            if (payload.action === 'render') {
                render(payload);
                return;
            }
            if (payload.action === 'configurationError') {
                stage.replaceChildren();
                statistics.replaceChildren();
                status.textContent = payload.message || 'Karte nicht verfügbar';
                return;
            }
        } catch (error) {
            status.textContent = error instanceof Error
                ? error.message
                : 'Karte nicht verfügbar';
        }
    }

    surface.addEventListener('wheel', function (event) {
        event.preventDefault();
        zoom(event.deltaY < 0 ? 0.82 : 1.22, event.clientX, event.clientY);
        state.following = false;
        followButton.setAttribute('aria-pressed', 'false');
    }, {passive: false});

    surface.addEventListener('pointerdown', function (event) {
        surface.setPointerCapture(event.pointerId);
        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        if (pointers.size === 1 && state.viewBox) {
            state.drag = {x: event.clientX, y: event.clientY, viewBox: [...state.viewBox]};
        } else if (pointers.size === 2 && state.viewBox) {
            const points = [...pointers.values()];
            state.pinch = {
                distance: Math.hypot(points[1].x - points[0].x, points[1].y - points[0].y),
                viewBox: [...state.viewBox],
            };
        }
    });

    surface.addEventListener('pointermove', function (event) {
        if (!pointers.has(event.pointerId) || !state.viewBox) {
            return;
        }
        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        if (pointers.size === 2 && state.pinch) {
            const points = [...pointers.values()];
            const distance = Math.hypot(
                points[1].x - points[0].x,
                points[1].y - points[0].y
            );
            if (distance > 0) {
                state.viewBox = [...state.pinch.viewBox];
                const centerX = (points[0].x + points[1].x) / 2;
                const centerY = (points[0].y + points[1].y) / 2;
                zoom(state.pinch.distance / distance, centerX, centerY);
            }
            return;
        }
        if (pointers.size === 1 && state.drag) {
            const rect = surface.getBoundingClientRect();
            if (rect.width > 0 && rect.height > 0) {
                const dx = (event.clientX - state.drag.x)
                    * state.drag.viewBox[2] / rect.width;
                const dy = (event.clientY - state.drag.y)
                    * state.drag.viewBox[3] / rect.height;
                applyViewBox([
                    state.drag.viewBox[0] - dx,
                    state.drag.viewBox[1] - dy,
                    state.drag.viewBox[2],
                    state.drag.viewBox[3],
                ]);
            }
        }
    });

    function releasePointer(event)
    {
        pointers.delete(event.pointerId);
        state.drag = null;
        state.pinch = null;
        state.following = false;
        followButton.setAttribute('aria-pressed', 'false');
    }
    surface.addEventListener('pointerup', releasePointer);
    surface.addEventListener('pointercancel', releasePointer);

    root.querySelector('[data-zoom-in]').addEventListener('click', function () {
        const rect = surface.getBoundingClientRect();
        zoom(0.82, rect.left + rect.width / 2, rect.top + rect.height / 2);
    });
    root.querySelector('[data-zoom-out]').addEventListener('click', function () {
        const rect = surface.getBoundingClientRect();
        zoom(1.22, rect.left + rect.width / 2, rect.top + rect.height / 2);
    });
    root.querySelector('[data-fit]').addEventListener('click', function () {
        fit();
        state.following = false;
        followButton.setAttribute('aria-pressed', 'false');
    });
    followButton.addEventListener('click', function () {
        state.following = !state.following;
        followButton.setAttribute('aria-pressed', String(state.following));
        if (state.following) {
            focusMower();
        }
    });
    zoneSelect.addEventListener('change', function () {
        state.following = false;
        followButton.setAttribute('aria-pressed', 'false');
        if (zoneSelect.value === '') {
            fit();
            return;
        }
        const selector = '.zone[data-zone-id="'
            + CSS.escape(zoneSelect.value) + '"]';
        focusElement(state.svg ? state.svg.querySelector(selector) : null, 0.12);
    });

    window.handleMessage = handleMessage;
}());
