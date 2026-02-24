<?php
/**
 * Custom search results page template.
 *
 * Replaces the theme's search.php via the template_include filter.
 * Results are fetched client-side from ajax-search.php and rendered
 * into #wcm-search-grid by assets/search-results.js.
 */
defined( 'ABSPATH' ) || exit;

get_header();

$query = get_search_query();
?>

<style>
  #wcm-search-page {
    max-width: 1200px;
    margin: 30px auto;
    padding: 0 20px 60px;
  }
  .wcm-search-heading {
    font-size: 22px;
    margin-bottom: 6px;
    font-weight: 600;
    color: #222;
  }
  .wcm-search-heading em {
    font-style: normal;
    color: #0073aa;
  }
  #wcm-search-count {
    color: #888;
    font-size: 13px;
    margin-bottom: 24px;
    min-height: 20px;
  }
  #wcm-search-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 20px;
  }
  .wcm-card {
    display: flex;
    flex-direction: column;
    text-decoration: none;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
    transition: box-shadow .2s, transform .15s;
    color: inherit;
  }
  .wcm-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,.12);
    transform: translateY(-2px);
  }
  .wcm-card img {
    width: 100%;
    aspect-ratio: 1 / 1;
    object-fit: contain;
    padding: 12px;
    background: #fafafa;
  }
  .wcm-card-noimg {
    width: 100%;
    aspect-ratio: 1 / 1;
    background: #f0f0f0;
  }
  .wcm-card-body {
    padding: 10px 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
  }
  .wcm-card-name {
    font-size: 13px;
    color: #333;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    flex: 1;
  }
  .wcm-card-price {
    font-size: 15px;
    font-weight: 700;
    color: #0073aa;
    margin-top: 6px;
  }
  .wcm-card-oos {
    font-size: 11px;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: .3px;
  }
  .wcm-search-msg {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px 20px;
    color: #888;
    font-size: 16px;
  }
  @media (max-width: 600px) {
    #wcm-search-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
  }
</style>

<div id="wcm-search-page">
  <h1 class="wcm-search-heading">
    Resultados para: <em><?php echo esc_html( $query ); ?></em>
  </h1>
  <p id="wcm-search-count"></p>
  <div id="wcm-search-grid">
    <p class="wcm-search-msg">Buscando…</p>
  </div>
</div>

<?php get_footer(); ?>
