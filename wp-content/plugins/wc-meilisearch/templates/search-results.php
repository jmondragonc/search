<?php
/**
 * Custom search results page template.
 *
 * Replaces the theme's search.php via the template_include filter.
 * Results and filters are managed client-side by assets/search-results.js.
 */
defined( 'ABSPATH' ) || exit;

get_header();

if ( ! did_action( 'wp_body_open' ) ) {
    wcm_render_header_searchbar();
}

$query = get_search_query();
?>

<style>
  /* ── Page layout ───────────────────────────────────────────────────── */
  #wcm-search-page {
    max-width: 1280px;
    margin: 0 auto;
    padding: 24px 20px 60px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  }
  #wcm-search-header { margin-bottom: 20px; }
  .wcm-search-heading {
    font-size: 22px; font-weight: 600; color: #222; margin: 0 0 4px;
  }
  .wcm-search-heading em { font-style: normal; color: #8b0000; }
  #wcm-search-count { color: #888; font-size: 13px; margin: 0 0 12px; min-height: 18px; }

  /* Active filter chips */
  #wcm-active-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 4px; min-height: 8px; }
  .wcm-active-chip {
    display: inline-flex; align-items: center; gap: 4px;
    background: #8b0000; color: #fff; border-radius: 20px;
    padding: 4px 12px; font-size: 12px; cursor: pointer;
    transition: background .15s;
  }
  .wcm-active-chip:hover { background: #6b0000; }

  /* ── Layout: sidebar + content ─────────────────────────────────────── */
  #wcm-search-layout {
    display: flex; gap: 28px; align-items: flex-start;
  }

  /* ── Sidebar ───────────────────────────────────────────────────────── */
  #wcm-filters-sidebar {
    width: 240px; flex-shrink: 0;
    background: #fff; border: 1px solid #e8e8e8;
    border-radius: 10px; padding: 20px;
  }
  .wcm-sidebar-title {
    font-size: 14px; font-weight: 700; color: #222;
    margin: 0 0 16px; padding-bottom: 12px;
    border-bottom: 1px solid #f0f0f0; text-transform: uppercase; letter-spacing: .5px;
  }
  .wcm-filter-block { margin-bottom: 20px; }
  .wcm-filter-block:last-child { margin-bottom: 0; }
  .wcm-filter-title {
    font-size: 13px; font-weight: 600; color: #444;
    margin: 0 0 10px;
  }
  .wcm-filter-list { display: flex; flex-direction: column; gap: 6px; max-height: 260px; overflow-y: auto; }
  .wcm-filter-option {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; color: #333; cursor: pointer;
    padding: 3px 0;
  }
  .wcm-filter-option input[type="checkbox"] { accent-color: #8b0000; cursor: pointer; flex-shrink: 0; }
  .wcm-filter-option span { flex: 1; }
  .wcm-filter-option em { color: #aaa; font-style: normal; font-size: 12px; }

  /* Stock toggle */
  .wcm-filter-toggle-row {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; color: #333; cursor: pointer; padding: 3px 0;
  }
  .wcm-filter-toggle-row input[type="checkbox"] { accent-color: #8b0000; cursor: pointer; }

  .wcm-clear-btn {
    display: block; width: 100%; padding: 7px 0; background: transparent;
    color: #888; border: 1px solid #ddd; border-radius: 6px;
    font-size: 12px; cursor: pointer; margin-top: 16px; transition: all .15s;
  }
  .wcm-clear-btn:hover { border-color: #8b0000; color: #8b0000; }

  /* ── Main content ──────────────────────────────────────────────────── */
  #wcm-search-main { flex: 1; min-width: 0; }

  /* ── Product grid ──────────────────────────────────────────────────── */
  #wcm-search-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 20px;
  }
  .wcm-card {
    display: flex; flex-direction: column;
    text-decoration: none; border: 1px solid #e0e0e0;
    border-radius: 8px; overflow: hidden; background: #fff;
    transition: box-shadow .2s, transform .15s; color: inherit;
  }
  .wcm-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.12); transform: translateY(-2px); }
  .wcm-card img {
    width: 100%; aspect-ratio: 1 / 1; object-fit: contain;
    padding: 12px; background: #fafafa;
  }
  .wcm-card-noimg { width: 100%; aspect-ratio: 1 / 1; background: #f0f0f0; }
  .wcm-card-body { padding: 10px 12px 14px; display: flex; flex-direction: column; gap: 4px; flex: 1; }
  .wcm-card-name {
    font-size: 13px; color: #333; line-height: 1.4; flex: 1;
    display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
  }
  .wcm-card-price { font-size: 15px; font-weight: 700; color: #8b0000; margin-top: 6px; }
  .wcm-card-oos { font-size: 11px; color: #aaa; text-transform: uppercase; letter-spacing: .3px; }
  .wcm-search-msg { grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: #888; font-size: 16px; }

  /* ── Mobile filter toggle button ───────────────────────────────────── */
  #wcm-filter-toggle {
    display: none; position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
    background: #8b0000; color: #fff; border: none; border-radius: 24px;
    padding: 12px 28px; font-size: 14px; font-weight: 600;
    box-shadow: 0 4px 16px rgba(0,0,0,.25); cursor: pointer; z-index: 1000;
    transition: background .15s;
  }
  #wcm-filter-toggle:hover { background: #6b0000; }

  /* Mobile overlay backdrop */
  #wcm-filter-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 1001;
  }
  #wcm-filter-overlay.wcm-overlay-open { display: block; }

  /* ── Responsive ────────────────────────────────────────────────────── */
  @media (max-width: 768px) {
    #wcm-search-layout { display: block; }

    #wcm-filters-sidebar {
      display: none; position: fixed; bottom: 0; left: 0; right: 0;
      max-height: 75vh; overflow-y: auto; z-index: 1002;
      border-radius: 16px 16px 0 0; border: none;
      box-shadow: 0 -4px 24px rgba(0,0,0,.18);
    }
    #wcm-filters-sidebar.wcm-sidebar-open { display: block; }

    #wcm-filter-toggle { display: block; }

    #wcm-search-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }

    .wcm-search-heading { font-size: 18px; }
  }

  /* Loading state */
  #wcm-search-grid.wcm-loading { opacity: .5; pointer-events: none; }
</style>

<div id="wcm-search-page">

  <div id="wcm-search-header">
    <h1 class="wcm-search-heading">
      Resultados para: <em><?php echo esc_html( $query ); ?></em>
    </h1>
    <p id="wcm-search-count"></p>
    <div id="wcm-active-chips"></div>
  </div>

  <div id="wcm-search-layout">

    <aside id="wcm-filters-sidebar">
      <p class="wcm-sidebar-title">Filtrar por</p>
      <p style="color:#aaa;font-size:13px;">Cargando filtros…</p>
    </aside>

    <main id="wcm-search-main">
      <div id="wcm-search-grid">
        <p class="wcm-search-msg">Buscando…</p>
      </div>
    </main>

  </div>

</div>

<!-- Mobile filter button + overlay -->
<button id="wcm-filter-toggle">Filtros</button>
<div id="wcm-filter-overlay"></div>

<?php get_footer(); ?>
