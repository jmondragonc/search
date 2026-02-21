/**
 * WC Meilisearch – Admin page JavaScript.
 *
 * Handles:
 *  - "Probar Conexión" button
 *  - "Reindexar Productos" button with animated progress bar
 */
(function () {
  'use strict';

  if (typeof wcmAdmin === 'undefined') return;

  const { ajaxUrl, nonceReindex, nonceTest, i18n } = wcmAdmin;

  // ---------------------------------------------------------------------------
  // DOM references (populated after DOMContentLoaded)
  // ---------------------------------------------------------------------------
  let btnTest, statusSpan, btnReindex, progressWrap, progressBar, progressText;

  // ---------------------------------------------------------------------------
  // Test connection
  // ---------------------------------------------------------------------------
  async function testConnection() {
    btnTest.disabled = true;
    statusSpan.textContent = '…';
    statusSpan.style.color = '#333';

    try {
      const body = new URLSearchParams({ action: 'wcm_test_connection', nonce: nonceTest });
      const resp = await fetch(ajaxUrl, { method: 'POST', body });
      const data = await resp.json();

      if (data.success) {
        statusSpan.textContent = i18n.connected;
        statusSpan.style.color = '#46b450';
      } else {
        statusSpan.textContent = i18n.notConnected;
        statusSpan.style.color = '#dc3232';
      }
    } catch (err) {
      statusSpan.textContent = i18n.notConnected;
      statusSpan.style.color = '#dc3232';
    } finally {
      btnTest.disabled = false;
    }
  }

  // ---------------------------------------------------------------------------
  // Bulk reindex with progress bar
  // ---------------------------------------------------------------------------
  async function startReindex() {
    btnReindex.disabled = true;
    progressWrap.style.display = 'block';
    progressBar.style.width = '0%';
    progressText.textContent = i18n.reindexing;

    // Step 1: get total product count.
    let total = 0;
    try {
      const body = new URLSearchParams({ action: 'wcm_reindex_count', nonce: nonceReindex });
      const resp = await fetch(ajaxUrl, { method: 'POST', body });
      const data = await resp.json();
      total = data.success ? data.data.total : 0;
    } catch (_) {}

    if (total === 0) {
      progressText.textContent = i18n.done;
      btnReindex.disabled = false;
      return;
    }

    // Step 2: index in batches.
    let indexed = 0;
    let offset  = 0;
    let done    = false;

    while (!done) {
      try {
        const body = new URLSearchParams({
          action: 'wcm_reindex_batch',
          nonce:  nonceReindex,
          offset: offset,
        });
        const resp = await fetch(ajaxUrl, { method: 'POST', body });
        const data = await resp.json();

        if (!data.success) {
          progressText.textContent = i18n.error;
          break;
        }

        indexed += data.data.indexed;
        offset   = data.data.offset;
        done     = data.data.done;

        const pct = total > 0 ? Math.round((indexed / total) * 100) : 100;
        progressBar.style.width = pct + '%';
        progressText.textContent = `${indexed} / ${total} (${pct}%)`;

      } catch (err) {
        progressText.textContent = i18n.error;
        break;
      }
    }

    if (done) {
      progressBar.style.width = '100%';
      progressText.textContent = i18n.done;
    }

    btnReindex.disabled = false;
  }

  // ---------------------------------------------------------------------------
  // Init
  // ---------------------------------------------------------------------------
  document.addEventListener('DOMContentLoaded', function () {
    btnTest      = document.getElementById('wcm-test-connection');
    statusSpan   = document.getElementById('wcm-connection-status');
    btnReindex   = document.getElementById('wcm-start-reindex');
    progressWrap = document.getElementById('wcm-progress-wrap');
    progressBar  = document.getElementById('wcm-progress-bar');
    progressText = document.getElementById('wcm-progress-text');

    if (btnTest)    btnTest.addEventListener('click', testConnection);
    if (btnReindex) btnReindex.addEventListener('click', startReindex);
  });
})();
