/**
 * WC Meilisearch – Lightbox Modal JS
 *
 * Replaces the old dropdown widget.
 */
(function () {
  "use strict";

  if (typeof wcmSearch === "undefined") return;

  const CONFIG = {
    url: wcmSearch.ajaxUrl,
    minChars: wcmSearch.minChars || 2,
    debounce: wcmSearch.debounce || 150,
  };

  function debounce(fn, delay) {
    let timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  function formatPrice(price) {
    if (!price && price !== 0) return "";
    return (
      "S/. " +
      new Intl.NumberFormat("es-PE", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(price)
    );
  }

  function esc(str) {
    const d = document.createElement("div");
    d.textContent = str || "";
    return d.innerHTML;
  }

  // --- Local Storage for Recent Searches ---
  function getRecentSearches() {
    try {
      return JSON.parse(localStorage.getItem("wcm_recent_searches") || "[]");
    } catch (e) {
      return [];
    }
  }

  function addRecentSearch(query) {
    let recents = getRecentSearches();
    recents = recents.filter((q) => q.toLowerCase() !== query.toLowerCase());
    recents.unshift(query);
    if (recents.length > 5) recents.pop();
    localStorage.setItem("wcm_recent_searches", JSON.stringify(recents));
  }

  function clearRecentSearches() {
    localStorage.removeItem("wcm_recent_searches");
    renderRecentSearches();
  }

  // --- Initialization ---
  document.addEventListener("DOMContentLoaded", () => {
    const trigger = document.getElementById("wcm-header-trigger");
    const overlay = document.getElementById("wcm-lightbox-overlay");
    const modal = document.getElementById("wcm-lightbox-modal");
    const closeBtn = document.getElementById("wcm-modal-close");
    const input = document.getElementById("wcm-header-input");

    // Views
    const initialView = document.getElementById("wcm-initial-view");
    const resultsView = document.getElementById("wcm-results-view");
    const resultsGrid = document.getElementById("wcm-search-results");
    const resultsCount = document.getElementById("wcm-results-count");
    const viewAllLink = document.getElementById("wcm-view-all-link");

    // Recents
    const recentSection = document.getElementById(
      "wcm-recent-searches-section",
    );
    const recentTagsObj = document.getElementById("wcm-recent-tags");
    const clearRecentBtn = document.getElementById("wcm-clear-recent");

    // Chips (Filters)
    const chips = document.querySelectorAll(".wcm-chip");
    let currentCategoryFilter = "";

    let activeIndex = -1;
    let currentResults = [];
    let abortController = null;

    if (!overlay || !input) return; // Not initialized on page

    // --- View Toggling ---
    function showInitialView() {
      initialView.style.display = "block";
      resultsView.style.display = "none";
      renderRecentSearches();
      // Keep selected category visually
    }

    function showResultsView() {
      initialView.style.display = "none";
      resultsView.style.display = "block";
    }

    // --- Render Recent Searches ---
    function renderRecentSearches() {
      const recents = getRecentSearches();
      if (recents.length === 0) {
        recentSection.style.display = "none";
      } else {
        recentSection.style.display = "block";
        recentTagsObj.innerHTML = "";
        recents.forEach((q) => {
          const btn = document.createElement("button");
          btn.className = "wcm-tag";
          btn.textContent = q;
          btn.addEventListener("click", () => {
            input.value = q;
            fetchResults(q, currentCategoryFilter);
          });
          recentTagsObj.appendChild(btn);
        });
      }
    }

    if (clearRecentBtn) {
      clearRecentBtn.addEventListener("click", clearRecentSearches);
    }

    // Popular tags click
    document
      .querySelectorAll("#wcm-initial-view .wcm-tag[data-query]")
      .forEach((btn) => {
        btn.addEventListener("click", (e) => {
          const q = e.target.dataset.query;
          input.value = q;
          fetchResults(q, currentCategoryFilter);
        });
      });

    // --- Chips (Category Filters) ---
    chips.forEach((chip) => {
      chip.addEventListener("click", (e) => {
        chips.forEach((c) => c.classList.remove("active"));
        chip.classList.add("active");

        const filterValue = chip.dataset.filter;
        currentCategoryFilter = filterValue;

        // If there's text, re-search with the new filter
        // If empty, we can just show initial view, or do an empty search with filter
        const val = input.value.trim();
        if (val.length >= CONFIG.minChars || filterValue) {
          fetchResults(val, filterValue);
        } else {
          showInitialView();
        }
      });
    });

    // --- Helper to build query parameters ---
    function buildQueryUrl(query, category) {
      let url = `${CONFIG.url}?limit=20`;

      // If we only have a category but no query text, we can search for '*' (Meilisearch handles empty as all docs)
      // Note: The backend ajax-search currently requires a 'q' >= 2 chars.
      // If we want to allow empty queries just by category, we need to modify ajax-search.php.
      // For now, if q is empty, let's append it anyway, but we'll append category as well.

      url += `&q=${encodeURIComponent(query)}`;

      if (category) {
        // To filter by facet in Meilisearch via REST API, normally one uses the `filter` body param.
        // However, since we use GET, we'll send it as a custom parameter 'cat' that the backend will need to parse.
        url += `&cat=${encodeURIComponent(category)}`;
      }
      return url;
    }

    // --- Render Results ---
    function render(data, q) {
      currentResults = data.results || [];
      activeIndex = -1;
      resultsGrid.innerHTML = "";

      if (!currentResults.length) {
        resultsCount.textContent = "0 resultados";
        const empty = document.createElement("div");
        empty.style.padding = "20px";
        empty.style.color = "#888";
        empty.textContent =
          "No se encontraron productos que coincidan con la búsqueda.";
        resultsGrid.appendChild(empty);
      } else {
        resultsCount.textContent = `${currentResults.length} resultados`;
        viewAllLink.href =
          "/?s=" +
          encodeURIComponent(q) +
          (currentCategoryFilter
            ? "&cat=" + encodeURIComponent(currentCategoryFilter)
            : "");

        currentResults.forEach((product, idx) => {
          const a = document.createElement("a");
          a.className = "wcm-product-card";
          a.href = product.url;
          a.dataset.idx = idx;

          const inStock = product.in_stock !== false;

          // Recreate the layout from the design
          const categoryText =
            product.categories && product.categories[0]
              ? product.categories[0]
              : "Vino";

          a.innerHTML = `
            <div class="wcm-product-image-wrap">
                ${product.image ? `<img src="${esc(product.image)}" alt="" loading="lazy">` : ""}
            </div>
            <div class="wcm-product-info">
                <div class="wcm-product-title">${esc(product.name)}</div>
                <div class="wcm-product-price-row">
                    <span class="wcm-product-price">${esc(formatPrice(product.price))}</span>
                </div>
                <div class="wcm-product-meta">
                    <span>${esc(categoryText)}</span>
                    ${!inStock ? `<span class="wcm-product-meta-dot">•</span> <span style="color:#e53935">Sin stock</span>` : ""}
                </div>
            </div>
          `;

          resultsGrid.appendChild(a);
        });

        if (q && q.trim().length >= CONFIG.minChars) {
          addRecentSearch(q.trim());
        }
      }

      showResultsView();
    }

    // --- Fetch Logic ---
    const fetchResults = debounce(async function (query, category) {
      // If no query and no category, go home
      if (query.length < CONFIG.minChars && !category) {
        showInitialView();
        return;
      }

      if (abortController) abortController.abort();
      abortController = new AbortController();

      try {
        const url = buildQueryUrl(query, category);
        const resp = await fetch(url, { signal: abortController.signal });
        if (!resp.ok) return;
        const data = await resp.json();
        render(data, query);
      } catch (err) {
        if (err.name !== "AbortError") console.warn("[wcm]", err);
      }
    }, CONFIG.debounce);

    // --- Lightbox Open / Close ---
    function openLightbox() {
      overlay.style.display = "flex";
      renderRecentSearches();
      // Force reflow
      void overlay.offsetWidth;
      overlay.classList.add("wcm-open");
      setTimeout(() => input.focus(), 50);
      document.body.style.overflow = "hidden"; // prevent scrolling
    }

    function closeLightbox() {
      overlay.classList.remove("wcm-open");
      setTimeout(() => {
        overlay.style.display = "none";
        document.body.style.overflow = "";
      }, 200);
    }

    if (trigger) {
      trigger.addEventListener("click", openLightbox);
    }
    if (closeBtn) {
      closeBtn.addEventListener("click", closeLightbox);
    }
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) closeLightbox();
    });

    // Support physical search icon in some themes
    const existingSearchToggles = document.querySelectorAll(
      '.search-toggle, [href="#search"]',
    );
    existingSearchToggles.forEach((el) => {
      el.addEventListener("click", (e) => {
        e.preventDefault();
        openLightbox();
      });
    });

    // --- Keyboard & Input Listeners ---
    input.addEventListener("input", (e) => {
      const val = e.target.value.trim();
      if (val === "" && !currentCategoryFilter) {
        showInitialView();
      } else {
        fetchResults(val, currentCategoryFilter);
      }
    });

    function setActive(idx) {
      const items = resultsGrid.querySelectorAll(".wcm-product-card");
      items.forEach((el) => el.classList.remove("wcm-active"));
      if (idx >= 0 && idx < items.length) {
        items[idx].classList.add("wcm-active");
        items[idx].scrollIntoView({ block: "nearest", behavior: "smooth" });
        activeIndex = idx;
      } else {
        activeIndex = -1;
      }
    }

    window.addEventListener("keydown", (e) => {
      if (!overlay.classList.contains("wcm-open")) return;

      if (e.key === "Escape") {
        closeLightbox();
        return;
      }

      if (resultsView.style.display === "block") {
        const items = resultsGrid.querySelectorAll(".wcm-product-card");

        if (e.key === "ArrowDown") {
          e.preventDefault();
          setActive(Math.min(activeIndex + 1, items.length - 1));
        } else if (e.key === "ArrowUp") {
          e.preventDefault();
          setActive(Math.max(activeIndex - 1, -1));
          if (activeIndex === -1) input.focus();
        } else if (e.key === "Enter") {
          e.preventDefault();
          if (activeIndex >= 0) {
            const el = items[activeIndex];
            if (el) window.location.href = el.href;
          } else {
            const q = input.value.trim();
            if (q.length >= CONFIG.minChars || currentCategoryFilter) {
              window.location.href =
                "/?s=" +
                encodeURIComponent(q) +
                (currentCategoryFilter
                  ? "&cat=" + encodeURIComponent(currentCategoryFilter)
                  : "");
            }
          }
        }
      }
    });
  });
})();
