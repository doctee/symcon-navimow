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
    const details = root.querySelector('[data-statistics-details]');
    const detailsTitle = details.querySelector('[data-details-title]');
    const detailsValues = details.querySelector('[data-details-values]');
    const followButton = root.querySelector('[data-follow]');
    const pointers = new Map();
    const viewportEdgeInset = 6;
    const positionTimeFormatter = new Intl.DateTimeFormat('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
    });
    const state = {
        svg: null,
        fitViewBox: null,
        viewBox: null,
        geometryKey: null,
        following: false,
        drag: null,
        pinch: null,
        legendFrame: null,
        statisticsFrame: null,
        detailsZoneId: null,
    };

    function alignLegendRight()
    {
        if (!state.svg || !state.fitViewBox) {
            return;
        }
        const legend = state.svg.querySelector('.legend[data-anchor-x][data-anchor-y]');
        if (!legend) {
            return;
        }
        const anchorX = Number(legend.dataset.anchorX);
        const anchorY = Number(legend.dataset.anchorY);
        if (!Number.isFinite(anchorX) || !Number.isFinite(anchorY)) {
            return;
        }
        legend.setAttribute('transform', 'translate(' + anchorX + ' ' + anchorY + ')');
        const mapRect = state.svg.getBoundingClientRect();
        const matrix = state.svg.getScreenCTM();
        if (!matrix || !(mapRect.width > 0) || !(mapRect.height > 0)) {
            return;
        }
        const currentScale = Math.min(
            Math.hypot(matrix.a, matrix.b),
            Math.hypot(matrix.c, matrix.d)
        );
        const fitScale = Math.min(
            mapRect.width / state.fitViewBox[2],
            mapRect.height / state.fitViewBox[3]
        );
        if (!(currentScale > 0) || !(fitScale > 0)) {
            return;
        }
        const counterScale = fitScale / currentScale;
        const legendBox = legend.getBBox();
        if (!(legendBox.width > 0) || !(legendBox.height > 0)) {
            return;
        }
        const target = state.svg.createSVGPoint();
        target.x = mapRect.right - viewportEdgeInset;
        target.y = mapRect.bottom - viewportEdgeInset;
        const viewportTarget = target.matrixTransform(matrix.inverse());
        const x = viewportTarget.x
            - (legendBox.x + legendBox.width) * counterScale;
        const y = viewportTarget.y
            - (legendBox.y + legendBox.height) * counterScale;
        legend.setAttribute(
            'transform',
            'translate(' + x + ' ' + y + ') scale(' + counterScale + ')'
        );
    }

    function scheduleLegendAlignment()
    {
        if (state.legendFrame !== null) {
            cancelAnimationFrame(state.legendFrame);
        }
        state.legendFrame = requestAnimationFrame(function () {
            state.legendFrame = null;
            alignLegendRight();
        });
    }

    function updateStatisticsLayout()
    {
        const height = statistics.childElementCount > 0
            ? Math.ceil(statistics.getBoundingClientRect().height)
            : 0;
        root.style.setProperty('--nav-statistics-height', height + 'px');
        scheduleLegendAlignment();
    }

    function scheduleStatisticsLayout()
    {
        if (state.statisticsFrame !== null) {
            cancelAnimationFrame(state.statisticsFrame);
        }
        state.statisticsFrame = requestAnimationFrame(function () {
            state.statisticsFrame = null;
            updateStatisticsLayout();
        });
    }

    function updateMowerTimeLabel()
    {
        const label = state.svg
            ? state.svg.querySelector('.mower-time-label[data-position-received-at]')
            : null;
        if (!label) {
            return;
        }
        const receivedAt = Number(label.dataset.positionReceivedAt);
        const ageSeconds = Math.floor(Date.now() / 1000) - receivedAt;
        if (!Number.isFinite(receivedAt) || receivedAt <= 0 || ageSeconds <= 60) {
            label.textContent = '';
            label.removeAttribute('data-visible');
            label.removeAttribute('aria-label');
            return;
        }
        const formatted = positionTimeFormatter.format(
            new Date(receivedAt * 1000)
        );
        label.textContent = formatted;
        label.dataset.visible = 'true';
        label.setAttribute('aria-label', 'Letzte Position ' + formatted);
    }

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
        scheduleLegendAlignment();
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
            return 'Stand –';
        }
        if (zone.recencyDays === 0) {
            return 'Stand heute';
        }
        return 'Stand ' + zone.recencyDays + ' T.';
    }

    function zonePresentation(zone, index)
    {
        const zoneId = zone.zoneId === undefined || zone.zoneId === null
            ? ''
            : String(zone.zoneId);
        const element = state.svg && zoneId !== ''
            ? state.svg.querySelector(
                '.zone[data-zone-id="' + CSS.escape(zoneId) + '"]'
            )
            : null;
        if (!element) {
            return {
                zone,
                index,
                centerX: Number.POSITIVE_INFINITY,
                color: '',
                label: zone.label,
            };
        }
        const box = element.getBBox();
        const title = element.querySelector('title');
        return {
            zone,
            index,
            centerX: box.x + box.width / 2,
            color: element.getAttribute('stroke') || '',
            label: title ? title.textContent.split(';', 1)[0] : zone.label,
        };
    }

    function compactArea(value)
    {
        if (!Number.isFinite(value)) {
            return '–';
        }
        const hectares = value >= 999.5;
        return new Intl.NumberFormat('de-DE', {
            maximumFractionDigits: hectares ? (value >= 10000 ? 1 : 2) : 0,
        }).format(hectares ? value / 10000 : value) + (hectares ? ' ha' : ' m²');
    }

    function detailsArea(value)
    {
        return Number.isFinite(value)
            ? new Intl.NumberFormat('de-DE', {maximumFractionDigits : 2})
                .format(value) + ' m²'
            : 'Nicht ermittelbar';
    }

    function showStatisticsDetails(entry)
    {
        const zone = entry.zone;
        state.detailsZoneId = String(zone.zoneId);
        detailsTitle.textContent = entry.label;
        const rows = [
            ['Status', zone.passIsCurrent === true ? 'Aktueller Mählauf' : 'Zuletzt erfasster Stand'],
            ['Beobachtet', Number.isFinite(zone.passObservedAt)
                ? new Date(zone.passObservedAt * 1000).toLocaleString('de-DE') : 'Nicht ermittelbar'],
            ['Passfortschritt · gemeldet', Number.isFinite(zone.passProgressPercent)
                ? formatPercent(zone.passProgressPercent) : 'Nicht ermittelbar'],
            ['Passfläche · gemeldet', detailsArea(zone.observedArea)],
            ['Abdeckung · geschätzt', Number.isFinite(zone.passCoveragePercent)
                ? '≈ ' + formatPercent(zone.passCoveragePercent) : 'Nicht ermittelbar'],
            ['Mähfläche Woche · geschätzt', Number.isFinite(zone.weekEstimatedArea)
                ? '≈ ' + detailsArea(zone.weekEstimatedArea) : 'Nicht ermittelbar'],
        ];
        detailsValues.replaceChildren();
        rows.forEach(function (values) {
            const term = document.createElement('dt');
            const value = document.createElement('dd');
            term.textContent = values[0];
            value.textContent = values[1];
            detailsValues.append(term, value);
        });
        if (!details.open) {
            details.showModal();
        }
    }

    function renderStatistics(zones)
    {
        statistics.replaceChildren();
        const entries = Array.isArray(zones) ? zones : [];
        let selectedEntry = null;
        entries.map(zonePresentation)
            .sort(function (left, right) {
                return left.centerX - right.centerX || left.index - right.index;
            })
            .forEach(function (entry) {
                const zone = entry.zone;
                const row = document.createElement('button');
                row.type = 'button';
                row.className = 'nav-map__zone-stat';
                row.dataset.zoneId = String(zone.zoneId === undefined || zone.zoneId === null ? '' : zone.zoneId);
                row.dataset.recency = String(zone.recencyState || 0);
                row.setAttribute('aria-haspopup', 'dialog');
                row.setAttribute('aria-label', entry.label + ' · Statistikdetails');
                if (entry.color !== '') {
                    row.style.setProperty('--nav-zone-color', entry.color);
                }
                const date = Number.isFinite(zone.passObservedAt)
                    ? new Date(zone.passObservedAt * 1000).toLocaleDateString(
                        'de-DE',
                        {day: '2-digit', month: '2-digit'}
                    ) : '–';
                const title = document.createElement('strong');
                title.textContent = entry.label + ' · '
                    + (zone.passIsCurrent === true ? 'aktuell' : date);
                title.title = title.textContent;
                const pass = document.createElement('span');
                pass.className = 'nav-map__status-line';
                pass.textContent = (Number.isFinite(zone.passProgressPercent)
                    ? formatPercent(zone.passProgressPercent) : '–')
                    + ' · ' + compactArea(zone.observedArea);
                const week = document.createElement('span');
                week.className = 'nav-map__status-line';
                week.textContent = 'Woche ' + (Number.isFinite(zone.weekEstimatedArea)
                    ? '≈ ' + compactArea(zone.weekEstimatedArea) : '–');
                row.append(title, pass, week);
                row.addEventListener('click', function () {
                    showStatisticsDetails(entry);
                });
                statistics.append(row);
                if (row.dataset.zoneId === state.detailsZoneId) {
                    selectedEntry = entry;
                }
            });
        if (details.open) {
            if (selectedEntry) {
                showStatisticsDetails(selectedEntry);
            } else {
                details.close();
            }
        }
        scheduleStatisticsLayout();
    }

    details.querySelector('[data-details-close]').addEventListener('click', function () {
        details.close();
    });
    details.addEventListener('click', function (event) {
        const rect = details.getBoundingClientRect();
        if (event.target === details && (event.clientX < rect.left
            || event.clientX > rect.right || event.clientY < rect.top
            || event.clientY > rect.bottom)) {
            details.close();
        }
    });
    details.addEventListener('close', function () {
        const button = Array.from(statistics.children).find(function (row) {
            return row.dataset.zoneId === state.detailsZoneId;
        });
        state.detailsZoneId = null;
        if (button) {
            button.focus({preventScroll: true});
        }
    });

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
        renderStatistics(
            Array.isArray(payload.statistics)
                ? payload.statistics
                : (payload.analytics && Array.isArray(payload.analytics.zones)
                    ? payload.analytics.zones
                    : [])
        );
        root.dataset.theme = payload.theme === 'light' ? 'light' : 'dark';
        status.textContent = payload.status === 'stale'
            ? 'Position veraltet'
            : '';
        if (state.following) {
            focusMower();
        }
        updateMowerTimeLabel();
        scheduleLegendAlignment();
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
                details.close();
                stage.replaceChildren();
                statistics.replaceChildren();
                scheduleStatisticsLayout();
                status.textContent = payload.message || 'Karte nicht verfügbar';
                return;
            }
        } catch (error) {
            details.close();
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
    window.handleMessage = handleMessage;
    window.addEventListener('resize', function () {
        scheduleStatisticsLayout();
        scheduleLegendAlignment();
    });
    if (document.fonts) {
        document.fonts.ready.then(scheduleStatisticsLayout);
        document.fonts.addEventListener('loadingdone', scheduleStatisticsLayout);
    }
    if (typeof ResizeObserver === 'function') {
        const statisticsObserver = new ResizeObserver(scheduleStatisticsLayout);
        statisticsObserver.observe(statistics);
    }
    window.setInterval(updateMowerTimeLabel, 15000);
}());
