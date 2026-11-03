<script>
// Search and paging for long lists of cards (<article>) in the evaluation pages: 10 at a time
(function () {
    const items = Array.from(document.querySelectorAll('main article'));
    if (items.length <= 10) return;
    const PER = 10;
    const list = items[0].parentElement;
    let page = 1, matches = items;

    const bar = document.createElement('div');
    bar.className = 'flex flex-col sm:flex-row sm:items-center gap-3 mb-4';
    bar.innerHTML = '<input type="search" placeholder="Search name, student no. or scholarship..." aria-label="Search this list" class="flex-1 bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium outline-none focus:border-blue-500">'
        + '<p class="text-[11px] font-black text-slate-400 uppercase tracking-widest whitespace-nowrap"></p>';
    list.before(bar);
    const pager = document.createElement('nav');
    pager.setAttribute('aria-label', 'Pages');
    pager.className = 'flex flex-wrap justify-center gap-1.5 mt-5';
    list.after(pager);
    const info = bar.querySelector('p');

    function button(label, target, active, disabled) {
        const b = document.createElement('button');
        b.type = 'button'; b.textContent = label; b.disabled = disabled;
        b.className = 'min-w-9 h-9 px-3 rounded-lg text-xs font-black ' + (active ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100 disabled:opacity-40');
        b.onclick = () => { page = target; render(); window.scrollTo(0, 0); };
        return b;
    }
    function render() {
        const pages = Math.max(1, Math.ceil(matches.length / PER));
        page = Math.min(page, pages);
        const start = (page - 1) * PER, shown = matches.slice(start, start + PER);
        items.forEach(a => a.style.display = 'none');
        shown.forEach(a => a.style.display = '');
        info.textContent = matches.length ? 'Showing ' + (start + 1) + '–' + (start + shown.length) + ' of ' + matches.length : 'No matches';
        pager.innerHTML = '';
        if (pages < 2) return;
        pager.appendChild(button('←', page - 1, false, page === 1));
        for (let i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || Math.abs(i - page) <= 1) pager.appendChild(button(String(i), i, i === page, false));
            else if (Math.abs(i - page) === 2) { const s = document.createElement('span'); s.textContent = '…'; s.className = 'px-1 text-slate-400 self-center'; pager.appendChild(s); }
        }
        pager.appendChild(button('→', page + 1, false, page === pages));
    }
    bar.querySelector('input').addEventListener('input', e => {
        const q = e.target.value.trim().toLowerCase();
        matches = items.filter(a => !q || a.textContent.toLowerCase().includes(q));
        page = 1; render();
    });
    render();
})();
</script>
