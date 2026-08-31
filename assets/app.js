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
