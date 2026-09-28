/*
 * No stylesheet import here: `base.html.twig` links `styles/app.css` itself,
 * and importing it from the entrypoint too makes `importmap()` emit a second
 * <link> for the same file.
 */
document.querySelectorAll('[data-expandable-table]').forEach((table) => {
    const rows = [...table.querySelectorAll('tbody > tr')];
    const initialRows = Number.parseInt(table.dataset.initialRows, 10);
    const controls = table.querySelector('[data-expandable-controls]');
    const button = table.querySelector('[data-expandable-toggle]');
    const label = table.querySelector('[data-expandable-label]');

    if (!controls || !button || !label || rows.length <= initialRows) {
        return;
    }

    let expanded = false;

    const render = () => {
        rows.slice(initialRows).forEach((row) => {
            row.hidden = !expanded;
        });
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        label.textContent = expanded ? 'Show less' : `Show ${rows.length - initialRows} more`;
    };

    controls.hidden = false;
    button.addEventListener('click', () => {
        expanded = !expanded;
        render();
    });
    render();
});

document.querySelectorAll('[data-match-station]').forEach((station) => {
    const tabs = [...station.querySelectorAll('[data-blade-select]')];
    const panels = [...station.querySelectorAll('[data-score-panel]')];

    if (tabs.length !== panels.length || tabs.length === 0) {
        return;
    }

    const select = (lane) => {
        tabs.forEach((tab) => {
            const selected = tab.dataset.bladeSelect === lane;
            tab.setAttribute('aria-current', selected ? 'true' : 'false');
            const blade = tab.querySelector(':scope > div');
            blade?.classList.toggle('border-brand-strong', selected);
            blade?.classList.toggle('bg-brand-strong/5', selected);
            blade?.classList.toggle('border-line', !selected);
            blade?.classList.toggle('bg-canvas/50', !selected);
        });
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.scorePanel !== lane;
        });
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', (event) => {
            event.preventDefault();
            select(tab.dataset.bladeSelect);
        });
    });
    select(tabs[0].dataset.bladeSelect);
});

document.querySelectorAll('[data-score-form]').forEach((form) => {
    form.addEventListener('change', async (event) => {
        if (!(event.target instanceof HTMLInputElement) || event.target.type !== 'radio') {
            return;
        }

        const submit = form.querySelector('button[type="submit"]');
        submit?.setAttribute('disabled', 'disabled');

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {'X-Requested-With': 'XMLHttpRequest'},
            });
            const payload = await response.json();
            if (!response.ok) {
                throw new Error(payload.message || 'The result could not be saved.');
            }

            const live = document.querySelector('[data-score-live]');
            if (live) {
                live.textContent = payload.message;
            }

            document.querySelectorAll(`[data-blade-total="${payload.lane}"]`).forEach((total) => {
                total.textContent = payload.bladeTotal;
            });

            const updatedTotals = {
                'overall-total': payload.overallTotal,
                'overall-average': payload.overallAverage,
                [`blade-${payload.lane}-average`]: payload.bladeAverage,
                [`blade-${payload.lane}-appearances`]: payload.bladeAppearances,
                [`blade-${payload.lane}-balance`]: payload.bladeBalance,
                [`blade-${payload.lane}-unused`]: payload.bladeUnused,
                [`blade-${payload.lane}-best`]: payload.bladeBest,
            };
            Object.entries(updatedTotals).forEach(([key, value]) => {
                document.querySelectorAll(`[data-kpi="${key}"], [data-total="${key}"]`).forEach((total) => {
                    total.textContent = value;
                });
            });

            const cell = document.querySelector(`[data-result-cell][data-match-number="${payload.match}"][data-lane="${payload.lane}"]`);
            if (cell) {
                cell.textContent = payload.value === 'unused' ? '—' : payload.label;
                cell.setAttribute('aria-label', payload.message);
                cell.dataset.recentlyChanged = '';
                window.setTimeout(() => delete cell.dataset.recentlyChanged, 1800);
            }

            if (payload.reload) {
                window.location.assign(payload.redirect);
            }
        } catch (error) {
            const live = document.querySelector('[data-score-live]');
            if (live) {
                live.textContent = error instanceof Error ? error.message : 'The result could not be saved.';
            }
        } finally {
            submit?.removeAttribute('disabled');
        }
    });
});
