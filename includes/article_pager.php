<script>
// Search, filters and "Show 5 / 10 / 25 / 50" for long lists of cards (<article>) in the evaluation pages.
// A card joins a filter with data-filter-NAME="value"; the filter appears when it has at least two values.
// The choices survive the page reload after saving a card, so a filtered queue can be worked through.
(function () {
    const items = Array.from(document.querySelectorAll('main article'));
    if (items.length <= 5) return;
    const LABELS = { stage: 'All stages', status: 'All application statuses', course: 'All courses', review: 'All review statuses', result: 'All AI results', document: 'All documents', resolution: 'All resolutions', scholarship: 'All scholarships', waitlist: 'All waitlist states', slots: 'All slot states' };
    const SIZES = [5, 10, 25, 50];
    const STATE_KEY = 'scholarlink_list_' + location.pathname, SIZE_KEY = 'scholarlink_list_size';
    const read = (store, key) => { try { return store.getItem(key); } catch (e) { return null; } };
    const write = (store, key, value) => { try { store.setItem(key, value); } catch (e) {} };

    let saved = {};
    try { saved = JSON.parse(read(sessionStorage, STATE_KEY)) || {}; } catch (e) {}
    let per = parseInt(read(localStorage, SIZE_KEY), 10);
    if (!SIZES.includes(per)) per = 10;
    let page = saved.page || 1, matches = items;

    const CONTROL = 'bg-white border border-slate-200 rounded-xl px-3 py-2.5 text-sm font-bold text-slate-700 outline-none focus:border-blue-500';
    const list = items[0].parentElement;
    const bar = document.createElement('div');
    bar.className = 'flex flex-wrap items-center gap-2.5 mb-4';
    const search = document.createElement('input');
    search.type = 'search'; search.placeholder = 'Search name, student no. or scholarship...'; search.setAttribute('aria-label', 'Search this list');
    search.className = CONTROL + ' basis-full min-w-0 font-medium';
    search.value = saved.search || '';
    bar.appendChild(search);

    // One dropdown per data-filter-* value on the cards, each with its count
    const filters = [];
    new Set(items.flatMap(a => Object.keys(a.dataset).filter(k => k.startsWith('filter')))).forEach(key => {
        const counts = {};
        items.forEach(a => { const v = a.dataset[key]; if (v) counts[v] = (counts[v] || 0) + 1; });
        const values = Object.keys(counts).sort();
        if (values.length < 2) return;
        const name = key.slice(6).toLowerCase(), select = document.createElement('select');
        select.className = CONTROL + ' flex-1 sm:flex-none min-w-[9rem] sm:max-w-[16rem] cursor-pointer';
        select.setAttribute('aria-label', LABELS[name] || name);
        select.add(new Option(LABELS[name] || 'All', ''));
        values.forEach(v => select.add(new Option(v + ' (' + counts[v] + ')', v)));
        if (saved.filters && counts[saved.filters[key]]) select.value = saved.filters[key];
        filters.push({ key, select });
        bar.appendChild(select);
    });

    const size = document.createElement('select');
    size.className = CONTROL + ' cursor-pointer'; size.setAttribute('aria-label', 'Cards per page');
    SIZES.forEach(n => size.add(new Option('Show ' + n, n)));
    size.value = String(per);
    bar.appendChild(size);

    const reset = document.createElement('button');
    reset.type = 'button'; reset.textContent = 'Reset';
    reset.className = 'px-3 py-2.5 rounded-xl text-xs font-black text-slate-500 hover:bg-slate-100';
    bar.appendChild(reset);

    const info = document.createElement('p');
    info.className = 'text-[11px] font-black text-slate-400 uppercase tracking-widest whitespace-nowrap ml-auto';
    bar.appendChild(info);
    list.before(bar);

    const pager = document.createElement('nav');
    pager.setAttribute('aria-label', 'Pages');
    pager.className = 'flex flex-wrap justify-center gap-1.5 mt-5';
    list.after(pager);

    function button(label, target, active, disabled) {
        const b = document.createElement('button');
        b.type = 'button'; b.textContent = label; b.disabled = disabled;
        b.className = 'min-w-9 h-9 px-3 rounded-lg text-xs font-black ' + (active ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100 disabled:opacity-40');
        b.onclick = () => { page = target; render(); window.scrollTo(0, 0); };
        return b;
    }
    function applyFilters() {
        const q = search.value.trim().toLowerCase();
        matches = items.filter(a => (!q || a.textContent.toLowerCase().includes(q)) && filters.every(f => !f.select.value || a.dataset[f.key] === f.select.value));
        reset.style.display = q || filters.some(f => f.select.value) ? '' : 'none';
    }
    function render() {
        const pages = Math.max(1, Math.ceil(matches.length / per));
        page = Math.min(page, pages);
        const start = (page - 1) * per, shown = matches.slice(start, start + per);
        items.forEach(a => a.style.display = 'none');
        shown.forEach(a => a.style.display = '');
        info.textContent = matches.length ? 'Showing ' + (start + 1) + '–' + (start + shown.length) + ' of ' + matches.length : 'No matches';
        write(sessionStorage, STATE_KEY, JSON.stringify({ search: search.value, page, filters: Object.fromEntries(filters.map(f => [f.key, f.select.value])) }));
        pager.innerHTML = '';
        if (pages < 2) return;
        pager.appendChild(button('←', page - 1, false, page === 1));
        for (let i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || Math.abs(i - page) <= 1) pager.appendChild(button(String(i), i, i === page, false));
            else if (Math.abs(i - page) === 2) { const s = document.createElement('span'); s.textContent = '…'; s.className = 'px-1 text-slate-400 self-center'; pager.appendChild(s); }
        }
        pager.appendChild(button('→', page + 1, false, page === pages));
    }
    function changed() { page = 1; applyFilters(); render(); }
    search.addEventListener('input', changed);
    filters.forEach(f => f.select.addEventListener('change', changed));
    size.addEventListener('change', () => { per = parseInt(size.value, 10); write(localStorage, SIZE_KEY, size.value); changed(); });
    reset.addEventListener('click', () => { search.value = ''; filters.forEach(f => f.select.value = ''); changed(); });
    applyFilters();
    render();
})();
</script>
