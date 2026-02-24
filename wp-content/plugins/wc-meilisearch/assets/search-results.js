/**
 * WC Meilisearch – Search results page script.
 *
 * Reads ?s= from the URL, fetches results from ajax-search.php (up to 96),
 * and renders a product grid into #wcm-search-grid.
 *
 * Depends on wcmSearch (injected via wp_localize_script on wcm-autocomplete).
 */
(function () {
  'use strict';

  if (typeof wcmSearch === 'undefined') return;

  var params  = new URLSearchParams(window.location.search);
  var query   = params.get('s') || params.get('q') || '';
  var countEl = document.getElementById('wcm-search-count');
  var gridEl  = document.getElementById('wcm-search-grid');

  if (!gridEl) return;

  if (!query || query.length < 2) {
    gridEl.innerHTML = '<p class="wcm-search-msg">Ingresa al menos 2 caracteres para buscar.</p>';
    return;
  }

  // Pre-fill search input in the header bar with the current query.
  var headerInput = document.getElementById('wcm-header-input');
  if (headerInput) headerInput.value = query;

  fetch(wcmSearch.ajaxUrl + '?q=' + encodeURIComponent(query) + '&limit=96')
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var results = data.results || [];

      if (countEl) {
        countEl.textContent =
          results.length + ' producto(s) encontrado(s) · ' + data.processingTimeMs + 'ms' +
          (data.cached ? ' · caché' : '');
      }

      if (!results.length) {
        gridEl.innerHTML =
          '<p class="wcm-search-msg">No se encontraron productos para "<strong>' +
          esc(query) + '</strong>".</p>';
        return;
      }

      gridEl.innerHTML = results.map(function (p) {
        var price = 'S/. ' + new Intl.NumberFormat('es-PE', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
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
    })
    .catch(function () {
      gridEl.innerHTML = '<p class="wcm-search-msg">Error al realizar la búsqueda. Intenta de nuevo.</p>';
    });

  function esc(str) {
    var d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
  }
})();
