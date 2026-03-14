/**
 * WC Meilisearch – Search results page.
 *
 * Manages state, fetches results + facets, renders grid and filter sidebar.
 * All filtering is done server-side via ajax-search.php.
 *
 * Depends on wcmSearch (injected via wp_localize_script).
 */
(function () {
  'use strict';

  if (typeof wcmSearch === 'undefined') return;

  const urlParams = new URLSearchParams(window.location.search);
  const query     = urlParams.get('s') || urlParams.get('q') || '';

  // DOM refs
  const countEl   = document.getElementById('wcm-search-count');
  const gridEl    = document.getElementById('wcm-search-grid');
  const chipsEl   = document.getElementById('wcm-active-chips');
  const sidebarEl = document.getElementById('wcm-filters-sidebar');
  const toggleBtn = document.getElementById('wcm-filter-toggle');
  const overlayEl = document.getElementById('wcm-filter-overlay');

  if (!gridEl) return;

  if (!query || query.length < 2) {
    gridEl.innerHTML = '<p class="wcm-search-msg">Ingresa al menos 2 caracteres para buscar.</p>';
    if (sidebarEl) sidebarEl.innerHTML = '';
    return;
  }

  // ── Filter state (single-select per group) ───────────────────────────────────
  const state = {
    cats:     '',
    pais:     '',
    region:   '',
    tipo:     '',
    varietal: '',
    marca:    '',
    volumen:  '',
    stock:    false,
  };

  // Attribute filter groups config (order matches the main site sidebar)
  const ATTR_GROUPS = [
    { key: 'cats',     param: 'cats',     facet: 'categories',    label: 'Categorías',         all: 'Todas las Categorías' },
    { key: 'pais',     param: 'pais',     facet: 'attr_pais',     label: 'País',               all: 'Todos los países' },
    { key: 'tipo',     param: 'tipo',     facet: 'attr_tipo',     label: 'Tipo',               all: 'Todos los tipos' },
    { key: 'varietal', param: 'varietal', facet: 'attr_varietal', label: 'Varietal',           all: 'Todos los varietales' },
    { key: 'region',   param: 'region',   facet: 'attr_region',   label: 'Región',             all: 'Todas las regiones' },
    { key: 'marca',    param: 'marca',    facet: 'attr_marca',    label: 'Marca',              all: 'Todas las marcas' },
    { key: 'volumen',  param: 'volumen',  facet: 'attr_volumen',  label: 'Volumen',            all: 'Todos los volúmenes' },
  ];

  // Categories too broad/internal to show.
  const SKIP_CATS = new Set(['Vinos.', 'Vinos y Espumantes', 'Al por mayor', 'Wine Collections']);

  // ── Fetch ────────────────────────────────────────────────────────────────────

  function buildUrl() {
    const u = new URL(wcmSearch.ajaxUrl, location.href);
    u.searchParams.set('q',      query);
    u.searchParams.set('limit',  '96');
    u.searchParams.set('facets', '1');
    ATTR_GROUPS.forEach(g => {
      if (state[g.key]) u.searchParams.set(g.param, state[g.key]);
    });
    if (state.stock) u.searchParams.set('stock', 'true');
    return u.toString();
  }

  // Facets-only URL: no attribute filters → shows full option counts (disjunctive)
  function buildAllFacetsUrl() {
    const u = new URL(wcmSearch.ajaxUrl, location.href);
    u.searchParams.set('q',      query);
    u.searchParams.set('limit',  '1');
    u.searchParams.set('facets', '1');
    if (state.stock) u.searchParams.set('stock', 'true');
    return u.toString();
  }

  function hasAttrFilters() {
    return ATTR_GROUPS.some(g => state[g.key] !== '');
  }

  function doSearch() {
    if (gridEl) gridEl.classList.add('wcm-loading');

    const mainFetch      = fetch(buildUrl()).then(r => r.json());
    const allFacetsFetch = hasAttrFilters()
      ? fetch(buildAllFacetsUrl()).then(r => r.json())
      : null;

    Promise.all([mainFetch, allFacetsFetch || Promise.resolve(null)])
      .then(([data, allData]) => {
        const results = data.results || [];
        const facets  = data.facets  || {};

        if (allData && allData.facets) {
          Object.keys(allData.facets).forEach(k => { facets[k] = allData.facets[k]; });
        }

        renderCount(results.length, data.processingTimeMs, data.cached);
        renderGrid(results);
        renderSidebar(facets);
        renderActiveChips();
        if (gridEl) gridEl.classList.remove('wcm-loading');
      })
      .catch(() => {
        if (gridEl) {
          gridEl.classList.remove('wcm-loading');
          gridEl.innerHTML = '<p class="wcm-search-msg">Error al buscar. Intenta de nuevo.</p>';
        }
      });
  }

  // ── Count ────────────────────────────────────────────────────────────────────
  function renderCount(n, ms, cached) {
    if (!countEl) return;
    countEl.textContent =
      n + ' producto' + (n !== 1 ? 's' : '') + ' · ' + ms + 'ms' +
      (cached ? ' · caché' : '');
  }

  // ── Grid ─────────────────────────────────────────────────────────────────────
  function renderGrid(results) {
    if (!results.length) {
      gridEl.innerHTML = '<p class="wcm-search-msg">No hay productos para los filtros seleccionados.</p>';
      return;
    }
    gridEl.innerHTML = results.map(p => {
      const price = 'S/. ' + new Intl.NumberFormat('es-PE', {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
      }).format(p.price);
      return (
        '<a class="wcm-card" href="' + esc(p.url) + '">' +
          (p.image
            ? '<img src="' + esc(p.image) + '" alt="" loading="lazy">'
            : '<div class="wcm-card-noimg"></div>') +
          '<div class="wcm-card-body">' +
            '<span class="wcm-card-name">' + esc(p.name) + '</span>' +
            '<span class="wcm-card-price">' + price + '</span>' +
            (!p.in_stock ? '<span class="wcm-card-oos">Sin stock</span>' : '') +
          '</div>' +
        '</a>'
      );
    }).join('');
  }

  // ── Sidebar ──────────────────────────────────────────────────────────────────
  function renderSidebar(facets) {
    if (!sidebarEl) return;

    const stockFacets  = facets.in_stock || {};
    const inStockCount = stockFacets['true'] || 0;

    // SVG chevron arrow
    const arrow = '<svg class="wcm-dd-arrow" viewBox="0 0 10 6"><polyline points="1,1 5,5 9,1"/></svg>';

    let html = '<p class="wcm-sidebar-title">Filtrar por</p>';

    // Stock toggle
    html +=
      '<div class="wcm-filter-block">' +
        '<h3 class="wcm-filter-title">Disponibilidad</h3>' +
        '<label class="wcm-filter-toggle-row">' +
          '<input type="checkbox" id="wcm-stock-toggle"' + (state.stock ? ' checked' : '') + '>' +
          '<span>Solo con stock (' + inStockCount + ')</span>' +
        '</label>' +
      '</div>';

    // Dropdown groups
    ATTR_GROUPS.forEach(g => {
      const raw = facets[g.facet] || {};

      let entries = Object.entries(raw);
      if (g.key === 'cats') entries = entries.filter(([n]) => !SKIP_CATS.has(n));
      entries = entries.filter(([n]) => n && n.trim() !== '');
      const sorted = entries.sort((a, b) => b[1] - a[1]).slice(0, 30);
      if (!sorted.length) return;

      const selected = state[g.key];
      const label    = selected ? esc(selected) : esc(g.all);
      const isActive = selected !== '';

      html +=
        '<div class="wcm-filter-block">' +
          '<h3 class="wcm-filter-title">' + esc(g.label) + '</h3>' +
          '<div class="wcm-dd" data-group="' + g.key + '">' +
            '<button class="wcm-dd-trigger' + (isActive ? ' wcm-dd-active' : '') + '" type="button">' +
              '<span>' + label + '</span>' + arrow +
            '</button>' +
            '<div class="wcm-dd-panel">' +
              '<input class="wcm-dd-search" type="text" placeholder="Buscar…" autocomplete="off">' +
              '<div class="wcm-dd-list">' +
                '<div class="wcm-dd-item wcm-dd-item-all" data-val="">' + esc(g.all) + '</div>' +
                sorted.map(([name, count]) =>
                  '<div class="wcm-dd-item' + (name === selected ? ' wcm-dd-selected' : '') + '" data-val="' + esc(name) + '">' +
                    '<span>' + esc(name) + '</span>' +
                    '<em>(' + count + ')</em>' +
                  '</div>'
                ).join('') +
              '</div>' +
            '</div>' +
          '</div>' +
        '</div>';
    });

    if (hasActiveFilters()) {
      html += '<button id="wcm-clear-all" class="wcm-clear-btn">Limpiar todos los filtros</button>';
    }

    sidebarEl.innerHTML = html;
    bindSidebarEvents();
  }

  function bindSidebarEvents() {
    // Stock toggle
    const stockToggle = document.getElementById('wcm-stock-toggle');
    if (stockToggle) {
      stockToggle.addEventListener('change', function () {
        state.stock = this.checked;
        doSearch();
      });
    }

    // Dropdown triggers: open/close panel
    sidebarEl.querySelectorAll('.wcm-dd').forEach(dd => {
      const trigger = dd.querySelector('.wcm-dd-trigger');
      const panel   = dd.querySelector('.wcm-dd-panel');
      const search  = dd.querySelector('.wcm-dd-search');
      const list    = dd.querySelector('.wcm-dd-list');
      const group   = dd.dataset.group;

      trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = dd.classList.contains('open');
        // Close all other dropdowns
        sidebarEl.querySelectorAll('.wcm-dd.open').forEach(o => o.classList.remove('open'));
        if (!isOpen) {
          dd.classList.add('open');
          search.value = '';
          list.querySelectorAll('.wcm-dd-item').forEach(item => { item.style.display = ''; });
          search.focus();
        }
      });

      // Search input filters the list
      search.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        list.querySelectorAll('.wcm-dd-item').forEach(item => {
          if (item.classList.contains('wcm-dd-item-all')) { item.style.display = ''; return; }
          item.style.display = item.dataset.val.toLowerCase().includes(q) ? '' : 'none';
        });
      });

      // Option click
      list.addEventListener('click', function (e) {
        const item = e.target.closest('.wcm-dd-item');
        if (!item) return;
        state[group] = item.dataset.val; // '' = clear
        dd.classList.remove('open');
        doSearch();
      });
    });

    // Click outside closes all dropdowns
    document.addEventListener('click', closeAllDropdowns);

    // Clear all
    const clearBtn = document.getElementById('wcm-clear-all');
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        ATTR_GROUPS.forEach(g => { state[g.key] = ''; });
        state.stock = false;
        doSearch();
      });
    }
  }

  function closeAllDropdowns() {
    if (sidebarEl) sidebarEl.querySelectorAll('.wcm-dd.open').forEach(d => d.classList.remove('open'));
  }

  // ── Active filter chips ──────────────────────────────────────────────────────
  function renderActiveChips() {
    if (!chipsEl) return;
    const chips = [];
    ATTR_GROUPS.forEach(g => {
      if (state[g.key]) {
        chips.push(
          '<span class="wcm-active-chip" data-group="' + esc(g.key) + '">' +
            esc(state[g.key]) + ' ×' +
          '</span>'
        );
      }
    });
    if (state.stock) {
      chips.push('<span class="wcm-active-chip" data-group="stock">Con stock ×</span>');
    }
    chipsEl.innerHTML = chips.join('');
    chipsEl.querySelectorAll('.wcm-active-chip').forEach(chip => {
      chip.addEventListener('click', function () {
        const group = this.dataset.group;
        if (group === 'stock') state.stock = false;
        else state[group] = '';
        doSearch();
      });
    });
  }

  // ── Mobile sidebar toggle ────────────────────────────────────────────────────
  if (toggleBtn && sidebarEl && overlayEl) {
    toggleBtn.addEventListener('click', () => {
      sidebarEl.classList.toggle('wcm-sidebar-open');
      overlayEl.classList.toggle('wcm-overlay-open');
    });
    overlayEl.addEventListener('click', () => {
      sidebarEl.classList.remove('wcm-sidebar-open');
      overlayEl.classList.remove('wcm-overlay-open');
    });
  }

  // ── Helpers ──────────────────────────────────────────────────────────────────
  function hasActiveFilters() {
    return hasAttrFilters() || state.stock;
  }

  function esc(str) {
    const d = document.createElement('div');
    d.textContent = String(str || '');
    return d.innerHTML;
  }

  // ── Init ─────────────────────────────────────────────────────────────────────
  doSearch();

})();
